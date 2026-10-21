<?php

declare(strict_types=1);

namespace App\Console\Commands\Backups;

use App\Actions\Backups\RunControlPlaneBackup;
use App\Actions\Backups\StartControlPlaneBackup;
use App\Data\Audit\AuditActor;
use App\Enums\Backups\BackupReason;
use App\Enums\Backups\BackupRunStatus;
use App\Exceptions\DomainRuleViolation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Control-plane backup on demand. The upgrade flow calls
 * `launchroom:backups:control-plane --reason=pre_upgrade --wait` before
 * replacing compose files (docs/11 §4, docs/14 §3) and aborts on a non-zero
 * exit code. Without --wait the backup is queued for Horizon.
 */
#[Signature('launchroom:backups:control-plane {--reason=manual : manual|pre_upgrade} {--wait : Run in this process and exit non-zero on failure} {--json : Print the run as JSON}')]
#[Description('Back up the control plane (database + sealed keys) to its destination')]
final class ControlPlaneBackupCommand extends Command
{
    public function handle(StartControlPlaneBackup $start, RunControlPlaneBackup $run): int
    {
        $option = $this->option('reason');
        $reason = BackupReason::tryFrom(is_string($option) ? $option : '');

        if (! in_array($reason, [BackupReason::Manual, BackupReason::PreUpgrade], true)) {
            $this->error('--reason must be manual or pre_upgrade.');

            return self::INVALID;
        }

        $wait = (bool) $this->option('wait');

        try {
            $backup = $start->handle($reason, null, AuditActor::system(), dispatch: ! $wait);
        } catch (DomainRuleViolation $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($wait) {
            $backup = $run->handle($backup);
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'run' => $backup->public_id,
                'status' => $backup->status->value,
                'snapshot' => $backup->snapshot_id,
                'sizeBytes' => $backup->size_bytes,
                'error' => $backup->error,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } elseif ($backup->status === BackupRunStatus::Succeeded) {
            $this->info("Control-plane backup {$backup->public_id} stored as snapshot ".substr((string) $backup->snapshot_id, 0, 8).'.');
        } elseif ($backup->status === BackupRunStatus::Failed) {
            $this->error("Control-plane backup failed: {$backup->error}");
        } else {
            $this->info("Control-plane backup {$backup->public_id} queued.");
        }

        return $backup->status === BackupRunStatus::Failed ? self::FAILURE : self::SUCCESS;
    }
}
