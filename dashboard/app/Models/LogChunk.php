<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DaemonTaskType;
use App\Enums\LogSourceKind;
use App\Models\Concerns\BelongsToTeam;
use App\Support\Logs\LogLevel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A batch of log lines from a daemon (docs/07). Append-only.
 *
 * Search columns (docs/08 §12): the project, environment and service of the
 * chunk's deployment, `runtime` (workload chunks and live tails), and line,
 * error and byte counts. App\Actions\Daemons\StoreLogBatch fills them at
 * ingest; chunks created through the model (seeders, tests) get them, and
 * each line's `level`, derived on creation.
 *
 * @property int $id
 * @property int $server_id
 * @property int|null $daemon_task_id
 * @property int|null $deployment_id
 * @property int|null $project_id
 * @property int|null $environment_id
 * @property int|null $service_id
 * @property LogSourceKind $source_kind
 * @property string $source_id
 * @property int $seq
 * @property bool $runtime
 * @property int $line_count
 * @property int $error_count
 * @property int $bytes
 * @property list<array{time: string, stream: string, text: string, level?: string}> $lines
 * @property Carbon $created_at
 */
#[Fillable(['team_id', 'server_id', 'daemon_task_id', 'deployment_id', 'source_kind', 'source_id', 'seq', 'lines', 'created_at', 'project_id', 'environment_id', 'service_id', 'runtime', 'line_count', 'error_count', 'bytes'])]
final class LogChunk extends Model
{
    use BelongsToTeam;

    public const ?string UPDATED_AT = null;

    protected static function booted(): void
    {
        self::creating(static function (LogChunk $chunk): void {
            $task = $chunk->daemon_task_id === null ? null : DaemonTask::query()->whereKey($chunk->daemon_task_id)->first(['id', 'type', 'deployment_id']);
            $deploymentId = $chunk->deployment_id ?? ($task instanceof DaemonTask ? $task->deployment_id : null);

            if ($chunk->project_id === null && $deploymentId !== null) {
                $scope = DB::table('deployments')
                    ->join('service_instances', 'service_instances.id', '=', 'deployments.service_instance_id')
                    ->join('services', 'services.id', '=', 'service_instances.service_id')
                    ->where('deployments.id', $deploymentId)
                    ->first(['services.project_id', 'deployments.environment_id', 'services.id as service_id']);

                if ($scope !== null) {
                    $chunk->project_id = is_numeric($scope->project_id) ? (int) $scope->project_id : null;
                    $chunk->environment_id = is_numeric($scope->environment_id) ? (int) $scope->environment_id : null;
                    $chunk->service_id = is_numeric($scope->service_id) ? (int) $scope->service_id : null;
                }
            }

            if (! $chunk->isDirty('runtime')) {
                $chunk->runtime = $chunk->source_kind === LogSourceKind::Workload
                    || ($task instanceof DaemonTask && $task->type === DaemonTaskType::Logs);
            }

            $lines = array_map(static fn (array $line): array => [...$line, 'level' => LogLevel::of($line)], $chunk->lines);
            $chunk->lines = $lines;
            $chunk->line_count = count($lines);
            $chunk->error_count = count(array_filter($lines, static fn (array $line): bool => $line['level'] === 'error'));
            $chunk->bytes = strlen((string) json_encode($lines));
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_kind' => LogSourceKind::class,
            'seq' => 'integer',
            'lines' => 'array',
            'runtime' => 'boolean',
            'line_count' => 'integer',
            'error_count' => 'integer',
            'bytes' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
