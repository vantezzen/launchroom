<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Backups\BackupDayData;
use App\Data\Backups\BackupProtectionData;
use App\Data\Backups\DestinationUsageData;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\Backups\BackupSubject;
use App\Models\Backups\BackupDestination;
use App\Models\Backups\BackupRun;
use App\Models\Backups\BackupSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Settings → Backups at a glance (docs/design/flows.md §14): a 30-day run
 * strip per schedule and, per destination, the newest snapshot of
 * everything stored there by kind.
 */
final class BackupOverview
{
    public const int DAYS = 30;

    /**
     * @param  list<BackupSchedule>  $schedules
     * @return list<BackupProtectionData>
     */
    public function protection(array $schedules): array
    {
        if ($schedules === []) {
            return [];
        }

        $today = CarbonImmutable::now('UTC')->startOfDay();
        $since = $today->subDays(self::DAYS - 1);

        $runs = BackupRun::query()
            ->whereIn('schedule_id', array_map(static fn (BackupSchedule $schedule): int => $schedule->id, $schedules))
            ->whereIn('status', [BackupRunStatus::Succeeded->value, BackupRunStatus::Failed->value])
            ->where('created_at', '>=', $since)
            ->orderBy('id')
            ->get(['id', 'schedule_id', 'status', 'size_bytes', 'created_at', 'finished_at']);

        /** @var array<int, array<string, string>> $byDay */
        $byDay = [];
        /** @var array<int, BackupRun> $latest */
        $latest = [];

        foreach ($runs as $run) {
            $scheduleId = (int) $run->schedule_id;
            $day = ($run->created_at ?? $today)->copy()->utc()->format('Y-m-d');
            $ok = $run->status === BackupRunStatus::Succeeded;
            $byDay[$scheduleId][$day] = $ok || ($byDay[$scheduleId][$day] ?? null) === 'ok' ? 'ok' : 'failed';

            if ($ok) {
                $latest[$scheduleId] = $run;
            }
        }

        $out = [];

        foreach ($schedules as $schedule) {
            $days = [];

            for ($i = 0; $i < self::DAYS; $i++) {
                $date = $since->addDays($i)->format('Y-m-d');
                $days[] = new BackupDayData($date, $byDay[$schedule->id][$date] ?? 'none');
            }

            $last = $latest[$schedule->id] ?? $this->lastSucceeded($schedule);

            $out[] = new BackupProtectionData(
                $schedule->public_id,
                $days,
                $last instanceof BackupRun ? ($last->finished_at ?? $last->created_at) : null,
                $last?->size_bytes,
            );
        }

        return $out;
    }

    /**
     * @param  list<BackupDestination>  $destinations
     * @return list<DestinationUsageData>
     */
    public function usage(array $destinations): array
    {
        if ($destinations === []) {
            return [];
        }

        $ids = array_map(static fn (BackupDestination $destination): int => $destination->id, $destinations);

        // The newest successful run of every backed-up thing per destination.
        $newest = BackupRun::query()
            ->selectRaw('max(id) as id')
            ->whereIn('destination_id', $ids)
            ->where('status', BackupRunStatus::Succeeded->value)
            ->groupBy('destination_id', 'subject', 'service_instance_id', 'volume_id');

        $runs = BackupRun::query()
            ->whereIn('id', DB::query()->fromSub($newest, 'newest')->select('id'))
            ->get(['id', 'destination_id', 'subject', 'size_bytes', 'created_at', 'finished_at']);

        $out = [];

        foreach ($destinations as $destination) {
            $bytes = [BackupSubject::Database->value => 0, BackupSubject::Volume->value => 0, BackupSubject::ControlPlane->value => 0];
            $last = null;

            foreach ($runs->where('destination_id', $destination->id) as $run) {
                // Base backups (point-in-time recovery) count as database data.
                $bytes[$run->subject === BackupSubject::BaseBackup ? BackupSubject::Database->value : $run->subject->value] += max(0, (int) $run->size_bytes);
                $at = $run->finished_at ?? $run->created_at;

                if ($at !== null && ($last === null || $at->greaterThan($last))) {
                    $last = $at;
                }
            }

            $out[] = new DestinationUsageData(
                $destination->public_id,
                $last,
                $bytes[BackupSubject::Database->value],
                $bytes[BackupSubject::Volume->value],
                $bytes[BackupSubject::ControlPlane->value],
            );
        }

        return $out;
    }

    private function lastSucceeded(BackupSchedule $schedule): ?BackupRun
    {
        return BackupRun::query()
            ->where('schedule_id', $schedule->id)
            ->where('status', BackupRunStatus::Succeeded->value)
            ->latest('id')
            ->first(['id', 'size_bytes', 'created_at', 'finished_at']);
    }
}
