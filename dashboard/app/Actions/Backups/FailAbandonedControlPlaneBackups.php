<?php

declare(strict_types=1);

namespace App\Actions\Backups;

use App\Actions\Updates\AdvanceUpdates;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\Backups\BackupSubject;
use App\Jobs\Backups\RunControlPlaneBackupJob;
use App\Models\Backups\BackupRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Control-plane backups run inside a dashboard worker (docs/14 §2). When
 * that worker dies (container restart, out of memory, a killed process),
 * the run would stay `running` forever. Every minute
 * ({@see AdvanceUpdates}):
 *
 * - a run without a heartbeat for `control_plane_stall_seconds` (or still
 *   pending past the backup timeout) fails as abandoned
 *   ({@see BackupRun::abandoned()}), so the next backup or a waiting update
 *   can go on (docs/11 §4.3);
 * - a run still pending after 10 minutes gets its job dispatched again (the
 *   job's unique lock drops the copy while the first one is still queued).
 */
final class FailAbandonedControlPlaneBackups
{
    private const int REDISPATCH_AFTER_SECONDS = 600;

    public function handle(): int
    {
        $redispatch = [];

        $failed = DB::transaction(function () use (&$redispatch): int {
            $runs = BackupRun::query()
                ->where('subject', BackupSubject::ControlPlane->value)
                ->whereNull('team_id')
                ->whereIn('status', BackupRunStatus::nonTerminal())
                ->lock('for update skip locked')
                ->get();

            $failed = 0;

            foreach ($runs as $run) {
                if ($run->abandoned()) {
                    Log::warning('Control-plane backup abandoned: its worker stopped.', ['run' => $run->public_id, 'heartbeat' => $run->heartbeat_at?->toIso8601String()]);
                    $run->transitionTo(BackupRunStatus::Failed, BackupRun::ABANDONED);
                    $failed++;

                    continue;
                }

                if ($run->status === BackupRunStatus::Pending && ($run->created_at?->lt(now()->subSeconds(self::REDISPATCH_AFTER_SECONDS)) ?? false)) {
                    $redispatch[] = $run->id;
                }
            }

            return $failed;
        });

        foreach ($redispatch as $id) {
            dispatch(new RunControlPlaneBackupJob($id));
        }

        return $failed;
    }
}
