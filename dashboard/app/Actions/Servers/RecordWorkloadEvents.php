<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Data\Protocol\V1\LogLine;
use App\Data\Protocol\V1\WorkloadEvent;
use App\Data\Specs\ServiceSpec;
use App\Enums\DeploymentStatus;
use App\Enums\LogSourceKind;
use App\Events\LogChunksStored;
use App\Models\DaemonTask;
use App\Models\Deployment;
use App\Models\Placement;
use App\Models\Server;
use App\Services\Backups\DataHolds;
use App\Services\Deployments\DeploymentLogMasker;
use App\Services\Deployments\RetiredWorkloads;
use App\Support\Deployments\EffectiveDeploy;
use App\Support\Deployments\LifecycleLines;
use App\Support\Deployments\LogSections;
use App\Support\Logs\LogLevel;
use Illuminate\Support\Facades\DB;

/**
 * Stores launchroomd's container lifecycle events (Report.events, D68,
 * docs/15 §6) as management lines of their deployment's log: one chunk per
 * event (`source_kind` deployment, `seq` = the event's seq, so a re-sent
 * event is stored once), in the step the deployment was in (rollout, health,
 * route; `runtime` once it is live), `stream` system. They show on the
 * deployment's Steps and in the service's Logs tab.
 *
 * A server only writes about what it runs: the workload's placement on this
 * server, else one launchroom just retired (RetiredWorkloads), else the
 * event's deployment with a task or placement here. An exit's last lines go
 * to the placement (`last_exit`, failure messages) and, for a live
 * deployment, into the log after the exit line; while a deployment rolls out,
 * the container's output is in the log already (startup output).
 */
final readonly class RecordWorkloadEvents
{
    public function __construct(
        private DeploymentLogMasker $masker,
    ) {}

    /**
     * @param  list<WorkloadEvent>  $events
     */
    public function handle(Server $server, array $events): void
    {
        if ($events === []) {
            return;
        }

        $placements = Placement::query()
            ->where('server_id', $server->id)
            ->whereIn('workload_id', array_values(array_unique(array_map(static fn (WorkloadEvent $event): string => $event->workload, $events))))
            ->get()
            ->keyBy('workload_id');

        /** @var array<int, Deployment|null> $deployments */
        $deployments = [];
        $rows = [];
        $sources = [];

        foreach ($events as $event) {
            $placement = $placements->get($event->workload);
            $deploymentId = $placement instanceof Placement ? $placement->deployment_id : (RetiredWorkloads::deploymentOf($server->id, $event->workload) ?? $this->claimed($server, $event));

            if ($deploymentId === null) {
                continue;
            }

            $deployment = $deployments[$deploymentId] ??= Deployment::query()
                ->where('team_id', $server->team_id)
                ->whereKey($deploymentId)
                ->with(['revision', 'serviceInstance.service', 'environment'])
                ->first();

            if (! $deployment instanceof Deployment) {
                continue;
            }

            $section = LogSections::current($deployment);
            $line = $this->context($server, $deployment, $placement)->line($event);
            $lines = [['time' => $event->at, 'stream' => 'system', 'text' => $line['text'], 'step' => $section, 'level' => $line['level']]];

            if ($event->kind === 'exited') {
                $output = array_map(fn (LogLine $out): array => [
                    'time' => $out->time,
                    'stream' => $out->stream,
                    'text' => $this->masker->mask($deployment->id, $out->text),
                ], $event->lines());

                if ($placement instanceof Placement) {
                    $placement->forceFill(['last_exit' => [
                        'at' => $event->at,
                        'code' => is_int($event->exitCode) ? $event->exitCode : null,
                        'oomKilled' => $event->oomKilled === true,
                        'output' => $output,
                    ]])->save();
                }

                if ($section === 'runtime') {
                    foreach ($output as $out) {
                        $lines[] = [...$out, 'step' => $section, 'level' => LogLevel::guess($out['text'])];
                    }
                }
            }

            $json = json_encode($lines, JSON_THROW_ON_ERROR);
            $rows[] = [
                'team_id' => $server->team_id,
                'server_id' => $server->id,
                'daemon_task_id' => null,
                'deployment_id' => $deployment->id,
                'source_kind' => LogSourceKind::Deployment->value,
                'source_id' => $deployment->public_id,
                'seq' => $event->seq,
                'lines' => $json,
                'project_id' => $deployment->environment->project_id,
                'environment_id' => $deployment->environment_id,
                'service_id' => $deployment->serviceInstance->service_id,
                'runtime' => false,
                'line_count' => count($lines),
                'error_count' => count(array_filter($lines, static fn (array $l): bool => $l['level'] === 'error')),
                'bytes' => strlen($json),
                'created_at' => now(),
            ];
            $sources[$deployment->public_id] = ['kind' => LogSourceKind::Deployment, 'id' => $deployment->public_id];
        }

        if ($rows === []) {
            return;
        }

        DB::table('log_chunks')->insertOrIgnore($rows);
        event(new LogChunksStored($server->team_id, $server->id, array_values($sources)));
    }

    /**
     * The event's own deployment, if it ran here (a task or a placement).
     */
    private function claimed(Server $server, WorkloadEvent $event): ?int
    {
        if (! is_string($event->deployment)) {
            return null;
        }

        $id = Deployment::query()->where('team_id', $server->team_id)->where('public_id', $event->deployment)->value('id');

        if (! is_int($id)) {
            return null;
        }

        $ranHere = Placement::query()->where('server_id', $server->id)->where('deployment_id', $id)->exists()
            || DaemonTask::query()->where('server_id', $server->id)->where('deployment_id', $id)->exists();

        return $ranHere ? $id : null;
    }

    private function context(Server $server, Deployment $deployment, ?Placement $placement): LifecycleLines
    {
        $instance = $deployment->serviceInstance;
        $spec = $deployment->revision?->spec->service($instance->service->public_id);
        $effective = $spec instanceof ServiceSpec ? EffectiveDeploy::for($spec, $deployment) : null;

        $replacedBy = null;

        if ($instance->active_deployment_id !== null && $instance->active_deployment_id !== $deployment->id && $deployment->status === DeploymentStatus::Superseded) {
            $replacedBy = Deployment::query()->whereKey($instance->active_deployment_id)->value('public_id');
        }

        $hold = DataHolds::for($instance->id);
        $removedBecause = match (true) {
            $instance->stopped_at !== null => 'the service was stopped',
            $hold !== null => $hold['reason'],
            $deployment->status === DeploymentStatus::Failed => "the deployment didn't go live",
            $deployment->status === DeploymentStatus::Cancelled => 'the deployment was cancelled',
            default => null,
        };

        return new LifecycleLines(
            server: $server->name,
            replica: $placement instanceof Placement ? $placement->slot + 1 : null,
            port: $effective?->port,
            healthcheck: $effective?->healthcheck,
            replacedBy: is_string($replacedBy) ? $replacedBy : null,
            removedBecause: $removedBecause,
        );
    }
}
