<?php

declare(strict_types=1);

namespace App\Jobs\Backups;

use App\Actions\Backups\RunControlPlaneBackup;
use App\Models\Backups\BackupRun;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Tries;

/**
 * Runs one control-plane backup off the request path (docs/10 §2.2 rule 8).
 * No retries: a failed run is recorded and the next schedule tries again.
 */
#[Tries(1)]
final class RunControlPlaneBackupJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout;

    public function __construct(
        public readonly int $runId,
    ) {
        $this->timeout = config()->integer('launchroom.backups.task_timeout_seconds');
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return (string) $this->runId;
    }

    public function handle(RunControlPlaneBackup $action): void
    {
        $run = BackupRun::query()->whereKey($this->runId)->whereNull('team_id')->first();

        if ($run instanceof BackupRun) {
            $action->handle($run);
        }
    }
}
