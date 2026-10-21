<?php

declare(strict_types=1);

namespace App\Models\Backups;

use App\Enums\Backups\BackupSubject;
use App\Enums\Backups\VerificationReason;
use App\Enums\Backups\VerificationStatus;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasPublicId;
use App\Models\DaemonTask;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\Volume;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One automatic test restore of a snapshot into a throwaway container
 * (docs/14 §10.4, docs/07 `backup_verifications`).
 *
 * @property int $id
 * @property string $public_id
 * @property int $service_instance_id
 * @property int|null $backup_run_id
 * @property int $destination_id
 * @property BackupSubject $subject
 * @property int|null $volume_id
 * @property string $snapshot_id
 * @property Carbon|null $snapshot_taken_at
 * @property VerificationReason $reason
 * @property VerificationStatus $status
 * @property list<array{name: string, passed: bool, detail?: string}> $checks
 * @property int|null $tables
 * @property int|null $rows
 * @property int|null $files
 * @property int|null $bytes
 * @property Carbon|null $restored_to
 * @property int|null $duration_ms
 * @property string|null $error
 * @property int|null $daemon_task_id
 * @property int|null $server_id
 * @property int|null $actor_id
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ServiceInstance $serviceInstance
 * @property-read BackupRun|null $backupRun
 * @property-read BackupDestination $destination
 * @property-read DaemonTask|null $daemonTask
 * @property-read Server|null $server
 * @property-read Volume|null $volume
 */
#[Fillable(['team_id', 'service_instance_id', 'backup_run_id', 'destination_id', 'subject', 'volume_id', 'snapshot_id', 'snapshot_taken_at', 'reason', 'daemon_task_id', 'server_id', 'actor_id'])]
final class BackupVerification extends Model
{
    use BelongsToTeam, HasPublicId;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
        'checks' => '[]',
    ];

    public static function publicIdPrefix(): string
    {
        return 'bkv';
    }

    /**
     * The only way a verification's status changes (docs/10 §2.2 rule 3).
     */
    public function transitionTo(VerificationStatus $to, ?string $error = null): void
    {
        $this->status->assertCanTransitionTo($to);
        $this->status = $to;

        if ($to === VerificationStatus::Running) {
            $this->forceFill(['started_at' => $this->started_at ?? now()]);
        }

        if ($to->isTerminal()) {
            $this->forceFill(['started_at' => $this->started_at ?? now(), 'finished_at' => now()]);
            $this->error = $error === null ? null : mb_substr($error, 0, 2048);
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
     * @return BelongsTo<BackupRun, $this>
     */
    public function backupRun(): BelongsTo
    {
        return $this->belongsTo(BackupRun::class);
    }

    /**
     * @return BelongsTo<BackupDestination, $this>
     */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(BackupDestination::class, 'destination_id');
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
     * @return BelongsTo<Volume, $this>
     */
    public function volume(): BelongsTo
    {
        return $this->belongsTo(Volume::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subject' => BackupSubject::class,
            'reason' => VerificationReason::class,
            'status' => VerificationStatus::class,
            'checks' => 'array',
            'tables' => 'integer',
            'rows' => 'integer',
            'files' => 'integer',
            'bytes' => 'integer',
            'restored_to' => 'datetime',
            'duration_ms' => 'integer',
            'snapshot_taken_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
