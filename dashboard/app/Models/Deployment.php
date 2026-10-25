<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DeploymentFailureClass;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Events\DeploymentStatusChanged;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

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
 * @property string|null $commit_author_avatar_url always on avatars.githubusercontent.com (GitHubAvatar)
 * @property DeploymentStatus $status
 * @property DeploymentFailureClass|null $failure_class
 * @property string|null $failure_message
 * @property string|null $build_key
 * @property string|null $image
 * @property Carbon|null $image_pruned_at the registry no longer has the image (no rollback)
 * @property bool $hold_traffic staged deployment: healthy → `ready`, routed only on Promote (docs/06 §6.5)
 * @property array<string, mixed>|null $plan
 * @property array{path: string, commitSha: string|null, repository: string|null, errors: list<array<string, mixed>>}|null $config_errors the launchroom.json problems a push failed on (docs/18 §6)
 * @property int|null $build_server_id
 * @property int|null $inventory_id the image's inventory in this team (docs/22 §3.1)
 * @property string|null $env_snapshot
 * @property Carbon $queued_at
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
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

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'queued',
        'hold_traffic' => false,
    ];

    public static function publicIdPrefix(): string
    {
        return 'dpl';
    }

    /**
     * The only way a deployment's status changes (docs/10 §2.2 rule 3).
     * Emits DeploymentStatusChanged after the surrounding transaction commits.
     */
    public function transitionTo(DeploymentStatus $to, ?DeploymentFailureClass $failure = null, ?string $message = null): void
    {
        $from = $this->status;
        $from->assertCanTransitionTo($to);

        $this->status = $to;

        if ($from === DeploymentStatus::Queued && $this->started_at === null) {
            $this->forceFill(['started_at' => now()]);
        }

        if ($to === DeploymentStatus::Failed) {
            $this->failure_class = $failure ?? DeploymentFailureClass::RuntimeError;
            $this->failure_message = $message === null ? null : mb_substr($message, 0, 4000);
        }

        if (in_array($to, [DeploymentStatus::Active, DeploymentStatus::Failed, DeploymentStatus::Cancelled], true)
            || ($to === DeploymentStatus::Superseded && $this->finished_at === null)) {
            $this->forceFill(['finished_at' => now()]);
        }

        $this->save();

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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trigger' => DeploymentTrigger::class,
            'status' => DeploymentStatus::class,
            'failure_class' => DeploymentFailureClass::class,
            'plan' => 'array',
            'config_errors' => 'array',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'image_pruned_at' => 'datetime',
            'hold_traffic' => 'boolean',
        ];
    }
}
