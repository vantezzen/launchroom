<?php

declare(strict_types=1);

namespace App\Actions\Backups;

use App\Enums\Backups\BackupAlertKind;
use App\Enums\Backups\BackupHealth;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\Backups\BackupSubject;
use App\Enums\Backups\VerificationStatus;
use App\Enums\Backups\WalArchiveState;
use App\Events\Backups\BackupAlertRaised;
use App\Events\Backups\BackupAlertResolved;
use App\Models\Backups\BackupRun;
use App\Models\Backups\BackupSchedule;
use App\Models\Backups\BackupVerification;
use App\Models\Backups\WalArchive;
use App\Models\ServiceInstance;
use App\Services\Backups\BackupStatuses;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Backup alerts (docs/14 §10.6), on the backup tick: for every service with
 * a backup schedule or point-in-time recovery, which of these problems it
 * has right now —
 *
 * - **failed**: its newest backup run (dump, volume snapshot or base backup) failed;
 * - **verification failed**: its newest restore verification failed;
 * - **overdue**: scheduled, but no success for `stale_after_hours`;
 * - **WAL lagging**: point-in-time recovery is on and WAL has not reached
 *   the destination for `point_in_time.lag_alert_minutes` (shipping
 *   fails, segments wait, or the server stopped reporting).
 *
 * A problem raises {@see BackupAlertRaised} when it starts (and every 30
 * minutes while it lasts: the alerts API dedups) and {@see BackupAlertResolved}
 * when it ends. The last state per problem is kept in the cache; when the
 * cache is lost an ongoing problem is raised again (idempotent) and a
 * resolve is sent for the problems that are not there (a no-op when
 * nothing fires).
 */
final readonly class EvaluateBackupAlerts
{
    private const int REFRESH_MINUTES = 30;

    public function __construct(
        private BackupStatuses $statuses,
    ) {}

    public function handle(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now()->utc();

        $ids = BackupSchedule::query()->whereNotNull('service_instance_id')->pluck('service_instance_id')
            ->merge(WalArchive::query()->pluck('service_instance_id'))->unique()->values()->all();
        $changed = 0;

        foreach (ServiceInstance::query()->whereIn('id', $ids)->whereNull('removed_at')->with('service')->orderBy('id')->get() as $instance) {
            foreach ($this->problems($instance, $now) as $value => $problem) {
                $kind = BackupAlertKind::from($value);
                $changed += $this->apply($instance, $kind, $problem, $now) ? 1 : 0;
            }
        }

        return $changed;
    }

    /**
     * @return array<string, array{title: string, summary: string, occurrence: string|null}|null> kind => problem (null: fine)
     */
    private function problems(ServiceInstance $instance, CarbonImmutable $now): array
    {
        $name = $instance->service->name;
        $latest = BackupRun::query()->where('service_instance_id', $instance->id)
            ->whereIn('status', [BackupRunStatus::Succeeded->value, BackupRunStatus::Failed->value])
            ->latest('finished_at')->orderByDesc('id')->first();
        $verification = BackupVerification::query()->where('service_instance_id', $instance->id)
            ->whereIn('status', [VerificationStatus::Passed->value, VerificationStatus::Failed->value])
            ->latest('finished_at')->orderByDesc('id')->first();
        $schedule = BackupSchedule::query()->where('service_instance_id', $instance->id)->where('enabled', true)->exists();
        $archive = WalArchive::query()->where('service_instance_id', $instance->id)->where('enabled', true)->first();

        return [
            BackupAlertKind::Failed->value => $latest?->status === BackupRunStatus::Failed ? [
                'title' => ($latest->subject === BackupSubject::BaseBackup ? 'Base backup of ' : 'Backup of ').$name.' failed',
                'summary' => 'The newest backup of '.$name.' did not finish. The error is on its Backups tab.',
                'occurrence' => $latest->public_id,
            ] : null,
            BackupAlertKind::VerificationFailed->value => $verification?->status === VerificationStatus::Failed ? [
                'title' => 'Backup of '.$name.' could not be verified',
                'summary' => 'A test restore of the newest backup of '.$name.' failed. Until one passes, it is not known to be restorable.',
                'occurrence' => $verification->public_id,
            ] : null,
            BackupAlertKind::Overdue->value => $schedule && $this->statuses->forInstance($instance)->status === BackupHealth::Stale ? [
                'title' => 'Backups of '.$name.' are overdue',
                'summary' => 'No backup of '.$name.' succeeded in the last '.config()->integer('launchroom.backups.stale_after_hours').' hours.',
                'occurrence' => null,
            ] : null,
            BackupAlertKind::WalLagging->value => $archive instanceof WalArchive && $this->lagging($archive, $now) ? [
                'title' => 'Point-in-time recovery of '.$name.' is falling behind',
                'summary' => 'Changes to '.$name.' have not reached the backup destination for over '.config()->integer('launchroom.backups.point_in_time.lag_alert_minutes').' minutes, so the newest restorable point is getting older.',
                'occurrence' => null,
            ] : null,
        ];
    }

    private function lagging(WalArchive $archive, CarbonImmutable $now): bool
    {
        $limit = $now->subMinutes(config()->integer('launchroom.backups.point_in_time.lag_alert_minutes'));

        if ($archive->enabled_at === null || $archive->enabled_at->gt($limit)) {
            return false;
        }

        return ($archive->state === WalArchiveState::Failing && $archive->failing_since !== null && $archive->failing_since->lt($limit))
            || $archive->checked_at === null || $archive->checked_at->lt($limit)
            || ($archive->pending_segments > 0 && ($archive->last_archived_at === null || $archive->last_archived_at->lt($limit)));
    }

    /**
     * @param  array{title: string, summary: string, occurrence: string|null}|null  $problem
     */
    private function apply(ServiceInstance $instance, BackupAlertKind $kind, ?array $problem, CarbonImmutable $now): bool
    {
        $key = 'launchroom:backups:alert:'.$kind->value.':'.$instance->id;
        $subject = $kind->value.':'.$instance->public_id;
        $last = Cache::get($key);
        $state = is_array($last) ? $last : null;

        if ($problem === null) {
            if ($state === null) {
                return false;
            }

            Cache::forget($key);
            event(new BackupAlertResolved($instance->team_id, $kind, $instance->id, $subject, match ($kind) {
                BackupAlertKind::Failed => 'The next backup succeeded.',
                BackupAlertKind::VerificationFailed => 'The next verification passed.',
                BackupAlertKind::Overdue => 'A backup succeeded again.',
                BackupAlertKind::WalLagging => 'WAL reaches the destination again.',
            }));

            return true;
        }

        $fresh = $state !== null && ($state['occurrence'] ?? null) === $problem['occurrence']
            && is_int($state['at'] ?? null) && $state['at'] > $now->subMinutes(self::REFRESH_MINUTES)->getTimestamp();

        if ($fresh) {
            return false;
        }

        Cache::put($key, ['occurrence' => $problem['occurrence'], 'at' => $now->getTimestamp()], now()->addDays(7));
        event(new BackupAlertRaised($instance->team_id, $kind, $instance->id, $subject, $problem['title'], $problem['summary'], $problem['occurrence']));

        return $state === null;
    }
}
