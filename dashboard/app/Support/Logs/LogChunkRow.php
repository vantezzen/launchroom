<?php

declare(strict_types=1);

namespace App\Support\Logs;

use App\Enums\LogSourceKind;
use App\Models\Deployment;
use Carbon\CarbonInterface;

/**
 * One `log_chunks` row (docs/07, search columns docs/08 §12): each line's
 * level (kept when the writer set one, else guessed), the line, error and
 * byte counts, and the search scope (project, environment and service) of
 * the chunk's deployment. Every writer of log chunks builds rows here.
 */
final readonly class LogChunkRow
{
    public function __construct(
        public int $deploymentId,
        public int $projectId,
        public int $environmentId,
        public int $serviceId,
    ) {}

    /** The search scope of a deployment (loads its environment and service instance when missing). */
    public static function scopeOf(Deployment $deployment): self
    {
        $deployment->loadMissing(['environment', 'serviceInstance']);

        return new self($deployment->id, $deployment->environment->project_id, $deployment->environment_id, $deployment->serviceInstance->service_id);
    }

    /**
     * @param  list<array<string, mixed>>  $lines  time, stream, text, optional step and level
     * @return array<string, mixed>
     */
    public static function build(int $teamId, int $serverId, LogSourceKind $kind, string $sourceId, int $seq, array $lines, bool $runtime, ?self $scope = null, ?int $taskId = null, ?CarbonInterface $createdAt = null): array
    {
        $lines = array_map(static fn (array $line): array => [...$line, 'level' => LogLevel::of($line)], $lines);
        $json = json_encode($lines, JSON_THROW_ON_ERROR);

        return [
            'team_id' => $teamId,
            'server_id' => $serverId,
            'daemon_task_id' => $taskId,
            'deployment_id' => $scope?->deploymentId,
            'source_kind' => $kind->value,
            'source_id' => $sourceId,
            'seq' => $seq,
            'lines' => $json,
            'project_id' => $scope?->projectId,
            'environment_id' => $scope?->environmentId,
            'service_id' => $scope?->serviceId,
            'runtime' => $runtime,
            'line_count' => count($lines),
            'error_count' => count(array_filter($lines, static fn (array $line): bool => $line['level'] === 'error')),
            'bytes' => strlen($json),
            'created_at' => $createdAt ?? now(),
        ];
    }
}
