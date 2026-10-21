<?php

declare(strict_types=1);

namespace App\Services\Moves;

use App\Actions\Servers\RenderNodeState;
use App\Actions\Servers\RevokeServer;
use App\Data\Audit\AuditActor;
use App\Enums\ServerRole;
use App\Enums\WorkloadRole;
use App\Events\NodeStateInvalidated;
use App\Exceptions\DomainRuleViolation;
use App\Models\Placement;
use App\Models\Server;
use App\Models\Volume;
use App\Queries\MeshServers;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * Retires a server everything moved away from (docs/14 §13.2, §13.4): the
 * one it moved to takes over its roles and labels (its own labels win;
 * allow-lists that name the old server stand for it from now on), and the
 * old one gets an empty "decommission" desired state (no workloads, no
 * routes, no system containers; its Docker volumes are never deleted) and
 * is revoked, the way "Replace server" retires a dead one. Its launchroomd
 * fetches that state and stops what it ran; afterwards its key is refused.
 *
 * Call inside a transaction.
 */
final readonly class ServerRetirement
{
    public function __construct(
        private RenderNodeState $render,
        private RevokeServer $revoke,
    ) {}

    /**
     * Hands $old's roles and labels to $new, gives $old its empty desired
     * state and revokes it. Refused while $old still runs a service.
     */
    public function retire(Server $old, Server $new, AuditActor $actor): void
    {
        $old = Server::query()->whereKey($old->id)->lockForUpdate()->firstOrFail();
        $new = Server::query()->whereKey($new->id)->lockForUpdate()->firstOrFail();

        $current = static fn (Builder $query): Builder => $query->whereNull('removed_at');
        $serving = Placement::query()->where('server_id', $old->id)->where('role', WorkloadRole::Serving->value)->whereHas('serviceInstance', $current)->with('serviceInstance.service')->first();
        $volume = Volume::query()->where('server_id', $old->id)->whereHas('serviceInstance', $current)->with('serviceInstance.service')->first();

        if ($serving instanceof Placement || $volume instanceof Volume) {
            $name = $serving?->serviceInstance->service->name ?? $volume?->serviceInstance->service->name ?? 'A service';

            throw DomainRuleViolation::conflict("{$name} still runs on {$old->name}. Move it first.");
        }

        $roles = array_values(array_unique([
            ...array_map(static fn (ServerRole $role): string => $role->value, $new->roles->all()),
            ...array_map(static fn (ServerRole $role): string => $role->value, $old->roles->all()),
        ]));

        $new->forceFill([
            'roles' => $roles,
            'labels' => [...$old->labels, ...$new->labels],
        ])->save();

        /** @var list<int> $peers */
        $peers = Placement::query()->where('team_id', $old->team_id)->where('server_id', '!=', $old->id)->distinct()->pluck('server_id')->all();
        // Draining leftovers of earlier deployments.
        Placement::query()->where('server_id', $old->id)->delete();

        $old->forceFill(['roles' => [], 'labels' => [], 'is_control_plane' => false, 'replaced_by_server_id' => $new->id])->save();

        if ($this->render->handle($old)->failed()) {
            throw DomainRuleViolation::conflict("{$old->name} could not be given an empty state. Check its server page and try again.");
        }

        $this->revoke->handle($old, $actor);
        Server::query()->whereKey($old->id)->update(['replaced_by_server_id' => $new->id, 'replaced_at' => now()]);

        event(new NodeStateInvalidated(array_values(array_unique([$new->id, ...$peers, ...MeshServers::ids()]))));
    }
}
