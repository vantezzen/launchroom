<?php

declare(strict_types=1);

namespace App\Models;

use App\Data\ConfigAsCode\DeploymentConfigErrorsData;
use App\Data\Deployments\DeploymentPlanData;
use App\Enums\Contracts\HasTransitionTable;
use App\Enums\DeploymentFailureClass;
use App\Enums\DeploymentPurpose;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Events\DeploymentStatusChanged;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\HasStatusTransitions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One service instance moving to a new (image, resolved config) pair
 * (docs/04 §6).
 *
 * @property int $id
 * @property string $public_id
 * @property int $service_instance_id
 * @property int $environment_id
 * @property int|null $revision_id
 * @property DeploymentTrigger $trigger
 * @property string|null $git_ref
 * @property string|null $commit_sha
 * @property string|null $commit_message
 * @property string|null $commit_author
 * @property string|null $commit_author_avatar_url checked by the provider that parsed the commit (docs/19 §7)
 * @property DeploymentStatus $status
 * @property DeploymentFailureClass|null $failure_class
 * @property string|null $failure_message
 * @property string|null $build_key
 * @property string|null $image
 * @property Carbon|null $image_pruned_at the registry no longer has the image (no rollback)
 * @property bool $hold_traffic staged deployment: healthy → `ready`, routed only on Promote (docs/06 §6.5)
 * @property DeploymentPlanData|null $plan what the build produced
 * @property DeploymentPurpose $purpose
 * @property string|null $target_platform the platform a prebuild builds for
 * @property Carbon|null $prepared_until how long an import preparation waits for its import
 * @property DeploymentConfigErrorsData|null $config_errors
 * @property int|null $build_server_id
 * @property int|null $inventory_id the image's inventory in this team (docs/22 §3.1)
 * @property string|null $env_snapshot
 * @property Carbon $queued_at
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $went_live_at when it reached `active`; null for deployments that never served (docs/04 §6)
 * @property int|null $superseded_by_id
 * @property int|null $rollback_of_id
 * @property int|null $actor_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ServiceInstance $serviceInstance
 * @property-read Environment $environment
 * @property-read EnvironmentRevision|null $revision
 * @property-read User|null $actor
 */
#[Fillable([
    'team_id', 'service_instance_id', 'environment_id', 'revision_id', 'trigger', 'git_ref', 'commit_sha',
    'commit_message', 'commit_author', 'commit_author_avatar_url', 'build_key', 'image', 'env_snapshot', 'queued_at', 'rollback_of_id', 'actor_id',
])]
#[Hidden(['env_snapshot'])]
final class Deployment extends Model
{
    use BelongsToTeam, HasPublicId;

    /** @use HasStatusTransitions<DeploymentStatus> */
    use HasStatusTransitions;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'queued',
        'hold_traffic' => false,
        'purpose' => 'release',
    ];

    public static function publicIdPrefix(): string
    {
        return 'dpl';
    }

    /**
     * A failure summary may quote several lines of build or runtime output.
     */
    public const int FAILURE_MESSAGE_LIMIT = 4000;

    /**
     * The way into Failed: a failure always has a class.
     */
    public function fail(DeploymentFailureClass $class, ?string $message): void
    {
        $this->failure_class = $class;
        $this->failure_message = $message === null ? null : mb_substr($message, 0, self::FAILURE_MESSAGE_LIMIT);
        $this->transitionTo(DeploymentStatus::Failed);
    }

    /**
     * @param  DeploymentStatus  $from
     * @param  DeploymentStatus  $to
     */
    protected function recordTransition(HasTransitionTable $from, HasTransitionTable $to, ?string $note): void
    {
        if ($to === DeploymentStatus::Failed && ! $this->isDirty('failure_class')) {
            throw new LogicException('A deployment fails through fail(), with a failure class.');
        }

        if ($from === DeploymentStatus::Queued && $this->started_at === null) {
            $this->forceFill(['started_at' => now()]);
        }

        if ($to === DeploymentStatus::Active) {
            $this->forceFill(['went_live_at' => now()]);
        }

        if (in_array($to, [DeploymentStatus::Active, DeploymentStatus::Failed, DeploymentStatus::Cancelled], true)
            || ($to === DeploymentStatus::Superseded && $this->finished_at === null)) {
            $this->forceFill(['finished_at' => now()]);
        }
    }

    /**
     * DeploymentStatusChanged is delivered after the surrounding transaction commits.
     *
     * @param  DeploymentStatus  $from
     * @param  DeploymentStatus  $to
     */
    protected function transitioned(HasTransitionTable $from, HasTransitionTable $to): void
    {
        event(new DeploymentStatusChanged($this->id, $from, $to));
    }

    /**
     * @return BelongsTo<ServiceInstance, $this>
     */
    public function serviceInstance(): BelongsTo
    {
        return $this->belongsTo(ServiceInstance::class);
    }

    /**
     * @return BelongsTo<Environment, $this>
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /**
     * @return BelongsTo<EnvironmentRevision, $this>
     */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(EnvironmentRevision::class, 'revision_id');
    }

    /**
     * @return BelongsTo<Deployment, $this>
     */
    public function rollbackOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rollback_of_id');
    }

    /**
     * @return HasMany<DeploymentStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(DeploymentStep::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @return HasMany<Placement, $this>
     */
    public function placements(): HasMany
    {
        return $this->hasMany(Placement::class);
    }

    /**
     * Its image can run again without a build: retained, and its environment
     * snapshot kept (Restart, Start, rollback).
     */
    public function canRerunImage(): bool
    {
        return $this->image !== null && $this->image_pruned_at === null && $this->env_snapshot !== null;
    }

    /**
     * An earlier version that "Roll back" can return to (docs/04 §8).
     */
    public function isRollbackTarget(): bool
    {
        return $this->status === DeploymentStatus::Superseded && $this->went_live_at !== null && $this->canRerunImage();
    }

    /**
     * The oldest queued deployment of $instanceId: the one that starts next.
     */
    public static function nextQueuedId(int $instanceId): ?int
    {
        $next = self::query()
            ->where('service_instance_id', $instanceId)
            ->where('status', DeploymentStatus::Queued->value)
            ->orderBy('id')
            ->value('id');

        return is_int($next) ? $next : null;
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function unfinished(Builder $query): void
    {
        $query->whereIn('status', DeploymentStatus::unfinished());
    }

    /**
     * Served at some point: live now or replaced after it was. Superseded
     * alone doesn't say it (collapsed queued deployments, finished prebuilds).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function wentLive(Builder $query): void
    {
        $query->whereNotNull($query->qualifyColumn('went_live_at'));
    }

    /**
     * Counts in deploy statistics: not cancelled, and not collapsed by a
     * newer one before it started.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function counted(Builder $query): void
    {
        $status = $query->qualifyColumn('status');
        $startedAt = $query->qualifyColumn('started_at');

        $query->where($status, '!=', DeploymentStatus::Cancelled->value)
            ->where(static fn (Builder $q) => $q->where($status, '!=', DeploymentStatus::Superseded->value)->orWhereNotNull($startedAt));
    }

    /**
     * Each service instance's newest deployment that wasn't replaced or
     * cancelled: what a preview's state is made of.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function currentPerInstance(Builder $query): void
    {
        $replaced = DeploymentStatus::values([DeploymentStatus::Superseded, DeploymentStatus::Cancelled]);
        $table = $query->getModel()->getTable();

        $query->whereNotIn($query->qualifyColumn('status'), $replaced)
            ->whereNotExists(static fn (QueryBuilder $newer) => $newer->selectRaw('1')
                ->from($table, 'newer')
                ->whereColumn('newer.service_instance_id', $table.'.service_instance_id')
                ->whereColumn('newer.id', '>', $table.'.id')
                ->whereNotIn('newer.status', $replaced));
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function withPurpose(Builder $query, DeploymentPurpose $purpose): void
    {
        $query->where('purpose', $purpose->value);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trigger' => DeploymentTrigger::class,
            'status' => DeploymentStatus::class,
            'failure_class' => DeploymentFailureClass::class,
            'plan' => DeploymentPlanData::class,
            'purpose' => DeploymentPurpose::class,
            'prepared_until' => 'datetime',
            'config_errors' => DeploymentConfigErrorsData::class,
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'went_live_at' => 'datetime',
            'image_pruned_at' => 'datetime',
            'hold_traffic' => 'boolean',
        ];
    }
}
