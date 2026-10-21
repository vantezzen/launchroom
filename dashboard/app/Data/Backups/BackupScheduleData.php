<?php

declare(strict_types=1);

namespace App\Data\Backups;

use App\Enums\Backups\BackupSubject;
use App\Models\Backups\BackupSchedule;
use App\Support\Backups\CronDescription;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * A schedule row in Settings → Backups and the Backups tab.
 */
final class BackupScheduleData extends Data
{
    public function __construct(
        public string $id,
        public BackupSubject $subject,
        public string $label,
        public ?string $projectName,
        public ?string $environmentName,
        public ?string $serviceId,
        public string $destinationId,
        public string $destinationName,
        public string $cron,
        public RetentionData $retention,
        public string $retentionSummary,
        public bool $enabled,
        public ?CarbonInterface $nextRunAt,
        public ServiceBackupStatusData $health,
        public bool $canManage,
        /** The schedule as a sentence ("Daily at 03:12 UTC"); null for custom cron. */
        public ?string $cronDescription = null,
    ) {}

    /**
     * Needs serviceInstance.service, serviceInstance.environment.project and destination loaded.
     */
    public static function fromSchedule(BackupSchedule $schedule, ServiceBackupStatusData $health, bool $canManage): self
    {
        $instance = $schedule->serviceInstance;

        return new self(
            $schedule->public_id,
            $schedule->subject,
            $instance?->service->name ?? 'Control plane',
            $instance?->environment->project->name,
            $instance?->environment->name,
            $instance?->service->public_id,
            $schedule->destination->public_id,
            $schedule->destination->name,
            $schedule->cron,
            $schedule->retention,
            $schedule->retention->summary(),
            $schedule->enabled,
            $health->nextRunAt,
            $health,
            $canManage,
            CronDescription::describe($schedule->cron),
        );
    }
}
