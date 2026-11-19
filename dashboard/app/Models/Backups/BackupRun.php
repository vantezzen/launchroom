<?php

declare(strict_types=1);

namespace App\Models\Backups;

use App\Broadcasting\ServiceActivityTopic;
use App\Enums\Backups\BackupReason;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\Backups\BackupSubject;
use App\Enums\Contracts\HasTransitionTable;
use App\Events\Broadcasts\BackupsChanged;
use App\Events\Broadcasts\ServiceActivityChanged;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\HasStatusTransitions;
use App\Models\DaemonTask;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Models\User;
use App\Models\Volume;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One backup attempt (docs/07 `backup_runs`). The snapshot itself lives in
 * the restic repository; `snapshot_id` points at it.
 *
 * @property int $id
 * @property string $public_id
 * @property int|null $team_id
 * @property int|null $schedule_id
 * @property int $destination_id
 * @property BackupSubject $subject
 * @property int|null $service_instance_id
 * @property int|null $volume_id volume runs: the volume backed up
 * @property BackupReason $reason
 * @property BackupRunStatus $status
 * @property string|null $snapshot_id
 * @property int|null $size_bytes
 * @property int|null $added_bytes
 * @property int|null $duration_ms from the task result (metrics)
 * @property int|null $daemon_task_id
 * @property int|null $server_id
 * @property int|null $actor_id
 * @property string|null $batch_id "Back up everything now": the runs started together
 * @property string|null $error
 * @property Carbon|null $heartbeat_at control-plane runs: last sign of life of the worker running it
 * @property int|null $progress_bytes control-plane runs: bytes of the dump stored so far
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team|null $team
 * @property-read BackupSchedule|null $schedule
 * @property-read BackupDestination $destination
 * @property-read ServiceInstance|null $serviceInstance
 * @property-read Volume|null $volume
 * @property-read DaemonTask|null $daemonTask
 * @property-read Server|null $server
 * @property-read User|null $actor
 */
#[Fillable(['team_id', 'schedule_id', 'destination_id', 'subject', 'service_instance_id', 'volume_id', 'reason', 'daemon_task_id', 'server_id', 'actor_id', 'batch_id'])]
final class BackupRun extends Model
{
    use HasPublicId;

    /** @use HasStatusTransitions<BackupRunStatus> */
    use HasStatusTransitions;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
    ];

    /** The error of a control-plane run whose worker stopped (docs/11 §4.3). */
    public const string ABANDONED = 'The backup stopped without finishing: the background worker running it was restarted or stopped.';

    public static function publicIdPrefix(): string
    {
        return 'bkr';
    }

    /**
     * A control-plane run (taken by a dashboard worker) that shows no sign
     * of life: running without a heartbeat for the stall time, or still
     * pending after the backup timeout. Its worker died; it never finishes
     * by itself. Runs on launchroomd are followed through their tasks.
     */
    public function abandoned(?CarbonInterface $now = null): bool
    {
        if ($this->team_id !== null || $this->daemon_task_id !== null) {
            return false;
        }

        $now ??= now();

        return match ($this->status) {
            BackupRunStatus::Running => ($this->heartbeat_at ?? $this->started_at ?? $this->created_at)?->lt($now->copy()->subSeconds(config()->integer('launchroom.backups.control_plane_stall_seconds'))) ?? false,
            BackupRunStatus::Pending => $this->created_at?->lt($now->copy()->subSeconds(config()->integer('launchroom.backups.task_timeout_seconds') + config()->integer('launchroom.backups.control_plane_stall_seconds'))) ?? false,
            default => false,
        };
    }

    /**
     * @param  BackupRunStatus  $from
     * @param  BackupRunStatus  $to
     */
    protected function recordTransition(HasTransitionTable $from, HasTransitionTable $to, ?string $note): void
    {
        if ($to === BackupRunStatus::Running) {
            $this->forceFill(['started_at' => $this->started_at ?? now()]);
        }

        if ($to->isTerminal()) {
            $this->forceFill(['started_at' => $this->started_at ?? now(), 'finished_at' => now()]);
            $this->error = $note;
        }
    }

    /**
     * Runs that count for a service's backup state: database dumps, base
     * backups, and snapshots of volumes that are still backed up (a volume
     * whose backups were turned off no longer raises "failed" or "overdue").
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function ofBackedUpVolumes(Builder $query): void
    {
        $query->where(static fn (Builder $runs) => $runs
            ->whereNull('volume_id')
            ->orWhereIn('volume_id', Volume::query()->where('backup_enabled', true)->select('id')));
    }

    /**
     * Runs on a destination that is still connected (not removed).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function stored(Builder $query): void
    {
        $query->whereIn('destination_id', BackupDestination::query()->select('id'));
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<BackupSchedule, $this>
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(BackupSchedule::class, 'schedule_id');
    }

    /**
     * @return BelongsTo<BackupDestination, $this>
     */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(BackupDestination::class, 'destination_id')->withTrashed();
    }

    /**
     * @return BelongsTo<ServiceInstance, $this>
     */
    public function serviceInstance(): BelongsTo
    {
        return $this->belongsTo(ServiceInstance::class);
    }

    /**
     * @return BelongsTo<Volume, $this>
     */
    public function volume(): BelongsTo
    {
        return $this->belongsTo(Volume::class);
    }

    /**
     * @return BelongsTo<DaemonTask, $this>
     */
    public function daemonTask(): BelongsTo
    {
        return $this->belongsTo(DaemonTask::class);
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subject' => BackupSubject::class,
            'reason' => BackupReason::class,
            'status' => BackupRunStatus::class,
            'size_bytes' => 'integer',
            'added_bytes' => 'integer',
            'duration_ms' => 'integer',
            'heartbeat_at' => 'datetime',
            'progress_bytes' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::created(static fn (self $run) => BackupsChanged::about($run->team_id));
    }

    protected function transitioned(HasTransitionTable $from, HasTransitionTable $to): void
    {
        ServiceActivityChanged::about($this->serviceInstance, ServiceActivityTopic::Backups);
        BackupsChanged::about($this->team_id);
    }
}
