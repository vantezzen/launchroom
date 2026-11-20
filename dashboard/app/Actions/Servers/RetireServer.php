<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Data\Audit\AuditActor;
use App\Enums\ServerRole;
use App\Events\NodeStateInvalidated;
use App\Models\Placement;
use App\Models\Server;
use App\Queries\ServicesOnServer;
use App\Support\Refusal;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Retires a server whose services run elsewhere now (docs/14 §5.3, §13.2,
 * §13.4): "Replace server" after it took over a dead one's volumes, a server
 * move once everything moved, the control-plane hand-over. The other server
 * takes over its roles and labels (its own labels win; allow-lists that name
 * the old server stand for it from now on), and the old one gets an empty
 * "decommission" desired state (no workloads, no routes, no system
 * containers, the mesh client off, no credentials; its Docker volumes are
 * never deleted) while still approved, then is revoked (keys denied, pinned
 * tasks cancelled, mesh node removed) and marked replaced. Its launchroomd
 * fetches that state and stops what it ran; afterwards the only thing its key
 * still fetches is that state (AuthenticateDaemon).
 *
 * Refused (nothing changed) while a current service still runs or keeps a
 * volume on it (named, with its project and environment; leftover rows of
 * containers already removed don't count and go with the rest), or when the empty state can't be rendered: a refused render
 * would leave the old, full state as the latest one.
 */
final readonly class RetireServer
{
    public function __construct(
        private RenderNodeState $render,
        private RevokeServer $revoke,
    ) {}

    public function handle(Server $old, Server $new, AuditActor $actor): ?Refusal
    {
        DB::beginTransaction();

        try {
            $refusal = $this->retire($old, $new, $actor);
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        if ($refusal instanceof Refusal) {
            DB::rollBack();

            return $refusal;
        }

        DB::commit();

        return null;
    }

    private function retire(Server $old, Server $new, AuditActor $actor): ?Refusal
    {
        $old = Server::query()->whereKey($old->id)->lockForUpdate()->firstOrFail();
        $new = Server::query()->whereKey($new->id)->lockForUpdate()->firstOrFail();

        // Rows of workloads launchroomd already removed don't count (ServicesOnServer).
        $blockers = ServicesOnServer::blocking($old);

        if ($blockers !== []) {
            return Refusal::conflict(ServicesOnServer::refusal($blockers, 'Move, stop or remove it first.'));
        }

        $new->forceFill([
            'roles' => array_values(array_unique([
                ...array_map(static fn (ServerRole $role): string => $role->value, $new->roles->all()),
                ...array_map(static fn (ServerRole $role): string => $role->value, $old->roles->all()),
            ])),
            'labels' => [...$old->labels, ...$new->labels],
        ])->save();

        /** @var list<int> $peers */
        $peers = Placement::query()->where('team_id', $old->team_id)->where('server_id', '!=', $old->id)->distinct()->pluck('server_id')->all();
        // Draining leftovers of earlier deployments.
        Placement::query()->where('server_id', $old->id)->delete();

        $old->forceFill(['roles' => [], 'labels' => [], 'is_control_plane' => false, 'replaced_by_server_id' => $new->id])->save();

        if ($this->render->handle($old)->failed()) {
            return Refusal::conflict("{$old->name} could not be given an empty state. Check its server page and try again.");
        }

        $this->revoke->handle($old, $actor);
        Server::query()->whereKey($old->id)->update(['replaced_by_server_id' => $new->id, 'replaced_at' => now()]);

        NodeStateInvalidated::mesh([$new->id, ...$peers]);

        return null;
    }
}
