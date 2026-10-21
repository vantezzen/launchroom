<?php

declare(strict_types=1);

namespace App\Models\Backups;

use App\Enums\Backups\BackupSubject;
use App\Enums\Backups\RestoreMode;
use App\Enums\Backups\RestoreRunStatus;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasPublicId;
use App\Models\DaemonTask;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Models\Volume;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One restore (docs/14 §5.1, docs/07 `restore_runs`).
 *
 * @property int $id
 * @property string $public_id
 * @property int $destination_id
 * @property string $snapshot_id
 * @property int $source_service_instance_id
 * @property int|null $target_service_instance_id
 * @property RestoreMode $mode
 * @property BackupSubject $subject database (dump) or volume (tar) snapshot
 * @property int|null $volume_id volume restores: the volume restored into
 * @property int|null $server_replacement_id restores started by "Replace server"
 * @property RestoreRunStatus $status
 * @property int|null $safety_backup_run_id
 * @property int|null $daemon_task_id
 * @property int|null $actor_id
 * @property int|null $size_bytes
 * @property Carbon|null $point_in_time base_backup restores: where replay stops (null: as far as the archive goes)
 * @property Carbon|null $restored_to base_backup restores: the last transaction replayed
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read BackupDestination $destination
 * @property-read ServiceInstance $source
 * @property-read ServiceInstance|null $target
 * @property-read BackupRun|null $safetyBackup
 * @property-read DaemonTask|null $daemonTask
 * @property-read Volume|null $volume
 * @property-read User|null $actor
 */
#[Fillable(['team_id', 'destination_id', 'snapshot_id', 'source_service_instance_id', 'target_service_instance_id', 'mode', 'subject', 'volume_id', 'server_replacement_id', 'actor_id', 'point_in_time'])]
final class RestoreRun extends Model
{
    use BelongsToTeam, HasPublicId;

    /** @var array<string, mixed> */
    protected $attributes = [
        'subject' => 'database',
    ];

    public static function publicIdPrefix(): string
    {
        return 'rsr';
    }

    /**
     * The only way a restore's status changes (docs/10 §2.2 rule 3).
     */
    public function transitionTo(RestoreRunStatus $to, ?string $error = null): void
    {
        $this->status->assertCanTransitionTo($to);
        $this->status = $to;

        if ($to === RestoreRunStatus::Restoring) {
            $this->forceFill(['started_at' => $this->started_at ?? now()]);
        }

        if ($to->isTerminal()) {
            $this->forceFill(['finished_at' => now()]);
            $this->error = $error === null ? null : mb_substr($error, 0, 2048);
        }

        $this->save();
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
    public function source(): BelongsTo
    {
        return $this->belongsTo(ServiceInstance::class, 'source_service_instance_id');
    }

    /**
     * @return BelongsTo<ServiceInstance, $this>
     */
    public function target(): BelongsTo
    {
        return $this->belongsTo(ServiceInstance::class, 'target_service_instance_id');
    }

    /**
     * @return BelongsTo<BackupRun, $this>
     */
    public function safetyBackup(): BelongsTo
    {
        return $this->belongsTo(BackupRun::class, 'safety_backup_run_id');
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
            'mode' => RestoreMode::class,
            'subject' => BackupSubject::class,
            'status' => RestoreRunStatus::class,
            'size_bytes' => 'integer',
            'point_in_time' => 'datetime',
            'restored_to' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
