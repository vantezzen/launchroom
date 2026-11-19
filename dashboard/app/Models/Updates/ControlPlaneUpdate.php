<?php

declare(strict_types=1);

namespace App\Models\Updates;

use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\Contracts\HasTransitionTable;
use App\Enums\DaemonTaskStatus;
use App\Enums\Updates\ControlPlaneUpdateStatus;
use App\Models\Backups\BackupRun;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\HasStatusTransitions;
use App\Models\DaemonTask;
use App\Models\Server;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One control-plane update (docs/11 §4, docs/07 `control_plane_updates`):
 * pre-upgrade backup, then a self_update task (scope control_plane) on the
 * control-plane server whose launchroomd verifies and installs the release.
 *
 * @property int $id
 * @property string $public_id
 * @property string $from_version
 * @property string $to_version
 * @property string $daemon_image
 * @property ControlPlaneUpdateStatus $status
 * @property bool $skip_backup
 * @property bool $update_servers roll every server to to_version once the new dashboard is up
 * @property bool $automatic started by the nightly automatic-update check
 * @property int|null $fleet_update_id the server rollout this update started (update_servers)
 * @property int|null $backup_run_id
 * @property int $backup_attempts pre-upgrade backups started again after their worker stopped
 * @property int|null $daemon_task_id
 * @property int|null $server_id
 * @property int|null $actor_id
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read BackupRun|null $backupRun
 * @property-read DaemonTask|null $daemonTask
 * @property-read Server|null $server
 * @property-read User|null $actor
 * @property-read FleetUpdate|null $fleetUpdate
 */
#[Fillable(['from_version', 'to_version', 'daemon_image', 'skip_backup', 'update_servers', 'automatic', 'server_id', 'actor_id'])]
final class ControlPlaneUpdate extends Model
{
    use HasPublicId;

    /** @use HasStatusTransitions<ControlPlaneUpdateStatus> */
    use HasStatusTransitions;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
        'backup_attempts' => 0,
    ];

    public static function publicIdPrefix(): string
    {
        return 'cpu';
    }

    public static function active(): ?self
    {
        return self::query()->whereIn('status', [
            ControlPlaneUpdateStatus::Pending->value,
            ControlPlaneUpdateStatus::BackingUp->value,
            ControlPlaneUpdateStatus::Updating->value,
        ])->first();
    }

    /**
     * The last sign of life of the step the update is on (docs/11 §4.3),
     * from the loaded backupRun and daemonTask: the backup's heartbeat, or
     * when the job last moved it on. Null while launchroomd installs (its
     * task reports progress and has its own lease).
     */
    public function lastActivityAt(): ?CarbonInterface
    {
        $run = $this->backup_run_id === null ? null : $this->backupRun;
        $task = $this->daemon_task_id === null ? null : $this->daemonTask;

        return match ($this->status) {
            ControlPlaneUpdateStatus::Pending => $this->created_at,
            ControlPlaneUpdateStatus::BackingUp => match ($run?->status) {
                BackupRunStatus::Running => $run->heartbeat_at ?? $run->started_at ?? $run->created_at,
                BackupRunStatus::Pending => $run->created_at?->max($this->updated_at ?? $run->created_at),
                default => $this->updated_at,
            },
            ControlPlaneUpdateStatus::Updating => $task === null
                ? $this->updated_at
                : ($task->status === DaemonTaskStatus::Pending ? $task->updated_at : null),
            default => null,
        };
    }

    /** No sign of life for `launchroom.updates.stall_seconds`: the page says so and offers Cancel. */
    public function stalled(?CarbonInterface $now = null): bool
    {
        $last = $this->lastActivityAt();

        return $last instanceof CarbonInterface && $last->lt(($now ?? now())->copy()->subSeconds(config()->integer('launchroom.updates.stall_seconds')));
    }

    /**
     * Nothing has changed yet, so the owner can stop it: before launchroomd
     * took the self_update task (it is still pending, or not created).
     */
    public function cancellable(): bool
    {
        if (in_array($this->status, [ControlPlaneUpdateStatus::Pending, ControlPlaneUpdateStatus::BackingUp], true)) {
            return $this->daemon_task_id === null;
        }

        return $this->status === ControlPlaneUpdateStatus::Updating
            && ($this->daemon_task_id === null || $this->daemonTask?->status === DaemonTaskStatus::Pending);
    }

    /**
     * @param  ControlPlaneUpdateStatus  $from
     * @param  ControlPlaneUpdateStatus  $to
     */
    protected function recordTransition(HasTransitionTable $from, HasTransitionTable $to, ?string $note): void
    {
        if ($to !== ControlPlaneUpdateStatus::Pending) {
            $this->forceFill(['started_at' => $this->started_at ?? now()]);
        }

        if ($to->isTerminal()) {
            $this->forceFill(['finished_at' => now()]);
            $this->error = $note;
        }
    }

    /**
     * @return BelongsTo<BackupRun, $this>
     */
    public function backupRun(): BelongsTo
    {
        return $this->belongsTo(BackupRun::class);
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
     * @return BelongsTo<FleetUpdate, $this>
     */
    public function fleetUpdate(): BelongsTo
    {
        return $this->belongsTo(FleetUpdate::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ControlPlaneUpdateStatus::class,
            'skip_backup' => 'boolean',
            'backup_attempts' => 'integer',
            'update_servers' => 'boolean',
            'automatic' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** Recorded by the system: updates finish in the background (docs/09 R26). */
    public function finishedAuditEntry(): AuditEntryData
    {
        return new AuditEntryData(
            action: AuditAction::ControlPlaneUpdateFinished,
            actor: AuditActor::system(),
            subjectType: 'control_plane_update',
            subjectId: $this->public_id,
            meta: ['from' => $this->from_version, 'to' => $this->to_version, 'status' => $this->status->value],
        );
    }
}
