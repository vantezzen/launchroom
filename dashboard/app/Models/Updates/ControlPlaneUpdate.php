<?php

declare(strict_types=1);

namespace App\Models\Updates;

use App\Enums\Updates\ControlPlaneUpdateStatus;
use App\Models\Backups\BackupRun;
use App\Models\Concerns\HasPublicId;
use App\Models\DaemonTask;
use App\Models\Server;
use App\Models\User;
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

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
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
     * The only way an update's status changes (docs/10 §2.2 rule 3).
     */
    public function transitionTo(ControlPlaneUpdateStatus $to, ?string $error = null): void
    {
        $this->status->assertCanTransitionTo($to);
        $this->status = $to;

        if ($to !== ControlPlaneUpdateStatus::Pending) {
            $this->forceFill(['started_at' => $this->started_at ?? now()]);
        }

        if ($to->isTerminal()) {
            $this->forceFill(['finished_at' => now()]);
            $this->error = $error === null ? null : mb_substr($error, 0, 2048);
        }

        $this->save();
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
            'update_servers' => 'boolean',
            'automatic' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
