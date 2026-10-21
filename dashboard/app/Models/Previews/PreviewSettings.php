<?php

declare(strict_types=1);

namespace App\Models\Previews;

use App\Enums\Previews\ForkPolicy;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Environment;
use App\Models\Project;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A project's preview settings (docs/06 §5). One row per project, created
 * with defaults on first read ({@see forProject()}).
 *
 * @property int $id
 * @property int $project_id
 * @property bool $enabled
 * @property int|null $base_environment_id
 * @property string|null $target_branches comma-separated globs
 * @property string|null $required_label
 * @property int $max_concurrent
 * @property int $expiry_days
 * @property ForkPolicy $fork_policy
 * @property bool $protection
 * @property bool $allow_production_clone
 * @property string|null $bypass_nonce input of the CI bypass secret (a project-DEK MAC); never serialized
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read Environment|null $baseEnvironment
 */
#[Fillable(['team_id', 'project_id', 'enabled', 'base_environment_id', 'target_branches', 'required_label', 'max_concurrent', 'expiry_days', 'fork_policy', 'protection', 'allow_production_clone'])]
#[Hidden(['bypass_nonce'])]
#[Table(name: 'preview_settings')]
final class PreviewSettings extends Model
{
    use BelongsToTeam;

    public const int DEFAULT_MAX_CONCURRENT = 3;

    public const int DEFAULT_EXPIRY_DAYS = 7;

    /** @var array<string, mixed> */
    protected $attributes = [
        'enabled' => false,
        'max_concurrent' => self::DEFAULT_MAX_CONCURRENT,
        'expiry_days' => self::DEFAULT_EXPIRY_DAYS,
        'fork_policy' => 'off',
        'protection' => true,
        'allow_production_clone' => false,
    ];

    public static function forProject(Project $project): self
    {
        return self::query()->firstOrCreate(['project_id' => $project->id], ['team_id' => $project->team_id]);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Environment, $this>
     */
    public function baseEnvironment(): BelongsTo
    {
        return $this->belongsTo(Environment::class, 'base_environment_id');
    }

    /**
     * @return list<string>
     */
    public function targetBranchPatterns(): array
    {
        return array_values(array_filter(array_map(trim(...), explode(',', (string) $this->target_branches)), static fn (string $glob): bool => $glob !== ''));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'max_concurrent' => 'integer',
            'expiry_days' => 'integer',
            'fork_policy' => ForkPolicy::class,
            'protection' => 'boolean',
            'allow_production_clone' => 'boolean',
        ];
    }
}
