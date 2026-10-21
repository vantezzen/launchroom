<?php

declare(strict_types=1);

namespace App\Models\ConfigAsCode;

use App\Data\ConfigAsCode\ConfigFileProblemData;
use App\Data\ConfigAsCode\ManagedConfigData;
use App\Enums\ConfigAsCode\ConfigFileStatus;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Environment;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The last read of an environment's launchroom.json (docs/18 §8): which
 * commit, the outcome, what the file manages and its problems. One row per
 * environment, overwritten on every read.
 *
 * @property int $id
 * @property int $environment_id
 * @property string $path
 * @property string|null $repository
 * @property string|null $commit_sha
 * @property ConfigFileStatus $status
 * @property ManagedConfigData $managed
 * @property list<array<string, mixed>> $errors
 * @property list<array<string, mixed>> $warnings
 * @property list<array<string, mixed>> $ignored
 * @property string|null $content_hash
 * @property Carbon $checked_at
 * @property Carbon|null $applied_at
 * @property-read Environment $environment
 */
#[Fillable(['team_id', 'environment_id', 'path', 'repository', 'commit_sha', 'status', 'managed', 'errors', 'warnings', 'ignored', 'content_hash', 'checked_at', 'applied_at'])]
final class EnvironmentConfigFile extends Model
{
    use BelongsToTeam;

    /** @var array<string, mixed> */
    protected $attributes = [
        'managed' => '{}',
        'errors' => '[]',
        'warnings' => '[]',
        'ignored' => '[]',
    ];

    /**
     * @return BelongsTo<Environment, $this>
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /**
     * @return list<ConfigFileProblemData>
     */
    public function problems(string $column = 'errors'): array
    {
        $rows = $this->{$column};
        $problems = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row)) {
                $problems[] = ConfigFileProblemData::from($row);
            }
        }

        return $problems;
    }

    /**
     * Link to the file at its commit (GitHub), at $line.
     */
    public function webUrl(?int $line = null): ?string
    {
        if ($this->repository === null || $this->commit_sha === null) {
            return null;
        }

        return "https://github.com/{$this->repository}/blob/{$this->commit_sha}/".implode('/', array_map(rawurlencode(...), explode('/', $this->path))).($line === null ? '' : "#L{$line}");
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ConfigFileStatus::class,
            'managed' => ManagedConfigData::class,
            'errors' => 'array',
            'warnings' => 'array',
            'ignored' => 'array',
            'checked_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }
}
