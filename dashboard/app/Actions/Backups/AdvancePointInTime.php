<?php

declare(strict_types=1);

namespace App\Actions\Backups;

use App\Data\Backups\RetentionData;
use App\Enums\Backups\BackupReason;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\Backups\BackupSubject;
use App\Enums\Backups\RestoreMode;
use App\Enums\Backups\RestoreRunStatus;
use App\Enums\Backups\WalArchiveState;
use App\Enums\ServerStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Backups\BackupRun;
use App\Models\Backups\RestoreRun;
use App\Models\Backups\WalArchive;
use App\Models\Server;
use App\Services\Backups\BackupTasks;
use App\Services\Backups\DatabaseWorkloads;
use App\Services\Backups\PointInTimeRecovery;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Point-in-time recovery on the backup tick (docs/14 §10):
 *
 * - base backups: on the archive's cron, and right away when the window has
 *   no start yet (just turned on, or after an in-place restore started a
 *   new timeline) once launchroomd reports the archive active;
 * - retention, once a day: base backups and WAL older than the window plus
 *   a day are forgotten (`--keep-within`), then one prune per repository.
 */
final readonly class AdvancePointInTime
{
    public function __construct(
        private StartBaseBackup $base,
        private BackupTasks $tasks,
        private PointInTimeRecovery $pointInTime,
    ) {}

    /**
     * @return array{base: int, maintained: int}
     */
    public function handle(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now()->utc();
        $started = 0;
        $maintained = 0;

        $archives = WalArchive::query()->where('enabled', true)->with(['serviceInstance.service', 'serviceInstance.environment', 'destination'])->orderBy('id')->get();

        foreach ($archives as $archive) {
            $instance = $archive->serviceInstance;

            if ($instance->removed_at !== null || ! $this->pointInTime->supports($instance)) {
                continue;
            }

            try {
                if ($this->baseDue($archive, $now)) {
                    $archive->forceFill(['last_base_run_at' => $now])->save();
                    $this->base->handle($archive, BackupReason::Scheduled);
                    $started++;
                }
            } catch (DomainRuleViolation) {
                // Not running right now: the next due time tries again.
            } catch (Throwable $e) {
                report($e);
            }

            if ($archive->last_maintenance_at === null || $archive->last_maintenance_at->lt($now->subDay())) {
                $archive->forceFill(['last_maintenance_at' => $now])->save();
                $this->maintain($archive);
                $maintained++;
            }
        }

        return ['base' => $started, 'maintained' => $maintained];
    }

    private function baseDue(WalArchive $archive, CarbonImmutable $now): bool
    {
        $minute = $now->startOfMinute();

        if ($archive->last_base_run_at !== null && $archive->last_base_run_at->gte($minute)) {
            return false;
        }

        if ($archive->baseBackupDue($minute)) {
            return true;
        }

        // The window needs a (new) start: no base backup since archiving
        // started, or since an in-place restore switched the timeline.
        if ($archive->state !== WalArchiveState::Active) {
            return false;
        }

        $since = $archive->enabled_at;
        $restored = RestoreRun::query()
            ->where('target_service_instance_id', $archive->service_instance_id)
            ->where('mode', RestoreMode::InPlace->value)
            ->where('status', RestoreRunStatus::Succeeded->value)
            ->max('finished_at');

        if (is_string($restored) || $restored instanceof \DateTimeInterface) {
            $restoredAt = CarbonImmutable::parse($restored);
            $since = $since === null || $restoredAt->gt($since) ? $restoredAt : $since;
        }

        $runs = BackupRun::query()->where('service_instance_id', $archive->service_instance_id)->where('subject', BackupSubject::BaseBackup->value);

        if ($since !== null) {
            $runs->where('created_at', '>=', $since);
        }

        // Retry a failed first base backup at most hourly.
        $recentFailure = (clone $runs)->where('status', BackupRunStatus::Failed->value)->where('finished_at', '>', $now->subHour())->exists();

        return ! (clone $runs)->whereIn('status', [BackupRunStatus::Succeeded->value, BackupRunStatus::Pending->value, BackupRunStatus::Running->value])->exists()
            && ! $recentFailure;
    }

    private function maintain(WalArchive $archive): void
    {
        $instance = $archive->serviceInstance;
        $server = DatabaseWorkloads::servingPlacement($instance)->server
            ?? Server::query()->where('team_id', $instance->team_id)->where('status', ServerStatus::Online->value)->orderBy('id')->first();

        if (! $server instanceof Server) {
            return;
        }

        $retention = RetentionData::pointInTime($archive->keep_days);
        $this->tasks->maintenance($archive->destination, $server, $instance->team_id, 'forget', $retention, $this->pointInTime->baseFilter($instance));
        $this->tasks->maintenance($archive->destination, $server, $instance->team_id, 'forget', $retention, $this->pointInTime->walTags($instance));

        $lock = cache()->lock('launchroom:backups:prune:'.$archive->destination_id, 86400);

        if ($lock->get()) {
            $this->tasks->maintenance($archive->destination, $server, $instance->team_id, 'prune');
        }
    }
}
