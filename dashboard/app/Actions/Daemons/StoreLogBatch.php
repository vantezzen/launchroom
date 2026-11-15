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
use App\Services\Deployments\RetiredWorkloads;
use App\Support\Deployments\LogSections;
use App\Support\Logs\LogChunkRow;
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
    /** launchroomd's step of a starting container's first output (logship.StartupStep, D68). */
    private const string STARTUP_STEP = 'startup';

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

            // A starting container's first output (D68, step `startup`) is
            // part of its deployment's log: it goes to the step the
            // deployment is in and is kept like build output (not runtime).
            $startup = $chunk->source->kind === LogSourceKind::Workload && $deploymentId !== null
                && array_all($chunk->lines, static fn (LogLine $line): bool => $line->step === self::STARTUP_STEP);
            $section = $startup ? $this->section($deploymentId) : null;

            $lines = array_map(function (LogLine $line) use ($deploymentId, $section): array {
                /** @var array<string, mixed> $data */
                $data = $line->toArray();
                $data['text'] = $deploymentId !== null ? $this->masker->mask($deploymentId, $line->text) : $line->text;

                if ($section !== null) {
                    $data['step'] = $section;
                }

                return $data;
            }, $chunk->lines);

            $rows[] = LogChunkRow::build(
                $server->team_id,
                $server->id,
                $chunk->source->kind,
                $chunk->source->id,
                $chunk->seq,
                $lines,
                $tail || ($chunk->source->kind === LogSourceKind::Workload && $section === null),
                $deploymentId === null ? null : ($scopes[$deploymentId] ??= $this->scope($deploymentId)),
                $taskId,
            );
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
     */
    private function scope(int $deploymentId): LogChunkRow
    {
        $row = DB::table('deployments')
            ->join('service_instances', 'service_instances.id', '=', 'deployments.service_instance_id')
            ->join('services', 'services.id', '=', 'service_instances.service_id')
            ->where('deployments.id', $deploymentId)
            ->first(['services.project_id', 'deployments.environment_id', 'services.id as service_id']);

        $id = static fn (mixed $value): int => is_numeric($value) ? (int) $value : 0;

        return new LogChunkRow($deploymentId, $id($row?->project_id), $id($row?->environment_id), $id($row?->service_id));
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

        // A failed rollout's container prints its last lines after its
        // placement was removed (D68).
        return is_int($deploymentId) ? $deploymentId : RetiredWorkloads::deploymentOf($server->id, $workloadId);
    }

    /**
     * The deployment step startup output belongs to (LogSections).
     */
    private function section(int $deploymentId): string
    {
        $deployment = Deployment::query()->whereKey($deploymentId)->first();

        return $deployment instanceof Deployment ? LogSections::current($deployment) : 'runtime';
    }
}
