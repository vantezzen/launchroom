<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Backups\DestinationUsageData;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\Backups\BackupSubject;
use App\Models\Backups\BackupDestination;
use App\Models\Backups\BackupRun;
use Illuminate\Support\Facades\DB;

/**
 * What each destination holds (the Backups page's storage card,
 * docs/design/flows.md §14): the newest snapshot of everything stored there
 * by kind. DAYS is the run strip's length (App\Queries\Backups\BackupsPage).
 */
final class BackupOverview
{
    public const int DAYS = 30;

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
}
