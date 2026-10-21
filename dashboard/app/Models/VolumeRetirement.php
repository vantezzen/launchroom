<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VolumeRetirementStatus;
use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A volume whose environment, project or preview was deleted (docs/05 §1.1).
 * The Docker volume stays on its server until `retain_until` has passed
 * and, when its service had backups, its final backup exists
 * (`final_backup_snapshot`) or the deletion was overridden (`confirmed_at`,
 * "delete without final backup"); then it is
 * requested in the server's NodeState `deleteVolumes` and launchroomd
 * reports the outcome (App\Actions\Servers\RecordReport).
 *
 * @property int $id
 * @property int $server_id
 * @property string $volume_public_id
 * @property string $docker_name
 * @property string $service_public_id
 * @property string $environment_public_id
 * @property string $label display only: "<service> in <project> / <environment>"
 * @property string $reason environment_deleted | project_deleted | preview_destroyed
 * @property string $mount_path
 * @property Carbon $retain_until
 * @property bool $backups_configured
 * @property string|null $final_backup_snapshot restic snapshot of the final backup taken before the teardown (docs/14 §9.13)
 * @property Carbon|null $confirmed_at
 * @property int|null $confirmed_by
 * @property VolumeRetirementStatus $status
 * @property Carbon|null $requested_at
 * @property string|null $message the daemon's last explanation (refused, failed)
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Server $server
 */
#[Fillable([
    'team_id', 'server_id', 'volume_public_id', 'docker_name', 'service_public_id', 'environment_public_id',
    'label', 'reason', 'mount_path', 'retain_until', 'backups_configured', 'final_backup_snapshot', 'confirmed_at', 'confirmed_by',
])]
final class VolumeRetirement extends Model
{
    use BelongsToTeam;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'retained',
    ];

    /**
     * Deletable once retention passed and, with backups, a final backup
     * exists or the deletion was overridden.
     */
    public function isDue(): bool
    {
        return $this->status === VolumeRetirementStatus::Retained
            && $this->retain_until->isPast()
            && (! $this->backups_configured || $this->final_backup_snapshot !== null || $this->confirmed_at !== null);
    }

    public function transitionTo(VolumeRetirementStatus $to): void
    {
        $this->status->assertCanTransitionTo($to);
        $this->status = $to;
        $this->save();
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => VolumeRetirementStatus::class,
            'retain_until' => 'datetime',
            'backups_configured' => 'boolean',
            'confirmed_at' => 'datetime',
            'requested_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }
}
