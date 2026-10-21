<?php

declare(strict_types=1);

namespace App\Models\Backups;

use App\Data\Backups\RetentionData;
use App\Enums\Backups\BackupSubject;
use App\Models\Concerns\HasPublicId;
use App\Models\ServiceInstance;
use App\Models\Team;
use Cron\CronExpression;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * When and where a database (or the control plane) is backed up, and how
 * long snapshots are kept (docs/14 §3, docs/07 `backup_schedules`).
 *
 * @property int $id
 * @property string $public_id
 * @property int|null $team_id
 * @property BackupSubject $subject
 * @property int|null $service_instance_id
 * @property int $destination_id
 * @property string $cron
 * @property RetentionData $retention
 * @property bool $enabled
 * @property Carbon|null $last_run_at
 * @property Carbon|null $last_maintenance_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team|null $team
 * @property-read ServiceInstance|null $serviceInstance
 * @property-read BackupDestination $destination
 */
#[Fillable(['team_id', 'subject', 'service_instance_id', 'destination_id', 'cron', 'retention', 'enabled'])]
final class BackupSchedule extends Model
{
    use HasPublicId;

    /** @var array<string, mixed> */
    protected $attributes = [
        'enabled' => true,
    ];

    public static function publicIdPrefix(): string
    {
        return 'bks';
    }

    public function isDue(DateTimeInterface $at): bool
    {
        return $this->enabled && CronExpression::isValidExpression($this->cron) && new CronExpression($this->cron)->isDue($at, 'UTC');
    }

    public function nextRunAt(DateTimeInterface $after): ?DateTimeInterface
    {
        if (! $this->enabled || ! CronExpression::isValidExpression($this->cron)) {
            return null;
        }

        return new CronExpression($this->cron)->getNextRunDate($after, 0, false, 'UTC');
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<ServiceInstance, $this>
     */
    public function serviceInstance(): BelongsTo
    {
        return $this->belongsTo(ServiceInstance::class);
    }

    /**
     * @return BelongsTo<BackupDestination, $this>
     */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(BackupDestination::class, 'destination_id');
    }

    /**
     * @return HasMany<BackupRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(BackupRun::class, 'schedule_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subject' => BackupSubject::class,
            'retention' => RetentionData::class,
            'enabled' => 'boolean',
            'last_run_at' => 'datetime',
            'last_maintenance_at' => 'datetime',
        ];
    }
}
