<?php

declare(strict_types=1);

namespace App\Services\Deployments;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Servers\RenderNodeState;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Specs\ServiceSpec;
use App\Enums\AuditAction;
use App\Enums\DeploymentStatus;
use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Enums\WorkloadRole;
use App\Jobs\AdvanceDeploymentJob;
use App\Models\Placement;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Scheduling\PlacementFailed;
use Illuminate\Support\Facades\Log;

/**
 * Moves replicas off a dead server (docs/02 §6, research §3, §8).
 *
 * evacuate() (in the caller's transaction, server row locked):
 * - draining workloads on it are dropped (they were going away anyway);
 * - each serving replica of a stateless service gets a new placement (same
 *   deployment, same slot, new workload id) on a healthy server chosen by the
 *   scheduler with the set's other replicas as existing ones (anti-affinity
 *   and capacity), and the dead one is deleted. The dead server's NodeState
 *   is rendered without it, so when the server returns its launchroomd
 *   stops the orphan (the reconciler removes managed containers that left
 *   the desired state);
 * - stateful services are never moved (their volume lives there; no fencing,
 *   so no split-brain): the instance is marked degraded
 *   (`placement_issue.kind = stateful_server_down`), audited once, and the
 *   UI offers a restore on another server;
 * - a replica no other server can take stays where it is (it may come back)
 *   and the instance is marked `unplaceable` with the scheduler's reason;
 *   the next liveness tick retries.
 *
 * refreshIssues() clears marks that no longer hold (the server is back, or
 * every replica moved).
 */
final readonly class Rescheduler
{
    public function __construct(
        private Placer $placer,
        private RenderNodeState $renderNodeState,
        private RecordAuditEvent $audit,
    ) {}

    /**
     * @return int replicas moved
     */
    public function evacuate(Server $dead): int
    {
        $placements = Placement::query()
            ->where('server_id', $dead->id)
            ->with(['deployment.revision', 'serviceInstance.service', 'serviceInstance.environment', 'serviceInstance.volumes'])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($placements->isEmpty()) {
            return 0;
        }

        $touched = [$dead->id];
        $advance = [];
        $moved = [];
        $dropped = 0;

        foreach ($placements as $placement) {
            $instance = $placement->serviceInstance;

            if ($placement->role === WorkloadRole::Draining) {
                $placement->delete();
                $dropped++;

                continue;
            }

            if ($instance->service->kind->isStateful()) {
                $this->markStatefulDown($instance, $dead);

                continue;
            }

            $deployment = $placement->deployment;
            $spec = $deployment->revision?->spec->service($instance->service->public_id);

            if (! $spec instanceof ServiceSpec) {
                continue;
            }

            $others = array_values(Placement::query()
                ->where('deployment_id', $deployment->id)
                ->whereKeyNot($placement->id)
                ->where('role', WorkloadRole::Serving->value)
                ->whereIn('server_id', Server::query()->select('id')->where('status', ServerStatus::Online->value))
                ->get(['server_id'])
                ->map(static fn (Placement $other): int => $other->server_id)
                ->all());

            try {
                $target = $this->placer->plan($deployment, $spec, 1, $others)->serverIds[0];
            } catch (PlacementFailed $e) {
                $this->mark($instance, 'unplaceable', "Replicas on {$dead->name} could not move to another server: {$e->getMessage()}", $dead);

                continue;
            }

            $slot = $placement->slot;
            $placement->delete();
            $this->placer->createPlacement($deployment, $spec, $slot, $target);

            $touched[] = $target;
            $moved[] = $placement->workload_id;

            if (in_array($deployment->status, DeploymentStatus::inFlight(), true)) {
                $advance[$deployment->id] = true;
            }
        }

        if ($moved === [] && $dropped === 0) {
            return 0;
        }

        $edges = Server::query()
            ->where('team_id', $dead->team_id)
            ->where('status', ServerStatus::Online->value)
            ->whereJsonContains('roles', ServerRole::Edge->value)
            ->get(['id'])
            ->map(static fn (Server $server): int => $server->id)
            ->all();

        foreach (Server::query()->whereKey(array_unique([...$touched, ...$edges]))->orderBy('id')->get() as $server) {
            $this->renderNodeState->handle($server);
        }

        foreach (array_keys($advance) as $deploymentId) {
            dispatch(new AdvanceDeploymentJob($deploymentId))->afterCommit();
        }

        if ($moved !== []) {
            Log::warning('Rescheduled replicas off a dead server.', ['server' => $dead->public_id, 'workloads' => $moved]);

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::ReplicasRescheduled,
                actor: AuditActor::system(),
                teamId: $dead->team_id,
                subjectType: 'server',
                subjectId: $dead->public_id,
                meta: ['workloads' => $moved, 'lastSeenAt' => $dead->last_seen_at?->toIso8601String()],
            ));
        }

        return count($moved);
    }

    /**
     * Clears degraded/unplaceable marks that no longer hold.
     */
    public function refreshIssues(): int
    {
        $cleared = 0;

        $instances = ServiceInstance::query()
            ->whereNotNull('placement_issue')
            ->with(['volumes', 'service'])
            ->get();

        foreach ($instances as $instance) {
            $kind = $instance->placement_issue['kind'] ?? null;

            $resolved = match ($kind) {
                'stateful_server_down' => $instance->volumes->every(static fn ($volume): bool => $volume->server_id === null)
                    || Server::query()->whereKey($instance->volumes->pluck('server_id')->filter()->all())->where('status', '!=', ServerStatus::Online->value)->doesntExist(),
                default => Placement::query()
                    ->where('service_instance_id', $instance->id)
                    ->where('role', WorkloadRole::Serving->value)
                    ->whereIn('server_id', Server::query()->select('id')->where('status', '!=', ServerStatus::Online->value))
                    ->doesntExist(),
            };

            if ($resolved) {
                $instance->forceFill(['placement_issue' => null])->save();
                $cleared++;
            }
        }

        return $cleared;
    }

    private function markStatefulDown(ServiceInstance $instance, Server $dead): void
    {
        if (($instance->placement_issue['kind'] ?? null) === 'stateful_server_down') {
            return;
        }

        $this->mark($instance, 'stateful_server_down', "{$dead->name} is unreachable. {$instance->service->name} keeps its data there and is never moved automatically: bring the server back, or restore the latest backup on another server.", $dead);

        Log::warning('A stateful service is down with its server.', ['server' => $dead->public_id, 'instance' => $instance->public_id]);

        $this->audit->handle(new AuditEntryData(
            action: AuditAction::ServiceDegraded,
            actor: AuditActor::system(),
            teamId: $instance->team_id,
            subjectType: 'service_instance',
            subjectId: $instance->public_id,
            meta: ['server' => $dead->public_id, 'reason' => 'stateful_server_down'],
        ));
    }

    private function mark(ServiceInstance $instance, string $kind, string $message, Server $server): void
    {
        $current = $instance->placement_issue;

        if ($current !== null && $current['kind'] === $kind && $current['message'] === $message) {
            return;
        }

        $instance->forceFill(['placement_issue' => [
            'kind' => $kind,
            'message' => mb_substr($message, 0, 1000),
            'serverId' => $server->public_id,
            'since' => is_string($current['since'] ?? null) ? $current['since'] : now()->toIso8601String(),
        ]])->save();
    }
}
