<?php

declare(strict_types=1);

namespace App\Models;

use App\Data\Environments\EnvironmentTriggers;
use App\Enums\EnvironmentKind;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasPublicId;
use App\Support\Protocol\Grammar;
use App\Support\PublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

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
 * @property int|null $runtime_log_retention_days runtime log retention: null = off (production starts with 1 day, OD4)
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

    /** Runtime log retention a new production environment starts with (D41 amendment, OD4). */
    public const int PRODUCTION_LOG_DAYS = 1;

    public const int PRODUCTION_LOG_MAX_MB = 256;

    public static function publicIdPrefix(): string
    {
        return 'env';
    }

    protected static function booted(): void
    {
        // Production keeps its runtime logs by default, so the output of a
        // crashed and replaced container is still there (OD4).
        self::creating(static function (Environment $environment): void {
            if ($environment->kind === EnvironmentKind::Production && ! $environment->isDirty('runtime_log_retention_days')) {
                $environment->runtime_log_retention_days = self::PRODUCTION_LOG_DAYS;
                $environment->runtime_log_max_mb = self::PRODUCTION_LOG_MAX_MB;
            }
        });
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

    public const string NETWORK_ID_PREFIX = 'net';

    /**
     * Docker network id in the NodeState: `net_` + the environment's ULID.
     */
    public function networkId(): string
    {
        return self::NETWORK_ID_PREFIX.PublicId::SEPARATOR.Str::after($this->public_id, PublicId::SEPARATOR);
    }

    /**
     * The inverse of {@see networkId()}: the environment public id a NodeState
     * network id names, or null when it is not an environment network.
     */
    public static function publicIdFromNetworkId(string $networkId): ?string
    {
        return PublicId::isValid($networkId, self::NETWORK_ID_PREFIX)
            ? self::publicIdPrefix().PublicId::SEPARATOR.Str::after($networkId, PublicId::SEPARATOR)
            : null;
    }

    /**
     * Host-side network name, generated from the id (docs/09 R3).
     */
    public function networkName(): string
    {
        return Grammar::NETWORK_PREFIX.substr(hash('sha256', $this->public_id), 0, 16);
    }

    /**
     * By kind ({@see EnvironmentKind::sortOrder()}), then by name.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function inDisplayOrder(Builder $query): void
    {
        // One `when` per EnvironmentKind case: a new case fails loudly on the binding count.
        $query->orderByRaw(
            'case kind when ? then ? when ? then ? when ? then ? end',
            array_merge(...array_map(static fn (EnvironmentKind $kind): array => [$kind->value, $kind->sortOrder()], EnvironmentKind::cases())),
        )->orderBy('name');
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
