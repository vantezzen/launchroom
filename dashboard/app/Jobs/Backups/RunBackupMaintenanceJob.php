<?php

declare(strict_types=1);

namespace App\Jobs\Backups;

use App\Actions\Backups\RunBackupMaintenance;
use App\Models\Backups\BackupSchedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;

/**
 * Daily retention for one schedule, off the scheduler process (the
 * control-plane variant runs restic forget/prune here).
 */
#[Tries(1)]
#[UniqueFor(3600)]
final class RunBackupMaintenanceJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout;

    public function __construct(
        public readonly int $scheduleId,
    ) {
        $this->timeout = config()->integer('launchroom.backups.task_timeout_seconds');
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return (string) $this->scheduleId;
    }

    public function handle(RunBackupMaintenance $maintenance): void
    {
        $schedule = BackupSchedule::query()->whereKey($this->scheduleId)->first();

        if ($schedule instanceof BackupSchedule) {
            $maintenance->handle($schedule);
        }
    }
}
