<?php

declare(strict_types=1);

namespace App\Models;

use App\Data\AppUpdates\UpdateWindowData;
use App\Data\Canvas\PlacementIssueData;
use App\Data\Services\BuildSettings;
use App\Data\Services\DeploySettings;
use App\Data\Services\ScalingSettings;
use App\Data\Services\ServicePlanData;
use App\Data\Specs\ServiceSpec;
use App\Enums\AppUpdates\AutoUpdateMode;
use App\Enums\PreviewStrategy;
use App\Enums\Security\WafMode;
use App\Models\AppUpdates\AppUpdateCheck;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
 * @property ServicePlanData|null $plan latest plan preview
 * @property Carbon|null $removed_at
 * @property PlacementIssueData|null $placement_issue
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Environment $environment
 * @property-read Service $service
 * @property WafMode $waf_mode web firewall (docs/22 §6.2): blocks by default
 * @property int $waf_paranoia CRS paranoia level 1 or 2
 * @property int $waf_inspect_body_kib request body inspected by the firewall: 32, 128 or 1024 KiB
 * @property Carbon|null $waf_mode_changed_at
 * @property Carbon|null $stopped_at stopped in launchroom (D68): keeps its config, domains, volumes and deployments, runs nothing
 * @property int|null $stopped_by_id
 * @property Carbon|null $last_auto_rollback_at launchroom rolled it back by itself (at most once an hour, CheckProbation)
 * @property AutoUpdateMode $auto_update which updates launchroom installs by itself (docs/04 §14.3)
 * @property UpdateWindowData|null $auto_update_window when it may
 * @property-read Deployment|null $activeDeployment
 */
#[Fillable(['team_id', 'environment_id', 'service_id', 'enabled', 'branch_override', 'build', 'deploy', 'scaling', 'preview_strategy', 'waf_mode', 'waf_paranoia', 'waf_inspect_body_kib'])]
final class ServiceInstance extends Model
{
    use BelongsToTeam, HasPublicId;

    /** @var array<string, mixed> */
    protected $attributes = [
        'enabled' => true,
        'build' => '{}',
        'deploy' => '{}',
        'scaling' => '{}',
        'waf_mode' => 'block',
        'waf_paranoia' => 1,
        'waf_inspect_body_kib' => 32,
        'auto_update' => 'off',
    ];

    public static function publicIdPrefix(): string
    {
        return 'sin';
    }

    /**
     * The settings a copy of this instance in another environment takes
     * over (base environments, previews, promotions, bringing back a staged
     * removal; docs/06 §6.2, the firewall per docs/22 §6.2). Volumes,
     * domains and generated values are not settings: copies get fresh ones.
     *
     * @return array<string, mixed>
     */
    public function copyableSettings(): array
    {
        return [
            'enabled' => $this->enabled,
            'branch_override' => $this->branch_override,
            'build' => $this->build,
            'deploy' => $this->deploy,
            'scaling' => $this->scaling,
            'preview_strategy' => $this->preview_strategy,
            'waf_mode' => $this->waf_mode,
            'waf_paranoia' => $this->waf_paranoia,
            'waf_inspect_body_kib' => $this->waf_inspect_body_kib,
        ];
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
     * @return HasOne<AppUpdateCheck, $this>
     */
    public function updateCheck(): HasOne
    {
        return $this->hasOne(AppUpdateCheck::class);
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
     * The volumes the service has now (staged removals left out).
     *
     * @return HasMany<Volume, $this>
     */
    public function volumes(): HasMany
    {
        return $this->hasMany(Volume::class)->whereNull('removed_at');
    }

    /**
     * Every volume row, including staged removals that a running deployment
     * may still mount (NodeStates, retirement).
     *
     * @return HasMany<Volume, $this>
     */
    public function allVolumes(): HasMany
    {
        return $this->hasMany(Volume::class);
    }

    /**
     * Volumes of other services this service mounts too (shared volumes, D73).
     *
     * @return HasMany<VolumeMount, $this>
     */
    public function volumeMounts(): HasMany
    {
        return $this->hasMany(VolumeMount::class);
    }

    /**
     * The volumes it mounts: its own (staged removals left out) and shared
     * ones whose volume isn't removed (D73).
     *
     * @return Collection<int, Volume>
     */
    public function mountedVolumes(): Collection
    {
        $this->loadMissing(['volumes', 'volumeMounts.volume']);
        $shared = $this->volumeMounts
            ->map(static fn (VolumeMount $mount): Volume => $mount->volume)
            ->filter(fn (Volume $volume): bool => $volume->removed_at === null && $volume->service_instance_id !== $this->id);

        return new Collection([...$this->volumes->all(), ...$shared->values()->all()]);
    }

    /**
     * Keeps data on one server: a database, or any service that mounts a
     * volume (own or shared). One replica, never moved automatically
     * (docs/02 §6, docs/14 §2).
     */
    public function isStateful(): bool
    {
        $this->loadMissing('service');

        return $this->service->kind->isStateful() || $this->mountedVolumes()->isNotEmpty();
    }

    /**
     * Its old version stops before the new one starts (docs/04 §8): two
     * copies never run on the same data. Databases always; any other
     * service that mounts a volume (own or shared, as $spec, the
     * deployment's revision, recorded it or as it mounts now) unless its
     * deploy settings say the app handles two copies on its data.
     */
    public function stopsBeforeStart(ServiceSpec $spec): bool
    {
        if ($spec->kind->isStateful()) {
            return true;
        }

        return ! $spec->deploy->overlapOnVolumes && ($spec->volumes !== [] || $this->mountedVolumes()->isNotEmpty());
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
            'plan' => ServicePlanData::class,
            'placement_issue' => PlacementIssueData::class,
            'waf_mode' => WafMode::class,
            'waf_paranoia' => 'integer',
            'waf_inspect_body_kib' => 'integer',
            'waf_mode_changed_at' => 'datetime',
            'stopped_at' => 'datetime',
            'last_auto_rollback_at' => 'datetime',
            'auto_update' => AutoUpdateMode::class,
            'auto_update_window' => UpdateWindowData::class,
        ];
    }
}
