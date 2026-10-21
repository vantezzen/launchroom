<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Servers\UpdateServerRolesData;
use App\Enums\AuditAction;
use App\Enums\DaemonTaskStatus;
use App\Enums\DaemonTaskType;
use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Events\DaemonTaskQueued;
use App\Events\NodeStateInvalidated;
use App\Exceptions\DomainRuleViolation;
use App\Jobs\Mesh\MaintainMeshJob;
use App\Models\DaemonTask;
use App\Models\Placement;
use App\Models\Server;
use App\Models\Volume;
use App\Queries\MeshServers;
use App\Support\Servers\HostRoleLimits;
use Illuminate\Support\Facades\DB;

/**
 * Changes a server's roles after enrollment (docs/02 §2.2): edge, runtime
 * and build; `registry` is kept as it is. Refused (user-facing reasons) when
 * nothing would be left, when the machine can't take an added role (a Mac
 * never serves traffic, HostRoleLimits), when the server still hosts workloads or volumes
 * and loses `runtime`, or when it is the team's last online build server or
 * last edge and loses that role.
 *
 * Effects: every mesh server is re-rendered (its own system containers
 * follow the roles; edges, upstreams, allow-lists and the shared certificate
 * storage follow the set of edges), builds pinned to it that no one leased
 * yet go back to the pool (running ones finish), and the mesh node's tags
 * follow the roles (MaintainMesh).
 */
final readonly class UpdateServerRoles
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    /**
     * @throws DomainRuleViolation
     */
    public function handle(Server $server, UpdateServerRolesData $data, AuditActor $actor): Server
    {
        $wanted = $data->serverRoles();

        return DB::transaction(function () use ($server, $wanted, $actor): Server {
            $server = Server::query()->whereKey($server->id)->lockForUpdate()->firstOrFail();

            if ($server->status === ServerStatus::Revoked) {
                throw DomainRuleViolation::on('roles', 'A revoked server can’t be changed.');
            }

            $before = $server->roles->all();
            // Roles outside the editable set (registry) stay as they are.
            $kept = array_values(array_filter($before, static fn (ServerRole $role): bool => ! in_array($role, UpdateServerRolesData::editable(), true)));
            $after = [];

            foreach ([...UpdateServerRolesData::editable(), ServerRole::Registry] as $role) {
                if (in_array($role, $wanted, true) || in_array($role, $kept, true)) {
                    $after[] = $role;
                }
            }

            if (! array_any($after, static fn (ServerRole $role): bool => in_array($role, UpdateServerRolesData::editable(), true))) {
                throw DomainRuleViolation::on('roles', 'A server needs at least one role.');
            }

            $removed = array_values(array_filter($before, static fn (ServerRole $role): bool => ! in_array($role, $after, true)));
            $added = array_values(array_filter($after, static fn (ServerRole $role): bool => ! in_array($role, $before, true)));

            if ($removed === [] && $added === []) {
                return $server;
            }

            foreach ($added as $role) {
                // What the machine can't do (a Mac never serves traffic, D53).
                $refusal = HostRoleLimits::refusal($server, $role);

                if ($refusal !== null) {
                    throw DomainRuleViolation::on('roles', $refusal);
                }
            }

            if (in_array(ServerRole::Runtime, $removed, true)) {
                $this->assertNothingPlaced($server);
            }

            if (in_array(ServerRole::Build, $removed, true) && ! $this->othersWith($server, ServerRole::Build, onlineOnly: true)) {
                throw DomainRuleViolation::on('roles', "{$server->name} is the team’s last online build server: add the build role to another server first.");
            }

            if (in_array(ServerRole::Edge, $removed, true) && ! $this->othersWith($server, ServerRole::Edge, onlineOnly: false)) {
                throw DomainRuleViolation::on('roles', "{$server->name} is the team’s last edge: add the edge role to another server first, or nothing serves your domains.");
            }

            $server->forceFill(['roles' => $after])->save();

            if (in_array(ServerRole::Build, $removed, true)) {
                $this->releaseBuildPins($server);
            }

            event(new NodeStateInvalidated(array_values(array_unique([...MeshServers::ids(), $server->id]))));

            if ($server->mesh_node_id !== null) {
                dispatch(new MaintainMeshJob)->afterCommit();
            }

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::ServerRolesUpdated,
                actor: $actor,
                teamId: $server->team_id,
                subjectType: 'server',
                subjectId: $server->public_id,
                meta: [
                    'from' => array_values(array_map(static fn (ServerRole $role): string => $role->value, $before)),
                    'to' => array_map(static fn (ServerRole $role): string => $role->value, $after),
                ],
            ));

            return $server;
        });
    }

    private function assertNothingPlaced(Server $server): void
    {
        $workloads = Placement::query()->where('server_id', $server->id)->count();
        $volumes = Volume::query()->where('server_id', $server->id)->count();

        if ($workloads === 0 && $volumes === 0) {
            return;
        }

        $what = array_filter([
            $workloads > 0 ? ($workloads === 1 ? '1 workload' : "{$workloads} workloads") : null,
            $volumes > 0 ? ($volumes === 1 ? '1 volume' : "{$volumes} volumes") : null,
        ]);

        throw DomainRuleViolation::on('roles', "{$server->name} still hosts ".implode(' and ', $what).': move them to other servers first (placement constraints or Replace server), then remove the runtime role.');
    }

    private function othersWith(Server $server, ServerRole $role, bool $onlineOnly): bool
    {
        return Server::query()
            ->where('team_id', $server->team_id)
            ->whereKeyNot($server->id)
            ->whereNull('revoked_at')
            ->whereIn('status', $onlineOnly ? [ServerStatus::Online->value] : [ServerStatus::Online->value, ServerStatus::Unreachable->value, ServerStatus::Offline->value])
            ->whereJsonContains('roles', $role->value)
            ->exists();
    }

    /**
     * Builds pinned here for cache locality that nobody leased yet go back
     * to the pool, like ReleaseStuckBuildPins does for a server that left.
     */
    private function releaseBuildPins(Server $server): void
    {
        $tasks = DaemonTask::query()
            ->where('server_id', $server->id)
            ->where('status', DaemonTaskStatus::Pending->value)
            ->whereIn('type', [DaemonTaskType::Build->value, DaemonTaskType::Plan->value])
            ->where('required_role', ServerRole::Build->value)
            ->lockForUpdate()
            ->get();

        foreach ($tasks as $task) {
            $task->forceFill(['pinned_to_server' => false, 'server_id' => null])->save();
            event(new DaemonTaskQueued($task));
        }
    }
}
