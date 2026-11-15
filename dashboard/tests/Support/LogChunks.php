<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\DaemonTaskType;
use App\Enums\LogSourceKind;
use App\Models\DaemonTask;
use App\Models\Deployment;
use App\Models\LogChunk;
use App\Support\Logs\LogChunkRow;
use Carbon\CarbonInterface;

/**
 * Stores a log chunk the way launchroomd's shipping does (StoreLogBatch):
 * a task chunk belongs to its task's deployment, workload chunks and live
 * tails are runtime output.
 */
final class LogChunks
{
    /**
     * @param  array{team_id: int, server_id: int, source_kind: LogSourceKind, source_id: string, seq: int, lines: list<array<string, mixed>>, daemon_task_id?: int|null, deployment_id?: int|null, created_at?: CarbonInterface|null, runtime?: bool}  $chunk
     */
    public static function create(array $chunk): LogChunk
    {
        $task = isset($chunk['daemon_task_id']) ? DaemonTask::query()->findOrFail($chunk['daemon_task_id']) : null;
        $deploymentId = $chunk['deployment_id'] ?? $task?->deployment_id;
        $deployment = $deploymentId === null ? null : Deployment::query()->with(['environment', 'serviceInstance'])->findOrFail($deploymentId);

        $id = LogChunk::query()->insertGetId(LogChunkRow::build(
            $chunk['team_id'],
            $chunk['server_id'],
            $chunk['source_kind'],
            $chunk['source_id'],
            $chunk['seq'],
            $chunk['lines'],
            $chunk['runtime'] ?? ($chunk['source_kind'] === LogSourceKind::Workload || $task?->type === DaemonTaskType::Logs),
            $deployment instanceof Deployment ? LogChunkRow::scopeOf($deployment) : null,
            $task?->id,
            $chunk['created_at'] ?? null,
        ));

        return LogChunk::query()->findOrFail($id);
    }
}
