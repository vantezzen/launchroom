<?php

declare(strict_types=1);

namespace App\Models\Backups;

use App\Enums\Backups\BackupReason;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\Backups\BackupSubject;
use App\Models\Concerns\HasPublicId;
use App\Models\DaemonTask;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Models\User;
use App\Models\Volume;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
 * @property string|null $error
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
#[Fillable(['team_id', 'schedule_id', 'destination_id', 'subject', 'service_instance_id', 'volume_id', 'reason', 'daemon_task_id', 'server_id', 'actor_id'])]
final class BackupRun extends Model
{
    use HasPublicId;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
    ];

    public static function publicIdPrefix(): string
    {
        return 'bkr';
    }

    /**
     * The only way a run's status changes (docs/10 §2.2 rule 3).
     */
    public function transitionTo(BackupRunStatus $to, ?string $error = null): void
    {
        $this->status->assertCanTransitionTo($to);
        $this->status = $to;

        if ($to === BackupRunStatus::Running) {
            $this->forceFill(['started_at' => $this->started_at ?? now()]);
        }

        if ($to->isTerminal()) {
            $this->forceFill(['started_at' => $this->started_at ?? now(), 'finished_at' => now()]);
            $this->error = $error === null ? null : mb_substr($error, 0, 2048);
        }

        $this->save();
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
        return $this->belongsTo(BackupDestination::class, 'destination_id');
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
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
