<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\Backups\BackupRunStatus;
use App\Enums\Backups\BackupSubject;
use App\Models\Backups\BackupDestination;
use App\Models\Backups\BackupRun;
use Illuminate\Database\Eloquent\Builder;

/**
 * Control-plane backups an update can use instead of taking a new one
 * (docs/11 §4.1): the latest succeeded backup on the current control-plane
 * destination, at any age (the owner judges it), or the backup running
 * right now, which the update waits for.
 */
final readonly class PreUpgradeBackups
{
    /** Past the task timeout plus this grace a running backup is dead (as in StartControlPlaneBackup). */
    private const int STALE_GRACE_SECONDS = 600;

    public function latest(BackupDestination $destination): ?BackupRun
    {
        return $this->controlPlane($destination)
            ->where('status', BackupRunStatus::Succeeded->value)
            ->whereNotNull('finished_at')
            ->latest('finished_at')
            ->latest('id')
            ->first();
    }

    public function running(BackupDestination $destination): ?BackupRun
    {
        $run = $this->controlPlane($destination)
            ->whereIn('status', BackupRunStatus::nonTerminal())
            ->latest('id')
            ->first();

        return $run instanceof BackupRun && ! $this->stale($run) ? $run : null;
    }

    /**
     * The run with public id $id when it can stand in for the pre-upgrade
     * backup (a succeeded or still running control-plane backup on
     * $destination), else why not.
     */
    public function usable(string $id, BackupDestination $destination): BackupRun|string
    {
        $run = $this->controlPlane($destination)->where('public_id', $id)->first();

        if (! $run instanceof BackupRun) {
            return 'That backup can’t be used for the update. Take a new backup instead.';
        }

        if ($run->status === BackupRunStatus::Failed) {
            return 'That backup failed. Take a new backup instead.';
        }

        if ($this->stale($run)) {
            return 'That backup stopped without finishing. Take a new backup instead.';
        }

        return $run;
    }

    /**
     * @return Builder<BackupRun>
     */
    private function controlPlane(BackupDestination $destination): Builder
    {
        return BackupRun::query()
            ->where('subject', BackupSubject::ControlPlane->value)
            ->whereNull('team_id')
            ->where('destination_id', $destination->id);
    }

    private function stale(BackupRun $run): bool
    {
        return ! $run->status->isTerminal()
            && ($run->abandoned() || (($run->started_at ?? $run->created_at)?->lt(now()->subSeconds(config()->integer('launchroom.backups.task_timeout_seconds') + self::STALE_GRACE_SECONDS)) ?? false));
    }
}
