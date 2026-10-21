<?php

declare(strict_types=1);

namespace App\Models\Transfers;

use App\Enums\Transfers\DataSubject;
use App\Enums\Transfers\ImportRunStatus;
use App\Enums\Transfers\ImportSourceKind;
use App\Models\Backups\BackupRun;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasPublicId;
use App\Models\DaemonTask;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Models\Volume;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One import into a database or a volume (docs/14 §12.5, D55).
 *
 * @property int $id
 * @property string $public_id
 * @property int $team_id
 * @property int $service_instance_id
 * @property DataSubject $subject
 * @property int|null $volume_id
 * @property int|null $transfer_id
 * @property ImportSourceKind $source_kind
 * @property string $source_label file name, or the URL without user and password
 * @property string|null $sealed_source the URL sealed to the target server (never readable here)
 * @property string|null $reserved_task_id the task id the sealed URL is bound to
 * @property int|null $server_id the server it runs on (a sealed URL is bound to it)
 * @property bool $replace
 * @property bool $stop_dependents
 * @property int $strip_components
 * @property int|null $owner_uid
 * @property int|null $owner_gid
 * @property ImportRunStatus $status
 * @property int|null $safety_backup_run_id
 * @property int|null $daemon_task_id
 * @property int|null $actor_id
 * @property array<string, mixed>|null $result protocol DataImportResult
 * @property string|null $error
 * @property Carbon|null $holding_since
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ServiceInstance $serviceInstance
 * @property-read Volume|null $volume
 * @property-read DataTransfer|null $transfer
 * @property-read BackupRun|null $safetyBackup
 * @property-read DaemonTask|null $daemonTask
 * @property-read User|null $actor
 */
#[Fillable(['team_id', 'service_instance_id', 'subject', 'volume_id', 'transfer_id', 'source_kind', 'source_label', 'server_id', 'replace', 'stop_dependents', 'strip_components', 'owner_uid', 'owner_gid', 'actor_id'])]
#[Hidden(['sealed_source', 'reserved_task_id'])]
final class DataImportRun extends Model
{
    use BelongsToTeam, HasPublicId;

    public static function publicIdPrefix(): string
    {
        return 'imp';
    }

    /**
     * The only way an import's status changes (docs/10 §2.2 rule 3).
     */
    public function transitionTo(ImportRunStatus $to, ?string $error = null): void
    {
        $this->status->assertCanTransitionTo($to);
        $this->status = $to;

        if ($to === ImportRunStatus::Holding) {
            $this->forceFill(['holding_since' => now()]);
        }

        if ($to === ImportRunStatus::Importing) {
            $this->forceFill(['started_at' => $this->started_at ?? now()]);
        }

        if ($to->isTerminal()) {
            $this->forceFill(['finished_at' => now()]);
            $this->error = $error === null ? null : mb_substr($error, 0, 2048);
            // A sealed URL is never kept past the run.
            $this->sealed_source = null;
        }

        $this->save();
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
     * @return BelongsTo<DataTransfer, $this>
     */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(DataTransfer::class, 'transfer_id');
    }

    /**
     * @return BelongsTo<BackupRun, $this>
     */
    public function safetyBackup(): BelongsTo
    {
        return $this->belongsTo(BackupRun::class, 'safety_backup_run_id');
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
            'subject' => DataSubject::class,
            'source_kind' => ImportSourceKind::class,
            'status' => ImportRunStatus::class,
            'replace' => 'boolean',
            'stop_dependents' => 'boolean',
            'strip_components' => 'integer',
            'owner_uid' => 'integer',
            'owner_gid' => 'integer',
            'result' => 'array',
            'holding_since' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
