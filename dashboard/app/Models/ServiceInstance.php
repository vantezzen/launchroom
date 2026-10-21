<?php

declare(strict_types=1);

namespace App\Models;

use App\Data\Services\BuildSettings;
use App\Data\Services\DeploySettings;
use App\Data\Services\ScalingSettings;
use App\Enums\PreviewStrategy;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A service in an environment, holding its settings (docs/06 §1).
 *
 * @property int $id
 * @property string $public_id
 * @property int $environment_id
 * @property int $service_id
 * @property bool $enabled
 * @property string|null $branch_override
 * @property BuildSettings $build
 * @property DeploySettings $deploy
 * @property ScalingSettings $scaling
 * @property PreviewStrategy|null $preview_strategy
 * @property int|null $preview_clone_source_id "clone from another database" source (docs/06 §5)
 * @property list<string>|null $preview_sanitize_command argv run in the database image after a clone
 * @property int|null $active_deployment_id
 * @property array<string, mixed>|null $plan latest plan preview (App\Data\Services\ServicePlanData)
 * @property Carbon|null $removed_at
 * @property array{kind: string, message: string, serverId?: string|null, since: string}|null $placement_issue why it isn't fully placed now (degraded banner; App\Services\Deployments\Rescheduler)
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Environment $environment
 * @property-read Service $service
 * @property-read Deployment|null $activeDeployment
 */
#[Fillable(['team_id', 'environment_id', 'service_id', 'enabled', 'branch_override', 'build', 'deploy', 'scaling', 'preview_strategy'])]
final class ServiceInstance extends Model
{
    use BelongsToTeam, HasPublicId;

    /** @var array<string, mixed> */
    protected $attributes = [
        'enabled' => true,
        'build' => '{}',
        'deploy' => '{}',
        'scaling' => '{}',
    ];

    public static function publicIdPrefix(): string
    {
        return 'sin';
    }

    /**
     * Instances that are part of the current intent (not staged for removal).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function current(Builder $query): void
    {
        $query->whereNull('removed_at');
    }

    /**
     * @return BelongsTo<Environment, $this>
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<Deployment, $this>
     */
    public function activeDeployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class, 'active_deployment_id');
    }

    /**
     * @return BelongsTo<ServiceInstance, $this>
     */
    public function previewCloneSource(): BelongsTo
    {
        return $this->belongsTo(self::class, 'preview_clone_source_id');
    }

    /**
     * @return HasMany<Deployment, $this>
     */
    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    /**
     * @return HasMany<Domain, $this>
     */
    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    /**
     * @return HasMany<Volume, $this>
     */
    public function volumes(): HasMany
    {
        return $this->hasMany(Volume::class);
    }

    /**
     * @return HasMany<Placement, $this>
     */
    public function placements(): HasMany
    {
        return $this->hasMany(Placement::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'build' => BuildSettings::class,
            'deploy' => DeploySettings::class,
            'scaling' => ScalingSettings::class,
            'preview_strategy' => PreviewStrategy::class,
            'preview_sanitize_command' => 'array',
            'removed_at' => 'datetime',
            'plan' => 'array',
            'placement_issue' => 'array',
        ];
    }
}
