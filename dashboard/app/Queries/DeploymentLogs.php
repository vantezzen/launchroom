<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Deployments\LogLineData;
use App\Data\Deployments\LogPageData;
use App\Enums\DaemonTaskType;
use App\Enums\DeploymentStepName;
use App\Enums\LogSourceKind;
use App\Models\DaemonTask;
use App\Models\Deployment;
use App\Models\LogChunk;
use App\Models\Placement;
use App\Models\Server;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The log lines of one deployment, from every source that belongs to it:
 * its tasks (plan, build, release command), chunks shipped for the
 * deployment itself, and its workloads (runtime logs). Lines are numbered in
 * arrival order (log chunk id), so `#L42` stays stable while more arrive.
 *
 * Paginated forwards by cursor = id of the last chunk returned.
 */
final class DeploymentLogs
{
    public const int PAGE_LINES = 5000;

    /** Timeline steps a line can belong to, plus runtime logs. */
    public const array SECTIONS = ['plan', 'build', 'release', 'rollout', 'health', 'route', 'runtime'];

    public function page(Deployment $deployment, int $after = 0, int $maxLines = self::PAGE_LINES): LogPageData
    {
        $tasks = DaemonTask::query()
            ->where('team_id', $deployment->team_id)
            ->where('deployment_id', $deployment->id)
            ->get(['id', 'type', 'server_id']);
        $taskTypes = $tasks->mapWithKeys(static fn (DaemonTask $task): array => [$task->id => $task->type])->all();

        // Only servers that ran something of this deployment may have written
        // its logs (review L1): workload lines from the server of that
        // placement, deployment lines from a server with a task or placement.
        $workloads = [];
        $servers = array_values(array_filter($tasks->pluck('server_id')->all(), is_int(...)));

        foreach (Placement::query()->where('team_id', $deployment->team_id)->where('deployment_id', $deployment->id)->get(['workload_id', 'server_id']) as $placement) {
            $workloads[] = [$placement->workload_id, $placement->server_id];
            $servers[] = $placement->server_id;
        }

        // Servers the rollout placed replicas on: a failed or cancelled
        // rollout's placements are gone, its lifecycle lines and startup
        // output stay (D68).
        $placedOn = $deployment->steps()->where('name', DeploymentStepName::Rollout->value)->value('meta');
        $placedOn = is_array($placedOn) ? $placedOn : (is_string($placedOn) ? json_decode($placedOn, true) : null);

        if (is_array($placedOn) && is_array($placedOn['servers'] ?? null)) {
            $ids = Server::query()->where('team_id', $deployment->team_id)->whereIn('public_id', array_values(array_filter($placedOn['servers'], is_string(...))))->pluck('id')->all();
            $servers = [...$servers, ...array_values(array_filter($ids, is_int(...)))];
        }

        $servers = array_values(array_unique($servers));

        $number = $after > 0
            ? (int) $this->chunks($deployment, array_keys($taskTypes), $workloads, $servers)
                ->where('id', '<=', $after)
                ->sum(DB::raw('jsonb_array_length(lines)'))
            : 0;

        $lines = [];
        $cursor = $after;

        $chunks = $this->chunks($deployment, array_keys($taskTypes), $workloads, $servers)
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit(500)
            ->get(['id', 'daemon_task_id', 'source_kind', 'lines']);

        foreach ($chunks as $chunk) {
            if ($lines !== [] && count($lines) + count($chunk->lines) > $maxLines) {
                break;
            }

            $task = $chunk->daemon_task_id === null ? null : ($taskTypes[$chunk->daemon_task_id] ?? null);

            /** @var list<array<string, mixed>> $raw */
            $raw = $chunk->lines;

            foreach ($raw as $line) {
                $step = isset($line['step']) && is_string($line['step']) && $line['step'] !== '' ? $line['step'] : null;
                $lines[] = new LogLineData(
                    number: ++$number,
                    time: is_string($line['time'] ?? null) ? $line['time'] : '',
                    stream: is_string($line['stream'] ?? null) ? $line['stream'] : 'stdout',
                    text: is_string($line['text'] ?? null) ? $line['text'] : '',
                    section: $this->section($chunk->source_kind, $task, $step),
                    step: $step,
                );
            }

            $cursor = $chunk->id;
        }

        $hasMore = $this->chunks($deployment, array_keys($taskTypes), $workloads, $servers)->where('id', '>', $cursor)->exists();

        return new LogPageData($lines, $cursor, $hasMore);
    }

    /**
     * Log chunks that belong to $deployment.
     *
     * @param  list<int>  $taskIds
     * @param  list<array{string, int}>  $workloads  [workload id, server id]
     * @param  list<int>  $servers  servers holding a task or placement of the deployment
     * @return Builder<LogChunk>
     */
    private function chunks(Deployment $deployment, array $taskIds, array $workloads, array $servers): Builder
    {
        return LogChunk::query()
            ->where('team_id', $deployment->team_id)
            ->where(static function (Builder $query) use ($deployment, $taskIds, $workloads, $servers): void {
                $query->where(static fn (Builder $q) => $q->where('deployment_id', $deployment->id)->whereIn('server_id', $servers));

                if ($servers !== []) {
                    $query->orWhere(static fn (Builder $q) => $q->where('source_kind', LogSourceKind::Deployment->value)->where('source_id', $deployment->public_id)->whereIn('server_id', $servers));
                }

                if ($taskIds !== []) {
                    $query->orWhereIn('daemon_task_id', $taskIds);
                }

                foreach ($workloads as [$workloadId, $serverId]) {
                    $query->orWhere(static fn (Builder $q) => $q->where('source_kind', LogSourceKind::Workload->value)->where('source_id', $workloadId)->where('server_id', $serverId));
                }
            });
    }

    private function section(LogSourceKind $source, ?DaemonTaskType $task, ?string $step): string
    {
        return match (true) {
            // A starting container's output belongs to the step it ran in (D68).
            $source === LogSourceKind::Workload => in_array($step, [DeploymentStepName::Rollout->value, DeploymentStepName::Health->value, DeploymentStepName::Route->value], true) ? (string) $step : 'runtime',
            $task === DaemonTaskType::Plan => DeploymentStepName::Plan->value,
            $task === DaemonTaskType::Build => DeploymentStepName::Build->value,
            $task === DaemonTaskType::RunOnce => DeploymentStepName::Release->value,
            $step !== null && in_array($step, self::SECTIONS, true) => $step,
            default => DeploymentStepName::Rollout->value,
        };
    }
}
