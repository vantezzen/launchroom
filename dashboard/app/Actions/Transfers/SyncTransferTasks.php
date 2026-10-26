<?php

declare(strict_types=1);

namespace App\Actions\Transfers;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\DaemonTaskStatus;
use App\Enums\DaemonTaskType;
use App\Enums\Transfers\DataSubject;
use App\Enums\Transfers\ExportRunStatus;
use App\Enums\Transfers\ImportRunStatus;
use App\Enums\Transfers\TransferStatus;
use App\Events\NodeStateInvalidated;
use App\Models\DaemonTask;
use App\Models\ServiceInstance;
use App\Models\Transfers\DataExportRun;
use App\Models\Transfers\DataImportRun;
use App\Models\Transfers\DataTransfer;
use App\Services\Backups\DataHolds;
use App\Services\Backups\RestoreHolds;
use App\Services\Transfers\ImportHolds;
use App\Services\Transfers\TransferStore;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Mirrors data import and export task outcomes onto their runs (docs/14
 * §12.5): right after a daemon completes a task, and for every in-flight run
 * from the transfers tick. Idempotent: terminal runs never change again.
 *
 * An import's end releases the services it held. An export is ready only
 * when the received chunks match the task's size, chunk count and digest;
 * otherwise it fails and its chunks are deleted.
 */
final readonly class SyncTransferTasks
{
    public function __construct(
        private TransferStore $store,
        private RecordAuditEvent $audit,
        private EndDatabasePreparation $endPreparation,
    ) {}

    public function handle(?DaemonTask $task = null): void
    {
        if ($task instanceof DaemonTask) {
            $this->sync($task);

            return;
        }

        $ids = [
            ...DataImportRun::query()->where('status', ImportRunStatus::Importing->value)->whereNotNull('daemon_task_id')->pluck('daemon_task_id')->all(),
            ...DataExportRun::query()->where('status', ExportRunStatus::Preparing->value)->whereNotNull('daemon_task_id')->pluck('daemon_task_id')->all(),
        ];

        foreach (DaemonTask::query()->whereIn('id', $ids)->get() as $daemonTask) {
            // One task that can't be mirrored must not stop the others, nor
            // the rest of the transfers tick (imports waiting to start).
            try {
                $this->sync($daemonTask);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    private function sync(DaemonTask $task): void
    {
        match ($task->type) {
            DaemonTaskType::DbImport, DaemonTaskType::VolumeImport => $this->import($task),
            DaemonTaskType::DbExport, DaemonTaskType::VolumeExport => $this->export($task),
            default => null,
        };
    }

    private function import(DaemonTask $task): void
    {
        $finished = DB::transaction(function () use ($task): ?ServiceInstance {
            $run = DataImportRun::query()->where('daemon_task_id', $task->id)->with(['serviceInstance.service', 'volume'])->lockForUpdate()->first();

            if (! $run instanceof DataImportRun || $run->status !== ImportRunStatus::Importing) {
                return null;
            }

            $held = ImportHolds::heldBy($run);

            if ($task->status === DaemonTaskStatus::Succeeded) {
                /** @var array<string, mixed> $result */
                $result = is_array($task->result['dataImport'] ?? null) ? $task->result['dataImport'] : [];
                $run->result = $result;
                $run->transitionTo(ImportRunStatus::Succeeded);
            } elseif ($task->status === DaemonTaskStatus::Failed || $task->status === DaemonTaskStatus::Cancelled) {
                $run->transitionTo(ImportRunStatus::Failed, $task->error ?? 'The import task '.$task->status->value.'.');
            } else {
                return null;
            }

            // The file stays for a retry until it expires.
            if ($run->transfer_id !== null) {
                DataTransfer::query()->whereKey($run->transfer_id)->update([
                    'last_used_at' => now(),
                    'expires_at' => now()->addHours(config()->integer('launchroom.transfers.upload_ttl_hours')),
                ]);
            }

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::DataImportFinished,
                actor: AuditActor::system(),
                teamId: $run->team_id,
                subjectType: 'service',
                subjectId: $run->serviceInstance->service->public_id,
                meta: array_filter([
                    'import' => $run->public_id,
                    'outcome' => $run->status->value,
                    'tables' => is_int($run->result['tables'] ?? null) ? $run->result['tables'] : null,
                    'files' => is_int($run->result['files'] ?? null) ? $run->result['files'] : null,
                    'sizeBytes' => is_int($run->result['sizeBytes'] ?? null) ? $run->result['sizeBytes'] : null,
                    'durationMs' => is_int($run->result['durationMs'] ?? null) ? $run->result['durationMs'] : null,
                ], static fn (mixed $value): bool => $value !== null),
            ));

            DataHolds::advanceDeployments($held);
            $servers = RestoreHolds::serverIds($held);

            if ($run->subject === DataSubject::Volume && $task->server_id !== null) {
                $servers[] = $task->server_id;
            }

            if ($servers !== []) {
                event(new NodeStateInvalidated(array_values(array_unique($servers))));
            }

            return $run->subject === DataSubject::Database ? $run->serviceInstance : null;
        });

        // A database started alone for this import stops again (docs/14 §12.9).
        if ($finished instanceof ServiceInstance) {
            $this->endPreparation->handle($finished);
        }
    }

    private function export(DaemonTask $task): void
    {
        $failed = DB::transaction(function () use ($task): ?DataTransfer {
            $run = DataExportRun::query()->where('daemon_task_id', $task->id)->with('transfer')->lockForUpdate()->first();

            if (! $run instanceof DataExportRun || $run->status !== ExportRunStatus::Preparing) {
                return null;
            }

            $transfer = $run->transfer === null ? null : DataTransfer::query()->whereKey($run->transfer->id)->lockForUpdate()->first();

            if ($task->status === DaemonTaskStatus::Failed || $task->status === DaemonTaskStatus::Cancelled || ! $transfer instanceof DataTransfer) {
                $run->transitionTo(ExportRunStatus::Failed, $task->error ?? 'The export task '.$task->status->value.'.');

                return $transfer;
            }

            if ($task->status !== DaemonTaskStatus::Succeeded) {
                return null;
            }

            /** @var array<string, mixed> $result */
            $result = is_array($task->result['dataExport'] ?? null) ? $task->result['dataExport'] : [];
            $error = $this->verify($transfer, $result);

            if ($error !== null) {
                $run->transitionTo(ExportRunStatus::Failed, $error);

                return $transfer;
            }

            $transfer->forceFill([
                'chunk_count' => is_int($result['chunks'] ?? null) ? $result['chunks'] : 0,
                'size_bytes' => is_int($result['sizeBytes'] ?? null) ? $result['sizeBytes'] : 0,
                'digest' => is_string($result['sha256'] ?? null) ? strtolower($result['sha256']) : null,
                'format' => is_string($result['format'] ?? null) ? $result['format'] : null,
                'compressed' => ($result['compressed'] ?? false) === true,
                'expires_at' => now()->addMinutes(config()->integer('launchroom.transfers.download_ttl_minutes')),
            ]);
            $transfer->transitionTo(TransferStatus::Ready);
            $run->transitionTo(ExportRunStatus::Ready);

            return null;
        });

        if ($failed instanceof DataTransfer) {
            if (! $failed->status->isTerminal()) {
                $failed->transitionTo(TransferStatus::Failed, 'The export didn’t finish.');
            }

            $this->store->delete($failed);
        }
    }

    /**
     * Why the received chunks don't match what launchroomd reported, or null.
     *
     * @param  array<string, mixed>  $result
     */
    private function verify(DataTransfer $transfer, array $result): ?string
    {
        $chunks = is_int($result['chunks'] ?? null) ? $result['chunks'] : -1;
        $size = is_int($result['sizeBytes'] ?? null) ? $result['sizeBytes'] : -1;
        $sha256 = is_string($result['sha256'] ?? null) ? $result['sha256'] : '';

        if ($chunks < 0 || $size < 0 || count($transfer->receivedChunks()) !== $chunks) {
            return 'The export arrived incomplete. Start it again.';
        }

        $lengths = $transfer->chunk_digests ?? [];

        for ($i = 0; $i < $chunks - 1; $i++) {
            if (! isset($lengths[$i]) || $lengths[$i]['n'] !== $transfer->chunk_bytes) {
                return 'The export arrived incomplete. Start it again.';
            }
        }

        $digest = $transfer->digestOver($chunks);

        if ($transfer->lengthOver($chunks) !== $size || $digest === null || ! hash_equals($digest, strtolower($sha256))) {
            return 'The export arrived damaged. Start it again.';
        }

        return null;
    }
}
