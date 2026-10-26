<?php

declare(strict_types=1);

namespace App\Listeners\Backups;

use App\Actions\Alerts\RaiseAlert;
use App\Actions\Alerts\ResolveAlert;
use App\Data\Alerts\AlertSignal;
use App\Enums\Alerts\AlertRule;
use App\Enums\Alerts\AlertSeverity;
use App\Events\Backups\BackupAlertRaised;
use App\Events\Backups\BackupAlertResolved;
use App\Models\ServiceInstance;
use App\Models\Team;

/**
 * Backup problems → alerts (docs/15 §3.2). Every backup problem uses the
 * `backup_failed` rule (one switch per team for "backups need attention");
 * the subject key tells them apart: `backup:`, `backup_verify:`,
 * `backup_overdue:`, `wal_archive:` + the instance's public id. Failures
 * are critical, overdue backups and WAL lag warnings.
 */
final readonly class ForwardBackupAlerts
{
    public function __construct(
        private RaiseAlert $raise,
        private ResolveAlert $resolve,
    ) {}

    public function handleRaised(BackupAlertRaised $event): void
    {
        $team = Team::query()->find($event->teamId);
        $instance = ServiceInstance::query()->whereKey($event->serviceInstanceId)->with(['service', 'environment.project'])->first();

        if (! $team instanceof Team || ! $instance instanceof ServiceInstance) {
            return;
        }

        $this->raise->handle(new AlertSignal(
            team: $team,
            rule: AlertRule::BackupFailed,
            subject: $event->subject,
            title: $event->title,
            summary: $event->summary,
            severity: $event->kind->critical() ? AlertSeverity::Critical : AlertSeverity::Warning,
            href: route('projects.services.section', ['project' => $instance->environment->project, 'environment' => $instance->environment, 'service' => $instance->service, 'section' => 'backups']),
            project: $instance->environment->project,
            environment: $instance->environment,
            serviceInstance: $instance,
            occurrence: $event->occurrence,
        ));
    }

    public function handleResolved(BackupAlertResolved $event): void
    {
        $this->resolve->handle($event->teamId, AlertRule::BackupFailed, $event->subject, reason: $event->reason);
    }
}
