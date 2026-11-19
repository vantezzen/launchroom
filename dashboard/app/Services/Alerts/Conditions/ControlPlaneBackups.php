<?php

declare(strict_types=1);

namespace App\Services\Alerts\Conditions;

use App\Data\Alerts\AlertSignal;
use App\Enums\Alerts\AlertRule;
use App\Enums\Alerts\AlertSeverity;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\Backups\BackupSubject;
use App\Models\Backups\BackupRun;
use App\Models\Backups\BackupSchedule;
use App\Models\InstanceSettings;
use App\Models\Server;
use Carbon\CarbonImmutable;

/**
 * `backup_failed` for launchroom's own backup (G40, docs/14 §11): its
 * newest run failed, or it is scheduled and nothing succeeded for
 * `launchroom.backups.stale_after_hours`. Service backups raise the same
 * rule from EvaluateBackupAlerts; this condition only owns the
 * `control_plane:` subjects. Goes to the control-plane server's team.
 */
final readonly class ControlPlaneBackups extends AlertCondition
{
    public function rule(): AlertRule
    {
        return AlertRule::BackupFailed;
    }

    public function subjectPrefix(): string
    {
        return 'control_plane:';
    }

    public function resolvedReason(): string
    {
        return 'launchroom’s backup succeeded again.';
    }

    public function failing(array $rules, array $firing, CarbonImmutable $now): array
    {
        $server = $this->controlPlaneServer();
        $schedule = BackupSchedule::query()->where('subject', BackupSubject::ControlPlane->value)->first();

        $settings = InstanceSettings::current();

        // Not before setup is finished: no passphrase means no scheduled
        // runs yet (RunBackupTick), so nothing failed and nothing is overdue.
        if (! $server instanceof Server || ! $schedule instanceof BackupSchedule || $settings->recovery_public_key === null) {
            return [];
        }

        $latest = BackupRun::query()->where('subject', BackupSubject::ControlPlane->value)
            ->whereIn('status', BackupRunStatus::terminal())
            ->latest('finished_at')->orderByDesc('id')->first();
        $hours = config()->integer('launchroom.backups.stale_after_hours');
        $lastSuccess = BackupRun::query()->where('subject', BackupSubject::ControlPlane->value)
            ->where('status', BackupRunStatus::Succeeded->value)->max('finished_at');
        // Overdue counts from when backups could first run: the schedule and the passphrase both exist.
        $since = CarbonImmutable::instance($schedule->created_at ?? $now);
        $passphraseAt = $settings->recovery_passphrase_set_at;
        $since = $passphraseAt !== null && $passphraseAt->greaterThan($since) ? CarbonImmutable::instance($passphraseAt) : $since;
        $overdue = $schedule->enabled && $since->lessThan($now->subHours($hours))
            && (! is_string($lastSuccess) || CarbonImmutable::parse($lastSuccess)->lessThan($now->subHours($hours)));

        if ($latest?->status === BackupRunStatus::Failed) {
            $title = 'launchroom’s own backup failed';
            $summary = 'The newest backup of the dashboard’s database and keys did not finish. Without one, a broken main server can’t be restored. The error is on the Backups page.';
        } elseif ($overdue) {
            $title = 'launchroom’s own backup is overdue';
            $summary = "No backup of the dashboard’s database and keys succeeded in the last {$hours} hours.";
        } else {
            return [];
        }

        return [$server->team_id.'|control_plane:backup' => fn (): AlertSignal => new AlertSignal(
            team: $this->team($server->team_id),
            rule: AlertRule::BackupFailed,
            subject: 'control_plane:backup',
            title: $title,
            summary: $summary,
            severity: AlertSeverity::Critical,
            href: route('backups.index', [], false),
            server: $server,
            occurrence: $latest?->status === BackupRunStatus::Failed ? $latest->public_id : null,
        )];
    }
}
