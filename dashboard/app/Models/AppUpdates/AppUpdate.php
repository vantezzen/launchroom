<?php

declare(strict_types=1);

namespace App\Models\AppUpdates;

use App\Enums\AppUpdates\AppUpdateStatus;
use App\Enums\AppUpdates\UpdateKind;
use App\Enums\Contracts\HasTransitionTable;
use App\Events\CanvasChanged;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\HasStatusTransitions;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\ServiceInstance;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One update of an image service to a newer release (docs/04 §14.2):
 * backup, deploy with the new image, watch. At most one open per instance
 * (partial unique index).
 *
 * @property int $id
 * @property string $public_id
 * @property int $team_id
 * @property int $service_instance_id
 * @property int $environment_id
 * @property AppUpdateStatus $status
 * @property bool $automatic
 * @property UpdateKind $kind
 * @property string $from_reference
 * @property string $to_reference `<registry>/<repository>:<tag>@sha256:…`
 * @property string $from_version
 * @property string $to_version
 * @property int|null $actor_id
 * @property int|null $deployment_id
 * @property list<int> $backup_run_ids
 * @property string|null $backup_note why there is no backup from before the update
 * @property string|null $message what went wrong, or the rollback's reason
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ServiceInstance $serviceInstance
 * @property-read Environment $environment
 * @property-read Deployment|null $deployment
 * @property-read User|null $actor
 */
#[Fillable(['team_id', 'service_instance_id', 'environment_id', 'automatic', 'kind', 'from_reference', 'to_reference', 'from_version', 'to_version', 'actor_id', 'started_at'])]
final class AppUpdate extends Model
{
    use BelongsToTeam, HasPublicId;

    /** @use HasStatusTransitions<AppUpdateStatus> */
    use HasStatusTransitions;

    /** @var array<string, mixed> */
    protected $attributes = [
        // Every update starts with its backups (none to wait for is fine).
        'status' => 'backing_up',
        'automatic' => false,
        'backup_run_ids' => '[]',
    ];

    public static function publicIdPrefix(): string
    {
        return 'apu';
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereIn('status', array_map(static fn (AppUpdateStatus $status): string => $status->value, AppUpdateStatus::open()));
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
     * @return BelongsTo<Deployment, $this>
     */
    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @param  AppUpdateStatus  $from
     * @param  AppUpdateStatus  $to
     */
    protected function recordTransition(HasTransitionTable $from, HasTransitionTable $to, ?string $note): void
    {
        if ($to->isTerminal()) {
            $this->forceFill(['finished_at' => now()]);
        }

        if ($note !== null) {
            $this->message = $note;
        }
    }

    /**
     * @param  AppUpdateStatus  $from
     * @param  AppUpdateStatus  $to
     */
    protected function transitioned(HasTransitionTable $from, HasTransitionTable $to): void
    {
        $instance = ServiceInstance::query()->find($this->service_instance_id);

        if ($instance instanceof ServiceInstance) {
            CanvasChanged::forInstance($instance);
        }
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AppUpdateStatus::class,
            'kind' => UpdateKind::class,
            'automatic' => 'boolean',
            'backup_run_ids' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
