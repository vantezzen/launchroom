<?php

declare(strict_types=1);

namespace App\Actions\Daemons;

use App\Data\Protocol\V1\LogBatch;
use App\Data\Protocol\V1\LogChunk as LogChunkData;
use App\Data\Protocol\V1\LogLine;
use App\Enums\DaemonTaskType;
use App\Enums\LogSourceKind;
use App\Events\LogChunksStored;
use App\Exceptions\DomainRuleViolation;
use App\Models\DaemonTask;
use App\Models\Deployment;
use App\Models\LogChunk;
use App\Models\Placement;
use App\Models\Server;
use App\Services\Deployments\DeploymentLogMasker;
use App\Support\Logs\LogLevel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Stores shipped log chunks (docs/03 §9). Re-sent chunks (same source and
 * seq) are ignored. A server only writes logs about what it runs (docs/09
 * §2, review L1):
 *
 * - `task`: a task this server holds, else the batch is refused (403);
 * - `deployment`: a deployment of its team with a task or placement on this
 *   server;
 * - `workload`: a placement on this server.
 *
 * Deployment and workload chunks that don't belong to this server are
 * dropped and logged (a stale workload must not block the batch). Lines that
 * belong to a deployment are masked against its secret values before they
 * are stored (DeploymentLogMasker, review M2).
 *
 * Every chunk is stored with its scope for search (project, environment and
 * service of its deployment), whether it is runtime output (workload chunks
 * and live tails), its counts and each line's guessed level (docs/08 §12).
 */
final readonly class StoreLogBatch
{
    public function __construct(
        private DeploymentLogMasker $masker,
    ) {}

    public function handle(Server $server, LogBatch $batch): void
    {
        $rows = [];
        $stored = [];
        $scopes = [];

        foreach ($batch->chunks as $chunk) {
            /** @var LogChunkData $chunk */
            [$taskId, $deploymentId, $tail] = $this->owner($server, $chunk);

            if ($taskId === null && $deploymentId === null && $chunk->source->kind !== LogSourceKind::Task) {
                Log::warning('Dropped log chunk for a source this server does not run.', [
                    'server' => $server->public_id,
                    'kind' => $chunk->source->kind->value,
                    'source' => $chunk->source->id,
                ]);

                continue;
            }

            $lines = array_map(function (LogLine $line) use ($deploymentId): array {
                $data = $line->toArray();
                $text = $deploymentId !== null ? $this->masker->mask($deploymentId, $line->text) : $line->text;
                $data['text'] = $text;
                $data['level'] = LogLevel::guess($text);

                return $data;
            }, $chunk->lines);

            $scope = $deploymentId === null ? null : ($scopes[$deploymentId] ??= $this->scope($deploymentId));
            $json = json_encode($lines, JSON_THROW_ON_ERROR);

            $rows[] = [
                'team_id' => $server->team_id,
                'server_id' => $server->id,
                'daemon_task_id' => $taskId,
                'deployment_id' => $deploymentId,
                'source_kind' => $chunk->source->kind->value,
                'source_id' => $chunk->source->id,
                'seq' => $chunk->seq,
                'lines' => $json,
                'project_id' => $scope['project_id'] ?? null,
                'environment_id' => $scope['environment_id'] ?? null,
                'service_id' => $scope['service_id'] ?? null,
                'runtime' => $tail || $chunk->source->kind === LogSourceKind::Workload,
                'line_count' => count($lines),
                'error_count' => count(array_filter($lines, static fn (array $line): bool => $line['level'] === 'error')),
                'bytes' => strlen($json),
                'created_at' => now(),
            ];
            $stored[] = $chunk;
        }

        if ($rows !== []) {
            LogChunk::query()->insertOrIgnore($rows);
        }

        if ($stored !== []) {
            event(new LogChunksStored($server->team_id, $server->id, array_map(
                static fn (LogChunkData $chunk): array => ['kind' => $chunk->source->kind, 'id' => $chunk->source->id],
                $stored,
            )));
        }
    }

    /**
     * The task and deployment a chunk belongs to on this server, and whether
     * it is a live tail; [null, null, false] when a deployment/workload
     * source isn't this server's.
     *
     * @return array{int|null, int|null, bool}
     *
     * @throws DomainRuleViolation for tasks this server doesn't hold
     */
    private function owner(Server $server, LogChunkData $chunk): array
    {
        return match ($chunk->source->kind) {
            LogSourceKind::Task => $this->taskOwner($server, $chunk->source->id),
            LogSourceKind::Deployment => [null, $this->deploymentOnServer($server, $chunk->source->id), false],
            LogSourceKind::Workload => [null, $this->workloadDeployment($server, $chunk->source->id), false],
        };
    }

    /**
     * @return array{int, int|null, bool}
     *
     * @throws DomainRuleViolation
     */
    private function taskOwner(Server $server, string $publicId): array
    {
        $task = DaemonTask::query()
            ->where('public_id', $publicId)
            ->where('server_id', $server->id)
            ->first(['id', 'type', 'deployment_id']);

        if (! $task instanceof DaemonTask) {
            throw DomainRuleViolation::forbidden('Logs can only be shipped for tasks this server holds.');
        }

        return [$task->id, $task->deployment_id, $task->type === DaemonTaskType::Logs];
    }

    /**
     * Project, environment and service of a deployment (search scope).
     *
     * @return array{project_id: int, environment_id: int, service_id: int}|null
     */
    private function scope(int $deploymentId): ?array
    {
        $row = DB::table('deployments')
            ->join('service_instances', 'service_instances.id', '=', 'deployments.service_instance_id')
            ->join('services', 'services.id', '=', 'service_instances.service_id')
            ->where('deployments.id', $deploymentId)
            ->first(['services.project_id', 'deployments.environment_id', 'services.id as service_id']);

        if ($row === null || ! is_numeric($row->project_id) || ! is_numeric($row->environment_id) || ! is_numeric($row->service_id)) {
            return null;
        }

        return ['project_id' => (int) $row->project_id, 'environment_id' => (int) $row->environment_id, 'service_id' => (int) $row->service_id];
    }

    private function deploymentOnServer(Server $server, string $publicId): ?int
    {
        $deploymentId = Deployment::query()
            ->where('team_id', $server->team_id)
            ->where('public_id', $publicId)
            ->value('id');

        if (! is_int($deploymentId)) {
            return null;
        }

        $runsHere = DaemonTask::query()->where('server_id', $server->id)->where('deployment_id', $deploymentId)->exists()
            || Placement::query()->where('server_id', $server->id)->where('deployment_id', $deploymentId)->exists();

        return $runsHere ? $deploymentId : null;
    }

    private function workloadDeployment(Server $server, string $workloadId): ?int
    {
        $deploymentId = Placement::query()
            ->where('server_id', $server->id)
            ->where('workload_id', $workloadId)
            ->value('deployment_id');

        return is_int($deploymentId) ? $deploymentId : null;
    }
}
