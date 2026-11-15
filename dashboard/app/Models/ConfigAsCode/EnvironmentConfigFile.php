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
use Spatie\LaravelData\DataCollection;

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
 * @property DataCollection<int, ConfigFileProblemData> $errors
 * @property DataCollection<int, ConfigFileProblemData> $warnings
 * @property DataCollection<int, ConfigFileProblemData> $ignored
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
        $problems = $this->{$column};

        return $problems instanceof DataCollection ? array_values(array_filter($problems->all(), static fn (mixed $problem): bool => $problem instanceof ConfigFileProblemData)) : [];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ConfigFileStatus::class,
            'managed' => ManagedConfigData::class,
            'errors' => DataCollection::class.':'.ConfigFileProblemData::class.',default',
            'warnings' => DataCollection::class.':'.ConfigFileProblemData::class.',default',
            'ignored' => DataCollection::class.':'.ConfigFileProblemData::class.',default',
            'checked_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }
}
