<?php

declare(strict_types=1);

namespace App\Models\Moves;

use App\Enums\Moves\MoveItemKind;
use App\Enums\Moves\MoveItemStatus;
use App\Models\Backups\BackupRun;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasPublicId;
use App\Models\Deployment;
use App\Models\ServiceInstance;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One service of a server move (docs/14 §13.2) and where it is
 * ({@see MoveItemStatus}).
 *
 * @property int $id
 * @property string $public_id
 * @property int $team_id
 * @property int $server_move_id
 * @property int $service_instance_id
 * @property MoveItemKind $kind
 * @property int $position
 * @property MoveItemStatus $status
 * @property string|null $failed_step the step that failed (MoveItemStatus value)
 * @property string|null $message the latest step's note or the failure
 * @property int|null $expected_bytes what the plan expected to copy (the latest backup's size)
 * @property int|null $copied_bytes what the exports copied
 * @property int|null $deployment_id the deployment that moves it (on the target, or back on the source)
 * @property int|null $prebuild_deployment_id the build for the target's platform
 * @property int|null $backup_run_id the fresh backup before it stopped
 * @property list<array{volume: string|null, backup?: string|null, export: string|null, import: string|null}> $transfers per volume (null: the database): the fresh backup, the export and the import
 * @property int|null $target_version the target's desired-state version that declares the copied volumes
 * @property int $attempts
 * @property CarbonImmutable|null $stopped_at
 * @property CarbonImmutable|null $cutover_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read ServerMove $move
 * @property-read ServiceInstance $serviceInstance
 * @property-read Deployment|null $deployment
 * @property-read Deployment|null $prebuildDeployment
 * @property-read BackupRun|null $backupRun
 */
#[Fillable(['team_id', 'server_move_id', 'service_instance_id', 'kind', 'position', 'expected_bytes'])]
final class ServerMoveItem extends Model
{
    use BelongsToTeam, HasPublicId;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
        'transfers' => '[]',
        'attempts' => 0,
    ];

    public static function publicIdPrefix(): string
    {
        return 'smi';
    }

    /**
     * The only way an item's status changes (docs/10 §2.2 rule 3).
     */
    public function transitionTo(MoveItemStatus $to, ?string $message = null): void
    {
        $this->status->assertCanTransitionTo($to);
        $from = $this->status;
        $this->status = $to;
        $this->message = $message === null ? null : mb_substr($message, 0, 2048);

        if ($to === MoveItemStatus::Failed && $from !== MoveItemStatus::RollingBack) {
            $this->failed_step = $from->value;
        }

        if ($to === MoveItemStatus::Stopping) {
            $this->stopped_at = now();
        }

        if ($to === MoveItemStatus::Pending) {
            $this->forceFill([
                'failed_step' => null,
                'transfers' => [],
                'deployment_id' => null,
                'prebuild_deployment_id' => null,
                'backup_run_id' => null,
                'target_version' => null,
                'stopped_at' => null,
                'cutover_at' => null,
                'finished_at' => null,
            ]);
        }

        if (in_array($to, [MoveItemStatus::Done, MoveItemStatus::Failed, MoveItemStatus::RolledBack, MoveItemStatus::Cancelled, MoveItemStatus::Skipped], true)) {
            $this->finished_at = now();
        }

        $this->save();
    }

    /**
     * @return BelongsTo<ServerMove, $this>
     */
    public function move(): BelongsTo
    {
        return $this->belongsTo(ServerMove::class, 'server_move_id');
    }

    /**
     * @return BelongsTo<ServiceInstance, $this>
     */
    public function serviceInstance(): BelongsTo
    {
        return $this->belongsTo(ServiceInstance::class);
    }

    /**
     * @return BelongsTo<Deployment, $this>
     */
    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }

    /**
     * @return BelongsTo<Deployment, $this>
     */
    public function prebuildDeployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class, 'prebuild_deployment_id');
    }

    /**
     * @return BelongsTo<BackupRun, $this>
     */
    public function backupRun(): BelongsTo
    {
        return $this->belongsTo(BackupRun::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => MoveItemKind::class,
            'status' => MoveItemStatus::class,
            'position' => 'integer',
            'expected_bytes' => 'integer',
            'copied_bytes' => 'integer',
            'transfers' => 'array',
            'target_version' => 'integer',
            'attempts' => 'integer',
            'stopped_at' => 'datetime',
            'cutover_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
