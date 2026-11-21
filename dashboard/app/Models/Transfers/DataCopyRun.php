<?php

declare(strict_types=1);

namespace App\Models\Transfers;

use App\Broadcasting\ServiceActivityTopic;
use App\Enums\Contracts\HasTransitionTable;
use App\Enums\Transfers\CopyRunStatus;
use App\Enums\Transfers\CopySourcePoint;
use App\Enums\Transfers\DataSubject;
use App\Events\Broadcasts\ServiceActivityChanged;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\HasStatusTransitions;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Models\Volume;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One copy of a database or a volume from one environment into another of
 * the same project (D110, docs/14 §14): an export of the source chained into
 * an import of the target. The export and import runs belong to the copy.
 *
 * @property int $id
 * @property string $public_id
 * @property int $team_id
 * @property int $source_service_instance_id
 * @property int $target_service_instance_id
 * @property DataSubject $subject
 * @property int|null $source_volume_id
 * @property int|null $target_volume_id
 * @property CopySourcePoint $source_point
 * @property string|null $snapshot_id the backup read (backup points)
 * @property Carbon|null $snapshot_at when that backup was taken
 * @property int|null $export_run_id
 * @property int|null $import_run_id
 * @property CopyRunStatus $status
 * @property int|null $size_bytes the exported file's size, once known
 * @property int|null $actor_id
 * @property string|null $error
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ServiceInstance $source
 * @property-read ServiceInstance $target
 * @property-read Volume|null $sourceVolume
 * @property-read Volume|null $targetVolume
 * @property-read DataExportRun|null $exportRun
 * @property-read DataImportRun|null $importRun
 * @property-read User|null $actor
 */
#[Fillable(['team_id', 'source_service_instance_id', 'target_service_instance_id', 'subject', 'source_volume_id', 'target_volume_id', 'source_point', 'snapshot_id', 'snapshot_at', 'actor_id'])]
final class DataCopyRun extends Model
{
    use BelongsToTeam, HasPublicId;

    /** @use HasStatusTransitions<CopyRunStatus> */
    use HasStatusTransitions;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'exporting',
    ];

    public static function publicIdPrefix(): string
    {
        return 'cpy';
    }

    /**
     * Whether a copy into this instance is still open: an import or restore
     * of it waits until the copy ended.
     */
    public static function openInto(int $instanceId): bool
    {
        return self::query()
            ->where('target_service_instance_id', $instanceId)
            ->whereIn('status', CopyRunStatus::nonTerminal())
            ->exists();
    }

    /**
     * @param  CopyRunStatus  $from
     * @param  CopyRunStatus  $to
     */
    protected function recordTransition(HasTransitionTable $from, HasTransitionTable $to, ?string $note): void
    {
        if ($to->isTerminal()) {
            $this->forceFill(['finished_at' => now(), 'error' => $note]);
        }
    }

    protected function transitioned(HasTransitionTable $from, HasTransitionTable $to): void
    {
        ServiceActivityChanged::about($this->target, ServiceActivityTopic::Imports);
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
     * @return BelongsTo<Volume, $this>
     */
    public function sourceVolume(): BelongsTo
    {
        return $this->belongsTo(Volume::class, 'source_volume_id');
    }

    /**
     * @return BelongsTo<Volume, $this>
     */
    public function targetVolume(): BelongsTo
    {
        return $this->belongsTo(Volume::class, 'target_volume_id');
    }

    /**
     * @return BelongsTo<DataExportRun, $this>
     */
    public function exportRun(): BelongsTo
    {
        return $this->belongsTo(DataExportRun::class, 'export_run_id');
    }

    /**
     * @return BelongsTo<DataImportRun, $this>
     */
    public function importRun(): BelongsTo
    {
        return $this->belongsTo(DataImportRun::class, 'import_run_id');
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
            'source_point' => CopySourcePoint::class,
            'status' => CopyRunStatus::class,
            'size_bytes' => 'integer',
            'snapshot_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
