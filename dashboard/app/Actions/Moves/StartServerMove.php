<?php

declare(strict_types=1);

namespace App\Actions\Moves;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Servers\UpdateServerRoles;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Moves\MoveDnsRecordData;
use App\Data\Moves\MovePlanItemData;
use App\Data\Servers\UpdateServerRolesData;
use App\Enums\AuditAction;
use App\Enums\Moves\MoveItemKind;
use App\Enums\ServerRole;
use App\Events\NodeStateInvalidated;
use App\Exceptions\DomainRuleViolation;
use App\Models\Moves\ServerMove;
use App\Models\Moves\ServerMoveItem;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Queries\MeshServers;
use App\Services\Moves\MovePlanner;
use Illuminate\Support\Facades\DB;

/**
 * Starts a planned server move (docs/14 §13.2): everything on $source (an
 * online server) moves to $target while the source keeps running.
 *
 * In one transaction, after the plan is checked again under a lock:
 * - the target gets the edge role first when the source is the team's only
 *   edge (so domains keep working; the plan lists the DNS records to change);
 * - one item per service instance on the source, stateless first;
 * - the move is open, so the source drains: the scheduler places nothing new
 *   there except services pinned to it by their volumes.
 *
 * {@see AdvanceServerMoves} moves the items forward (right away and from the
 * moves tick). The source is retired at the end ({@see RetireMovedServer}),
 * or, for the control plane, handed over (docs/14 §13.4).
 */
final readonly class StartServerMove
{
    public function __construct(
        private MovePlanner $planner,
        private UpdateServerRoles $roles,
        private RecordAuditEvent $audit,
        private AdvanceServerMoves $advance,
    ) {}

    public function handle(Server $source, Server $target, bool $backupFirst, ?User $user, AuditActor $actor, bool $controlPlane = false): ServerMove
    {
        $move = DB::transaction(function () use ($source, $target, $backupFirst, $user, $actor, $controlPlane): ServerMove {
            // Both servers locked in id order (no deadlock with a concurrent move the other way).
            $locked = Server::query()->whereKey([$source->id, $target->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $source = $locked->get($source->id);
            $target = $locked->get($target->id);

            if (! $source instanceof Server || ! $target instanceof Server) {
                throw DomainRuleViolation::on('target', 'Pick another server of the same team.');
            }

            $plan = $this->planner->plan($source, $target, $controlPlane);

            if ($plan->blockers !== []) {
                throw DomainRuleViolation::on('target', $plan->blockers[0]);
            }

            if ($plan->addsEdge) {
                $roles = array_values(array_unique([...array_map(static fn (ServerRole $role): string => $role->value, $target->roles->all()), ServerRole::Edge->value]));
                $this->roles->handle($target, new UpdateServerRolesData(array_values(array_filter($roles, static fn (string $role): bool => in_array($role, ['edge', 'runtime', 'build'], true)))), $actor);
            }

            $move = ServerMove::query()->create([
                'team_id' => $source->team_id,
                'source_server_id' => $source->id,
                'target_server_id' => $target->id,
                'control_plane' => $controlPlane,
                'options' => ['backupFirst' => $backupFirst && $plan->canBackUpFirst],
                'plan' => [
                    'platformFrom' => $plan->platformFrom,
                    'platformTo' => $plan->platformTo,
                    'downtimeSeconds' => $plan->downtimeSeconds,
                    'copyBytes' => $plan->copyBytes,
                    'warnings' => $plan->warnings,
                    'dns' => array_map(static fn (MoveDnsRecordData $record): array => ['kind' => $record->kind, 'host' => $record->host, 'addresses' => $record->addresses, 'remove' => $record->remove], $plan->dns),
                ],
                'edge_added' => $plan->addsEdge,
                'created_by' => $user?->id,
            ]);

            $instances = ServiceInstance::query()
                ->whereIn('public_id', array_map(static fn (MovePlanItemData $item): string => $item->instanceId, $plan->items))
                ->where('team_id', $source->team_id)
                ->pluck('id', 'public_id');

            foreach ($plan->items as $position => $item) {
                $id = $instances->get($item->instanceId);

                if (! is_int($id)) {
                    continue;
                }

                ServerMoveItem::query()->create([
                    'team_id' => $source->team_id,
                    'server_move_id' => $move->id,
                    'service_instance_id' => $id,
                    'kind' => $item->kind,
                    'position' => $position,
                    'expected_bytes' => $item->kind === MoveItemKind::Stateless ? null : $item->expectedBytes,
                ]);
            }

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::ServerMoveStarted,
                actor: $actor,
                teamId: $source->team_id,
                subjectType: 'server',
                subjectId: $source->public_id,
                meta: array_filter([
                    'move' => $move->public_id,
                    'target' => $target->public_id,
                    'services' => count($plan->items),
                    'stateful' => count(array_filter($plan->items, static fn (MovePlanItemData $item): bool => $item->kind->isStateful())),
                    'backupFirst' => $move->backupFirst(),
                    'edgeAdded' => $plan->addsEdge,
                    'platform' => $plan->platformTo === null ? null : "{$plan->platformFrom} → {$plan->platformTo}",
                    'controlPlane' => $controlPlane,
                ], static fn (mixed $value): bool => $value !== null && $value !== false),
            ));

            // The source drains; edges and allow-lists follow.
            event(new NodeStateInvalidated(array_values(array_unique([$source->id, $target->id, ...MeshServers::ids()]))));

            return $move;
        });

        $this->advance->handle($move->id);

        return $move->refresh();
    }
}
