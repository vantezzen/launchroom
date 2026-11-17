<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Actions\Backups\RecordWalArchives;
use App\Actions\Mesh\RecordMeshStatus;
use App\Actions\Metrics\RecordMetrics;
use App\Actions\Security\RecordWafReport;
use App\Data\Deployments\ContainerExitData;
use App\Data\Deployments\ProbeProgressData;
use App\Data\Deployments\PullProgressData;
use App\Data\Protocol\V1\HardwareFacts;
use App\Data\Protocol\V1\HostStatus;
use App\Data\Protocol\V1\ImageFacts;
use App\Data\Protocol\V1\IngressStatus;
use App\Data\Protocol\V1\ProbeStatus;
use App\Data\Protocol\V1\PullProgress;
use App\Data\Protocol\V1\Report;
use App\Data\Protocol\V1\StateRejection;
use App\Data\Protocol\V1\VolumeDeletionResult;
use App\Data\Protocol\V1\WorkloadFailure;
use App\Data\Protocol\V1\WorkloadIssue;
use App\Data\Protocol\V1\WorkloadStatus;
use App\Data\Servers\ControlPlaneHealthData;
use App\Data\Servers\ControlPlaneUrlReportData;
use App\Data\Servers\DaemonExitData;
use App\Data\Servers\Host\HostStatusData;
use App\Data\Servers\HostFactsData;
use App\Data\Servers\ServerRuntimeData;
use App\Data\Servers\StateRejectionData;
use App\Enums\DaemonFeature;
use App\Enums\DeploymentStatus;
use App\Enums\PortHint;
use App\Enums\ProbeHint;
use App\Enums\WorkloadFailureCode;
use App\Enums\WorkloadPhase;
use App\Events\Broadcasts\DeploymentUpdated;
use App\Events\NodeStateInvalidated;
use App\Exceptions\DomainRuleViolation;
use App\Jobs\AdvanceDeploymentJob;
use App\Jobs\RenderServerNodeState;
use App\Models\Deployment;
use App\Models\Placement;
use App\Models\Server;
use App\Services\Deployments\DeploymentLogMasker;
use App\Services\Volumes\VolumeRetirements;
use App\Support\Network\ServerAddress;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Spatie\LaravelData\Optional;

/**
 * Applies a daemon's observed-state report (docs/03 §9). Reports are about
 * the authenticated server only; stale deltas (seq not newer) are ignored,
 * full reports always apply (the daemon restarts its sequence).
 *
 * Per workload, the observed phase, probe state and container id land on its
 * placement, unless the placement already holds a newer observation
 * (`observedAt` ordering). Deployments whose workloads changed, and
 * deployments waiting for this server to apply a NodeState, are advanced
 * (docs/04 §6: event-driven orchestration).
 *
 * Also kept for the server page and the alerts: launchroomd's last unclean
 * exit (`last_daemon_exit`), its last refusal (kept until a newer one), the
 * control-plane watchdog's view (`control_plane_health`), the image facts of
 * pulled images (a change re-renders their servers), cron runs and crashes
 * of live services (RecordWorkloadRuns).
 */
final readonly class RecordReport
{
    public function __construct(
        private VolumeRetirements $retirements,
        private RecordMeshStatus $meshStatus,
        private RecordCertificates $certificates,
        private RecordMetrics $metrics,
        private RecordLinkQuality $links,
        private RecordWalArchives $walArchives,
        private RecordWafReport $waf,
        private RecordWorkloadEvents $events,
        private RecordWorkloadRuns $runs,
        private DeploymentLogMasker $masker,
    ) {}

    public function handle(Server $server, Report $report): bool
    {
        if (! hash_equals($server->public_id, $report->serverId)) {
            throw DomainRuleViolation::forbidden('A server can only report on itself.');
        }

        $result = DB::transaction(function () use ($server, $report): ?array {
            $server = Server::query()->whereKey($server->id)->lockForUpdate()->firstOrFail();

            if (! $report->full && $report->seq <= $server->last_report_seq) {
                return null;
            }

            $previousObserved = $server->observed_version;

            $server->forceFill([
                'observed_version' => min($report->appliedVersion, $server->desired_version),
                'last_report_seq' => $report->seq,
                'last_report_at' => now(),
            ]);

            if ($report->host instanceof HostStatus) {
                // A launchroomd of another release pins other system images
                // (D54, docs/05 §4): re-render with this dashboard's images
                // once it runs, e.g. the edge Caddy after a self-update.
                if ($server->daemon_version !== $report->host->daemonVersion) {
                    event(new NodeStateInvalidated([$server->id]));
                }

                // Latest host facts and health snapshot (docs/03 §1), whitelisted.
                $host = HostStatusData::fromProtocol($report->host);
                // A refusal is delivered once: the page keeps showing the last one.
                $host->lastRefusal ??= $server->host_status?->lastRefusal;
                $server->forceFill([
                    'host_status' => $host,
                    'host_status_at' => now(),
                    'daemon_version' => $report->host->daemonVersion,
                ]);

                // launchroomd crashed, got stuck or the machine restarted (also delivered once).
                $exit = is_array($report->host->daemon) ? DaemonExitData::fromReport($report->host->daemon) : null;

                if ($exit instanceof DaemonExitData) {
                    $server->forceFill(['last_daemon_exit' => $exit]);
                }

                if ($report->host->hardware instanceof HardwareFacts) {
                    // Device detection input (docs/design/direction.md §7.3), sent when it changed.
                    $server->facts = new HostFactsData(
                        $server->facts->hostname,
                        $server->facts->os,
                        $server->facts->arch,
                        $report->host->cpus,
                        $report->host->memoryMb > 0 ? $report->host->memoryMb : $server->facts->memoryMb,
                        $report->host->hardware->toStored(),
                    );
                }
                $this->meshStatus->handle($server, is_array($report->host->mesh) ? $report->host->mesh : null);
                $this->recordFeatures($server, $report->host);
                $this->recordControlPlane($server, $report->host);
                $this->recordPublicAddresses($server, $report->host);

                // Docker mode, dockerd-guard and capabilities (D49); kept when omitted.
                if (is_array($report->host->runtime)) {
                    $server->runtime = ServerRuntimeData::fromReport($report->host->runtime, now()->toIso8601ZuluString()) ?? $server->runtime;
                }
            }

            // The control-plane watchdog's view (D90): Settings → System and `control_plane_degraded`.
            if (is_array($report->controlPlane)) {
                $server->forceFill(['control_plane_health' => ControlPlaneHealthData::merge($server->control_plane_health, $report->controlPlane, CarbonImmutable::now())]);
            }

            // The daemon refused a NodeState and runs its last good one
            // (docs/03 §5): shown on the server page until a newer one applies.
            $rejected = $report->stateRejected instanceof StateRejection ? $report->stateRejected : null;
            $server->forceFill(['state_rejection' => $rejected instanceof StateRejection ? StateRejectionData::fromProtocol($rejected) : null]);

            $server->save();

            $volumesFinished = is_array($report->volumeDeletions) && $this->retirements->recordOutcomes($server, array_map(
                static fn (VolumeDeletionResult $result): array => ['id' => $result->id, 'name' => $result->name, 'outcome' => $result->outcome, 'message' => $result->message instanceof Optional ? null : $result->message],
                $report->volumeDeletions,
            ));

            if ($volumesFinished) {
                // Deleted volumes leave the NodeState's deleteVolumes.
                dispatch(new RenderServerNodeState($server->id))->afterCommit();
            }

            [$deployments, $progressed] = $this->recordWorkloads($server, $report->workloads);

            if ($report->ingress instanceof IngressStatus) {
                $this->certificates->handle($server, $report->ingress);
            }

            if ($server->observed_version > $previousObserved) {
                // Route steps wait for edge servers to apply their NodeState;
                // health checks for providers to admit new replicas on the
                // mesh (App\Services\NodeStates\MeshReadiness).
                $routing = Deployment::query()
                    ->where('team_id', $server->team_id)
                    ->whereIn('status', [DeploymentStatus::Routing->value, DeploymentStatus::HealthChecking->value])
                    ->pluck('id')
                    ->all();
                $deployments = [...$deployments, ...$routing];
            }

            $advance = array_values(array_unique(array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $deployments)));

            return ['advance' => $advance, 'progressed' => array_values(array_diff($progressed, $advance))];
        });

        // Usage minutes are idempotent upserts: stored even when the report
        // itself was stale (they are what the server had not delivered yet).
        if (is_array($report->metrics)) {
            $this->metrics->handle($server, $report->metrics);
        }

        // Web firewall state and samples (docs/22 §6.5), idempotent like metrics.
        $this->waf->handle($server, $report);

        // Link measurements are idempotent too (one sample per measuredAt).
        if ($report->host instanceof HostStatus && is_array($report->host->links)) {
            $this->links->handle($server, array_values(array_filter($report->host->links, is_array(...))));
        }

        // WAL shipping of point-in-time recovery (docs/14 §10.2), forward-only per archive.
        if (is_array($report->walArchives)) {
            $this->walArchives->handle($server, array_values(array_filter($report->walArchives, is_array(...))));
        }

        // Container lifecycle events (D68): stored once per seq, so also from
        // a report that was otherwise stale.
        if (is_array($report->events)) {
            $this->events->handle($server, $report->events);
            // Cron runs and crashes of live services (history and alerts), idempotent too.
            $this->runs->handle($server, $report->events);
        }

        if ($result === null) {
            return false;
        }

        foreach ($result['advance'] as $deploymentId) {
            dispatch(new AdvanceDeploymentJob($deploymentId));
        }

        $this->broadcastProgress($result['progressed']);

        return true;
    }

    /**
     * Stores the optional protocol features the daemon announced (known ones
     * only, docs/03 §10). A change re-renders every mesh server: optional
     * fields (`system.linkProbe`, `shipLogs`) are only rendered for servers
     * that understand them, and peers list each other only then.
     */
    private function recordFeatures(Server $server, HostStatus $host): void
    {
        $announced = is_array($host->features) ? $host->features : [];
        $features = [];

        foreach (DaemonFeature::cases() as $feature) {
            if (in_array($feature->value, $announced, true)) {
                $features[] = $feature->value;
            }
        }

        if ($features === ($server->daemon_features ?? [])) {
            return;
        }

        $server->forceFill(['daemon_features' => $features]);
        NodeStateInvalidated::mesh([$server->id]);
    }

    /**
     * Where DNS records must point (docs/05 §5): the public addresses
     * launchroomd reports. Without a report, a stored address that can't be
     * public (Docker's bridge gateway from an older enrollment) is dropped.
     */
    private function recordPublicAddresses(Server $server, HostStatus $host): void
    {
        $reported = is_array($host->publicAddresses) ? $host->publicAddresses : null;

        if ($reported !== null) {
            $ipv4 = ServerAddress::ipv4(is_string($reported['ipv4'] ?? null) ? $reported['ipv4'] : null);
            $ipv6 = ServerAddress::ipv6(is_string($reported['ipv6'] ?? null) ? $reported['ipv6'] : null);

            if ($ipv4 !== null || $ipv6 !== null) {
                $server->forceFill(['public_ipv4' => $ipv4, 'public_ipv6' => $ipv6]);

                return;
            }
        }

        $server->forceFill([
            'public_ipv4' => ServerAddress::ipv4($server->public_ipv4),
            'public_ipv6' => ServerAddress::ipv6($server->public_ipv6),
        ]);
    }

    /**
     * Which dashboard URL launchroomd uses (Report.host.controlPlane, docs/05
     * §4.1): shown in Settings → Dashboard domain. The URL it adopted or was
     * installed with decides the forward-auth origin of its preview routes,
     * so a change re-renders the server.
     */
    private function recordControlPlane(Server $server, HostStatus $host): void
    {
        $reported = is_array($host->controlPlane) ? $host->controlPlane : null;

        if ($reported === null) {
            return;
        }

        $stored = ControlPlaneUrlReportData::fromProtocol($reported, now()->toIso8601ZuluString());
        $before = $server->daemon_control_plane;
        $server->forceFill(['daemon_control_plane' => $stored]);

        if ($before?->adopted !== $stored->adopted || $before?->configured !== $stored->configured) {
            event(new NodeStateInvalidated([$server->id]));
        }
    }

    /**
     * @param  list<WorkloadStatus>  $statuses
     * @return array{0: list<int>, 1: list<int>} deployments whose workloads changed, and those where only an image pull progressed
     */
    private function recordWorkloads(Server $server, array $statuses): array
    {
        if ($statuses === []) {
            return [[], []];
        }

        $byId = [];

        foreach ($statuses as $status) {
            $byId[$status->id] = $status;
        }

        $placements = Placement::query()
            ->where('server_id', $server->id)
            ->whereIn('workload_id', array_keys($byId))
            ->lockForUpdate()
            ->get();

        $changed = [];
        $progressed = [];
        $instances = [];

        foreach ($placements as $placement) {
            $status = $byId[$placement->workload_id];
            $observedAt = CarbonImmutable::parse($status->observedAt);

            if ($placement->observed_at !== null && $observedAt->lessThan($placement->observed_at)) {
                continue;
            }

            $this->recordImageFacts($placement, $status);

            $phase = $status->phase;
            $health = $status->health instanceof ProbeStatus ? $status->health->state : null;
            $message = is_string($status->message) ? mb_substr($status->message, 0, 4000) : null;
            $pull = $phase === WorkloadPhase::Pulling && $status->pull instanceof PullProgress ? new PullProgressData(
                stage: $status->pull->stage,
                downloaded: $status->pull->downloadedBytes,
                total: $status->pull->totalBytes,
                updatedAt: is_string($status->pull->updatedAt) ? $status->pull->updatedAt : null,
            ) : null;

            $issue = $status->issue instanceof WorkloadIssue ? $status->issue->toStored() : null;
            $probe = $status->health instanceof ProbeStatus ? new ProbeProgressData(
                attempts: is_int($status->health->attempts) ? $status->health->attempts : null,
                failures: $status->health->consecutiveFailures,
                lastError: is_string($status->health->lastError) ? mb_substr($status->health->lastError, 0, 1024) : null,
                checkedAt: is_string($status->health->lastCheckedAt) ? $status->health->lastCheckedAt : null,
                portHint: $status->portHint instanceof PortHint ? $status->portHint : null,
                listening: $status->portHint instanceof PortHint && is_array($status->detectedPorts) ? array_slice($status->detectedPorts, 0, 32) : [],
                // What the failing answer means and its first bytes (G20, G45), masked like log lines.
                hint: $status->health->hint instanceof ProbeHint ? $status->health->hint : null,
                body: is_string($status->health->body) && $status->health->body !== '' ? $this->masker->mask($placement->deployment_id, mb_substr($status->health->body, 0, 300)) : null,
            ) : null;

            if ($placement->phase !== $phase || $placement->health !== $health || $placement->message !== $message || $placement->issue?->kind !== $issue?->kind) {
                $changed[] = $placement->deployment_id;
                $instances[] = $placement->service_instance_id;
            } elseif ($placement->pull?->toArray() !== $pull?->toArray() || ($probe instanceof ProbeProgressData ? $probe->movedFrom($placement->probe) : $placement->probe instanceof ProbeProgressData)) {
                // Only the pull moved: open deployment pages refetch, nothing to advance.
                $progressed[] = $placement->deployment_id;
            }

            $exit = $this->failureAsExit($placement, $status);

            if ($exit instanceof ContainerExitData) {
                // A typed failure without an event (a pull: unauthorized, image_not_found, wrong_platform):
                // kept like an exit, so the rollout fails at once and says why (AdvanceDeployment::failFast).
                $placement->forceFill(['last_exit' => $exit]);
                $changed[] = $placement->deployment_id;
            }

            $placement->forceFill([
                'phase' => $phase,
                'health' => $health,
                'container_id' => $status->containerId instanceof Optional ? $placement->container_id : $status->containerId,
                'message' => $message,
                'pull' => $pull,
                'probe' => $probe,
                'issue' => $issue,
                'restart_count' => $status->restartCount,
                'observed_at' => $observedAt,
            ])->save();
        }

        if ($instances !== []) {
            // A stateful rollout waits for its predecessor's workload to stop.
            $waiting = Deployment::query()
                ->whereIn('service_instance_id', array_unique($instances))
                ->where('status', DeploymentStatus::RollingOut->value)
                ->pluck('id')
                ->all();
            $changed = [...$changed, ...array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $waiting)];
        }

        return [array_values($changed), array_values(array_unique(array_diff($progressed, $changed)))];
    }

    /**
     * `WorkloadStatus.failure` as the placement's last exit, unless the
     * stored one is as new or newer (an event already reported it).
     */
    private function failureAsExit(Placement $placement, WorkloadStatus $status): ?ContainerExitData
    {
        $failure = $status->failure instanceof WorkloadFailure ? $status->failure : null;

        if (! $failure instanceof WorkloadFailure || ! $failure->code instanceof WorkloadFailureCode) {
            return null;
        }

        $at = CarbonImmutable::parse($failure->at);
        $stored = $placement->last_exit;

        if ($stored instanceof ContainerExitData && CarbonImmutable::parse($stored->at)->greaterThanOrEqualTo($at)) {
            return null;
        }

        $message = is_string($failure->message) && $failure->message !== '' ? $this->masker->mask($placement->deployment_id, mb_substr($failure->message, 0, 1024)) : null;

        return new ContainerExitData(at: $failure->at, failure: $failure->code, message: $message);
    }

    /**
     * The pulled image's user and ports (Report `workloads[].image`): the
     * only source for image services and for builds without them in the
     * summary. A change re-renders the deployment's servers, so root images
     * get their capabilities and volumes their owner (NodeStateContent).
     */
    private function recordImageFacts(Placement $placement, WorkloadStatus $status): void
    {
        if (! $status->image instanceof ImageFacts) {
            return;
        }

        $deployment = Deployment::query()->whereKey($placement->deployment_id)->first();

        if (! $deployment instanceof Deployment || ! $deployment->recordImageFacts($status->image)) {
            return;
        }

        NodeStateInvalidated::servers(Placement::query()->where('deployment_id', $deployment->id)->distinct()->pluck('server_id'));
    }

    /**
     * Pull progress moved without a phase change (docs/04 §11 "Pulls"): a
     * realtime nudge for open deployment pages, which refetch the step.
     *
     * @param  list<int>  $deploymentIds
     */
    private function broadcastProgress(array $deploymentIds): void
    {
        if ($deploymentIds === []) {
            return;
        }

        $deployments = Deployment::query()
            ->whereKey($deploymentIds)
            ->with(['environment:id,public_id', 'serviceInstance:id,service_id', 'serviceInstance.service:id,public_id'])
            ->get();

        foreach ($deployments as $deployment) {
            // Only a rollout in flight has progress to show; a finished
            // deployment's nudges would only cause refetches.
            if (! $deployment->status->isInFlight()) {
                continue;
            }

            broadcast(new DeploymentUpdated(
                $deployment->public_id,
                $deployment->environment->public_id,
                $deployment->serviceInstance->service->public_id,
                $deployment->status->value,
            ));
        }
    }
}
