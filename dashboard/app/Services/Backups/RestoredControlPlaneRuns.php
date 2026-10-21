<?php

declare(strict_types=1);

namespace App\Services\Backups;

use App\Enums\Backups\BackupRunStatus;
use App\Enums\Backups\BackupSubject;
use App\Models\Backups\BackupRun;

/**
 * After `launchroom:restore` (docs/14 §9.7): the database dump was taken
 * while its own control-plane run was in flight, so the restored database
 * holds that run as `running`, and StartControlPlaneBackup would hand every
 * later backup to it forever. The run that produced the restored snapshot
 * succeeded (its snapshot is what was just restored); any other run still
 * in flight in the restored data was interrupted by the loss of the old
 * control plane.
 */
final class RestoredControlPlaneRuns
{
    public const string INTERRUPTED = 'Interrupted: the control plane was restored from a backup.';

    /**
     * @return int number of runs settled
     */
    public static function settle(string $connection, string $restoredRun, string $databaseSnapshot): int
    {
        $runs = BackupRun::on($connection)
            ->where('subject', BackupSubject::ControlPlane->value)
            ->whereIn('status', [BackupRunStatus::Pending->value, BackupRunStatus::Running->value])
            ->get();

        foreach ($runs as $run) {
            if ($run->public_id === $restoredRun) {
                $run->snapshot_id ??= $databaseSnapshot;
                $run->transitionTo(BackupRunStatus::Succeeded);
            } else {
                $run->transitionTo(BackupRunStatus::Failed, self::INTERRUPTED);
            }
        }

        return $runs->count();
    }
}
