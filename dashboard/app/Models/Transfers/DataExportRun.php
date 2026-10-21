<?php

declare(strict_types=1);

namespace App\Models\Transfers;

use App\Enums\Transfers\DataSubject;
use App\Enums\Transfers\ExportRunStatus;
use App\Enums\Transfers\ExportSource;
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
 * One export of a database or a volume, live or from a backup (docs/14 §12.5, D55).
 *
 * @property int $id
 * @property string $public_id
 * @property int $team_id
 * @property int $service_instance_id
 * @property DataSubject $subject
 * @property int|null $volume_id
 * @property ExportSource $source
 * @property string|null $snapshot_id
 * @property int|null $transfer_id
 * @property int|null $daemon_task_id
 * @property int|null $actor_id
 * @property ExportRunStatus $status
 * @property string $file_name
 * @property string|null $error
 * @property Carbon|null $ready_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ServiceInstance $serviceInstance
 * @property-read Volume|null $volume
 * @property-read DataTransfer|null $transfer
 * @property-read DaemonTask|null $daemonTask
 * @property-read User|null $actor
 */
#[Fillable(['team_id', 'service_instance_id', 'subject', 'volume_id', 'source', 'snapshot_id', 'transfer_id', 'actor_id', 'file_name'])]
final class DataExportRun extends Model
{
    use BelongsToTeam, HasPublicId;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'preparing',
    ];

    public static function publicIdPrefix(): string
    {
        return 'exp';
    }

    /**
     * The only way an export's status changes (docs/10 §2.2 rule 3).
     */
    public function transitionTo(ExportRunStatus $to, ?string $error = null): void
    {
        $this->status->assertCanTransitionTo($to);
        $this->status = $to;

        if ($to === ExportRunStatus::Ready) {
            $this->forceFill(['ready_at' => now()]);
        }

        if ($to->isTerminal()) {
            $this->forceFill(['finished_at' => now()]);
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
            'source' => ExportSource::class,
            'status' => ExportRunStatus::class,
            'ready_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
