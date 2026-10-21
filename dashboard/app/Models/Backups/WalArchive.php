<?php

declare(strict_types=1);

namespace App\Models\Backups;

use App\Enums\Backups\WalArchiveState;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasPublicId;
use App\Models\Server;
use App\Models\ServiceInstance;
use Cron\CronExpression;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Point-in-time recovery of one Postgres instance (docs/14 §10, docs/07
 * `wal_archives`): whether it is on, how long it reaches back, when base
 * backups run, and the WAL shipping state launchroomd reports.
 *
 * @property int $id
 * @property string $public_id
 * @property int $service_instance_id
 * @property int $destination_id
 * @property bool $enabled
 * @property Carbon|null $enabled_at
 * @property int $keep_days
 * @property string $base_cron
 * @property Carbon|null $last_base_run_at
 * @property Carbon|null $last_maintenance_at
 * @property WalArchiveState|null $state
 * @property Carbon|null $checked_at
 * @property Carbon|null $last_archived_at
 * @property string|null $last_segment
 * @property int $pending_segments
 * @property string|null $message
 * @property int|null $reported_by_server_id
 * @property Carbon|null $failing_since
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ServiceInstance $serviceInstance
 * @property-read BackupDestination $destination
 * @property-read Server|null $reportedBy
 */
#[Fillable(['team_id', 'service_instance_id', 'destination_id', 'enabled', 'enabled_at', 'keep_days', 'base_cron'])]
final class WalArchive extends Model
{
    use BelongsToTeam, HasPublicId;

    /** @var array<string, mixed> */
    protected $attributes = [
        'enabled' => true,
        'keep_days' => 7,
        'pending_segments' => 0,
    ];

    public static function publicIdPrefix(): string
    {
        return 'wal';
    }

    public function baseBackupDue(DateTimeInterface $at): bool
    {
        return $this->enabled && CronExpression::isValidExpression($this->base_cron) && new CronExpression($this->base_cron)->isDue($at, 'UTC');
    }

    public function nextBaseBackupAt(DateTimeInterface $after): ?DateTimeInterface
    {
        if (! $this->enabled || ! CronExpression::isValidExpression($this->base_cron)) {
            return null;
        }

        return new CronExpression($this->base_cron)->getNextRunDate($after, 0, false, 'UTC');
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
     * @return BelongsTo<Server, $this>
     */
    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'reported_by_server_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'enabled_at' => 'datetime',
            'keep_days' => 'integer',
            'last_base_run_at' => 'datetime',
            'last_maintenance_at' => 'datetime',
            'state' => WalArchiveState::class,
            'checked_at' => 'datetime',
            'last_archived_at' => 'datetime',
            'pending_segments' => 'integer',
            'failing_since' => 'datetime',
        ];
    }
}
