<?php

declare(strict_types=1);

namespace App\Actions\Transfers;

use App\Data\Services\DatabaseSource;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\Transfers\DataSubject;
use App\Enums\Transfers\ImportRunStatus;
use App\Enums\Transfers\TransferStatus;
use App\Models\Placement;
use App\Models\Server;
use App\Models\Transfers\DataImportRun;
use App\Models\Transfers\DataTransfer;
use App\Models\Volume;
use App\Services\Backups\RestoreHolds;
use App\Services\Transfers\DatabasePreparations;
use App\Services\Transfers\ImportHolds;
use App\Services\Transfers\ImportTarget;
use App\Services\Transfers\TransferTasks;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Moves imports forward (docs/14 §12.5), from the transfers tick and right
 * after a run starts or its safety snapshot finishes:
 *
 * - waiting_for_safety_backup → holding once the snapshot succeeded (or none
 *   was needed); a failed snapshot fails the run and nothing changes;
 * - holding: the services that use the data leave their servers' desired
 *   state ({@see ImportHolds}); once they stopped the task is created →
 *   importing. A volume import goes ahead after `restore_hold_wait_seconds`
 *   (launchroomd itself waits until nothing uses the volume); a database
 *   import fails after `transfers.hold_timeout_seconds` naming the services
 *   that still run, and so does any run that couldn't start by then, so a
 *   run never stays `holding` (the services start again);
 * - importing → succeeded/failed comes from the task ({@see SyncTransferTasks}).
 */
final readonly class AdvanceImports
{
    public function __construct(
        private TransferTasks $tasks,
        private FailStuckImports $stuck,
    ) {}

    public function handle(): int
    {
        $advanced = 0;

        /** @var list<int> $ids */
        $ids = DataImportRun::query()
            ->whereIn('status', [ImportRunStatus::WaitingForSafetyBackup->value, ImportRunStatus::Holding->value])
            ->orderBy('id')
            ->pluck('id')
            ->all();

        foreach ($ids as $id) {
            try {
                $advanced += $this->advance($id) ? 1 : 0;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $advanced + $this->stuck->handle();
    }

    private function advance(int $id): bool
    {
        return DB::transaction(function () use ($id): bool {
            $run = DataImportRun::query()->whereKey($id)->with(['safetyBackup', 'transfer', 'volume.server', 'serviceInstance.service'])->lockForUpdate()->first();

            if (! $run instanceof DataImportRun) {
                return false;
            }

            if ($run->status === ImportRunStatus::WaitingForSafetyBackup) {
                $safety = $run->safetyBackup;

                if ($safety !== null && $safety->status === BackupRunStatus::Failed) {
                    $run->transitionTo(ImportRunStatus::Failed, 'The safety snapshot failed, so nothing was imported. The data is unchanged.');

                    return true;
                }

                if ($safety !== null && $safety->status !== BackupRunStatus::Succeeded) {
                    return false;
                }

                $run->transitionTo(ImportRunStatus::Holding);
                ImportHolds::release($run, ImportHolds::heldBy($run));
                // Nothing to wait for (no held services): start right away.
                $this->start($run);

                return true;
            }

            return $this->start($run);
        });
    }

    /**
     * Creates the task once the held services have let go.
     */
    private function start(DataImportRun $run): bool
    {
        $held = ImportHolds::heldBy($run);
        $wait = config()->integer('launchroom.backups.restore_hold_wait_seconds');
        $waited = $run->holding_since === null || $run->holding_since->lt(now()->subSeconds($wait));
        $instance = $run->serviceInstance;

        if ($run->transfer_id !== null && $run->transfer?->status !== TransferStatus::Ready) {
            return $this->fail($run, $held, 'The uploaded file is gone. Upload it again.');
        }

        // Never import long after the hold began (a run that got stuck, for
        // example before an update): fail it, the services start again.
        if (ImportHolds::overdue($run)) {
            return $this->fail($run, $held, ImportHolds::overdueReason($run, $run->subject === DataSubject::Database ? RestoreHolds::running($held) : []));
        }

        if ($run->subject === DataSubject::Volume) {
            $volume = $run->volume;

            if (! $volume instanceof Volume || ! $volume->server instanceof Server || ! $run->transfer instanceof DataTransfer) {
                return $this->fail($run, $held, 'The volume to import into no longer exists.');
            }

            // launchroomd waits until nothing uses the volume; give the held service a moment first.
            if (! RestoreHolds::released($held) && ! $waited) {
                return false;
            }

            $task = $this->tasks->importVolume(
                $volume->server,
                $run->team_id,
                $volume->public_id,
                $run->transfer,
                $run->replace,
                $run->strip_components,
                $run->owner_uid !== null && $run->owner_gid !== null ? ['uid' => $run->owner_uid, 'gid' => $run->owner_gid] : null,
            );
        } else {
            $placement = DatabasePreparations::importPlacement($instance);
            $target = ImportTarget::for($instance);

            if (! $placement instanceof Placement || ! $target->database instanceof DatabaseSource) {
                if ($waited) {
                    return $this->fail($run, $held, "{$instance->service->name} stopped before the import could start. Nothing was imported.");
                }

                return false;
            }

            if ($run->sealed_source !== null && $placement->server_id !== $run->server_id) {
                return $this->fail($run, $held, "{$instance->service->name} moved to another server. Start the import again.");
            }

            // Wait for every held service to stop, a failed or never
            // started one counts as stopped; {@see FailStuckImports} ends the wait.
            if (! RestoreHolds::released($held)) {
                return false;
            }

            $task = $this->tasks->importDatabase(
                $placement->server,
                $run->team_id,
                $target->importEngine(),
                $placement->workload_id,
                $run->sealed_source === null ? $run->transfer : null,
                $run->sealed_source,
                $run->replace,
                $run->sealed_source === null ? null : $run->reserved_task_id,
            );
        }

        $run->daemon_task_id = $task->id;
        $run->server_id = $task->server_id;
        // The sealed URL now lives only in the task's spec.
        $run->sealed_source = null;
        $run->transitionTo(ImportRunStatus::Importing);

        return true;
    }

    /**
     * @param  list<int>  $held
     */
    private function fail(DataImportRun $run, array $held, string $error): bool
    {
        $run->transitionTo(ImportRunStatus::Failed, $error);
        ImportHolds::release($run, $held);

        return true;
    }
}
