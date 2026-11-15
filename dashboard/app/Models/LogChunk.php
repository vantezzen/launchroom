<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LogSourceKind;
use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A batch of log lines from a daemon (docs/07). Append-only.
 *
 * Search columns (docs/08 §12): the project, environment and service of the
 * chunk's deployment, `runtime` (workload chunks and live tails), and line,
 * error and byte counts. Rows are built by App\Support\Logs\LogChunkRow.
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
