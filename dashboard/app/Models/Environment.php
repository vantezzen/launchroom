<?php

declare(strict_types=1);

namespace App\Models;

use App\Data\Environments\EnvironmentTriggers;
use App\Enums\EnvironmentKind;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A full copy of the project's service graph (docs/06 §1).
 *
 * @property int $id
 * @property string $public_id
 * @property int $project_id
 * @property string $name
 * @property string $slug
 * @property EnvironmentKind $kind
 * @property int|null $base_environment_id
 * @property EnvironmentTriggers $triggers
 * @property int|null $pr_number
 * @property string|null $pr_head_sha
 * @property string|null $pr_head_ref the preview's branch (branch-targeted variables)
 * @property bool $secrets_withheld fork previews get no secrets (docs/09 R20)
 * @property Carbon|null $expires_at
 * @property bool $auto_deploy_paused
 * @property int|null $last_applied_revision_id
 * @property string $network_subnet
 * @property int|null $runtime_log_retention_days runtime log retention (opt-in): null = off
 * @property int|null $runtime_log_max_mb size cap of the retained runtime logs
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read EnvironmentRevision|null $lastAppliedRevision
 */
#[Fillable(['team_id', 'project_id', 'name', 'slug', 'kind', 'base_environment_id', 'triggers', 'pr_number', 'pr_head_sha', 'pr_head_ref', 'secrets_withheld', 'expires_at', 'network_subnet'])]
final class Environment extends Model
{
    use BelongsToTeam, HasPublicId;

    /** @var array<string, mixed> */
    protected $attributes = [
        'triggers' => '{}',
        'auto_deploy_paused' => false,
        'secrets_withheld' => false,
    ];

    public static function publicIdPrefix(): string
    {
        return 'env';
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
        return $this->belongsTo(self::class, 'base_environment_id');
    }

    /**
     * @return HasMany<ServiceInstance, $this>
     */
    public function serviceInstances(): HasMany
    {
        return $this->hasMany(ServiceInstance::class);
    }

    /**
     * @return HasMany<EnvironmentRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(EnvironmentRevision::class);
    }

    /**
     * @return BelongsTo<EnvironmentRevision, $this>
     */
    public function lastAppliedRevision(): BelongsTo
    {
        return $this->belongsTo(EnvironmentRevision::class, 'last_applied_revision_id');
    }

    /**
     * @return HasMany<Deployment, $this>
     */
    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    /**
     * @return HasOne<CanvasLayout, $this>
     */
    public function canvasLayout(): HasOne
    {
        return $this->hasOne(CanvasLayout::class);
    }

    /**
     * Whether runtime logs of this environment are kept (docs/08 §12): its
     * workloads ship their logs continuously and PruneRuntimeLogs keeps them
     * for the period, up to the size cap.
     */
    public function retainsRuntimeLogs(): bool
    {
        return $this->runtime_log_retention_days !== null && $this->runtime_log_retention_days > 0;
    }

    /**
     * Docker network id in the NodeState: `net_` + the environment's ULID.
     */
    public function networkId(): string
    {
        return 'net_'.substr($this->public_id, strlen('env_'));
    }

    /**
     * Host-side network name, generated from the id (docs/09 R3).
     */
    public function networkName(): string
    {
        return 'lr-e_'.substr(hash('sha256', $this->public_id), 0, 16);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => EnvironmentKind::class,
            'triggers' => EnvironmentTriggers::class,
            'pr_number' => 'integer',
            'expires_at' => 'datetime',
            'auto_deploy_paused' => 'boolean',
            'secrets_withheld' => 'boolean',
            'runtime_log_retention_days' => 'integer',
            'runtime_log_max_mb' => 'integer',
        ];
    }
}
