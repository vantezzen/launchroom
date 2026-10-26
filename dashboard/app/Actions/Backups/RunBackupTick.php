<?php

declare(strict_types=1);

namespace App\Actions\Backups;

use App\Actions\Servers\AdvanceServerReplacements;
use App\Data\Audit\AuditActor;
use App\Enums\Backups\BackupReason;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\Backups\BackupSubject;
use App\Exceptions\DomainRuleViolation;
use App\Jobs\Backups\RunBackupMaintenanceJob;
use App\Models\Backups\BackupRun;
use App\Models\Backups\BackupSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder;
use Throwable;

/**
 * The backup scheduler (`launchroom:backups:tick`, every minute):
 *
 * 1. default schedules for databases added since the last tick;
 * 2. runs follow their daemon tasks (catches anything the completion hook missed);
 * 3. due schedules start a backup (cron in UTC; at most once per minute);
 * 4. daily retention per schedule;
 * 5. restores move forward;
 * 6. deletions waiting for their final backups move forward (docs/14 §9.13);
 * 7. "Replace server" runs move forward (docs/14 §5.3);
 * 8. point-in-time recovery: base backups and their retention (docs/14 §10);
 * 9. scheduled restore verifications (docs/14 §10.4);
 * 10. backup alerts (docs/14 §10.6).
 */
final readonly class RunBackupTick
{
    public function __construct(
        private EnsureBackupSchedules $ensure,
        private SyncBackupTasks $sync,
        private StartServiceBackup $service,
        private StartControlPlaneBackup $controlPlane,
        private AdvanceRestores $restores,
        private AdvanceDeletions $deletions,
        private AdvanceServerReplacements $replacements,
        private AdvancePointInTime $pointInTime,
        private AdvanceVerifications $verifications,
        private EvaluateBackupAlerts $alerts,
    ) {}

    /**
     * @return array{schedules: int, started: int, maintained: int, restores: int, deletions: int, replacements: int, baseBackups: int, verifications: int, alerts: int}
     */
    public function handle(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now()->utc();
        $minute = $now->startOfMinute();

        $schedules = $this->ensure->handle();
        $this->sync->handle();

        $started = 0;
        $maintained = 0;

        $due = BackupSchedule::query()
            ->where('enabled', true)
            ->where(static fn (Builder $query) => $query->whereNull('last_run_at')->orWhere('last_run_at', '<', $minute))
            ->with(['serviceInstance.service', 'destination'])
            ->orderBy('id')
            ->get();

        foreach ($due as $schedule) {
            if (! $schedule->isDue($minute)) {
                continue;
            }

            $schedule->forceFill(['last_run_at' => $now])->save();

            // A stopped service's backups pause (D68): nothing runs, nothing changes.
            if ($schedule->serviceInstance?->stopped_at !== null) {
                continue;
            }

            try {
                $this->start($schedule);
                $started++;
            } catch (DomainRuleViolation $e) {
                $this->recordSkipped($schedule, $e->getMessage());
            } catch (Throwable $e) {
                report($e);
            }
        }

        $maintenance = BackupSchedule::query()
            ->where('enabled', true)
            ->where(static fn (Builder $query) => $query->whereNull('last_maintenance_at')->orWhere('last_maintenance_at', '<', $now->subDay()))
            ->whereHas('runs', static fn (Builder $query) => $query->where('status', BackupRunStatus::Succeeded->value))
            ->orderBy('id')
            ->get();

        foreach ($maintenance as $schedule) {
            // Marked now so the next ticks don't queue it again.
            $schedule->forceFill(['last_maintenance_at' => $now])->save();
            dispatch(new RunBackupMaintenanceJob($schedule->id));
            $maintained++;
        }

        $result = ['schedules' => $schedules, 'started' => $started, 'maintained' => $maintained, 'restores' => $this->restores->handle(), 'deletions' => $this->deletions->handle(), 'replacements' => $this->replacements->handle()];
        $pointInTime = $this->pointInTime->handle($now);

        return [...$result, 'baseBackups' => $pointInTime['base'], 'verifications' => $this->verifications->handle($now), 'alerts' => $this->alerts->handle($now)];
    }

    private function start(BackupSchedule $schedule): void
    {
        if ($schedule->subject === BackupSubject::ControlPlane) {
            $this->controlPlane->handle(BackupReason::Scheduled, null, AuditActor::system());

            return;
        }

        if ($schedule->serviceInstance !== null && $schedule->serviceInstance->removed_at === null) {
            $this->service->handle($schedule->serviceInstance, BackupReason::Scheduled, null, AuditActor::system(), $schedule);
        }
    }

    /**
     * A due backup that could not start is recorded as a failed run (so the
     * canvas shows "failing", not a silently stale backup) — unless the
     * database was never deployed.
     */
    private function recordSkipped(BackupSchedule $schedule, string $reason): void
    {
        if ($schedule->subject !== BackupSubject::ControlPlane && $schedule->serviceInstance?->active_deployment_id === null) {
            return;
        }

        $run = BackupRun::query()->create([
            'team_id' => $schedule->team_id,
            'schedule_id' => $schedule->id,
            'destination_id' => $schedule->destination_id,
            'subject' => $schedule->subject,
            'service_instance_id' => $schedule->service_instance_id,
            'reason' => BackupReason::Scheduled,
        ]);
        $run->transitionTo(BackupRunStatus::Failed, $reason);
    }
}
