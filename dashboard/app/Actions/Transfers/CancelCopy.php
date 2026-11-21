<?php

declare(strict_types=1);

namespace App\Actions\Transfers;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\Transfers\CopyRunStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\DaemonTask;
use App\Models\Transfers\DataCopyRun;
use App\Models\Transfers\DataImportRun;
use App\Models\Transfers\DataTransfer;
use App\Services\Tasks\TaskCancellation;
use Illuminate\Support\Facades\DB;

/**
 * Calls a copy off before its import task runs (docs/14 §14.2): while the
 * source is exported its task is cancelled; while the import waits for its
 * safety snapshot or for the apps to stop, the import is cancelled and the
 * apps start again. The target is unchanged either way.
 */
final readonly class CancelCopy
{
    public function __construct(
        private TaskCancellation $tasks,
        private CancelImport $cancelImport,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(DataCopyRun $run, AuditActor $actor): DataCopyRun
    {
        DB::transaction(function () use ($run, $actor): void {
            $locked = DataCopyRun::query()->whereKey($run->id)->with(['target.service', 'exportRun', 'importRun'])->lockForUpdate()->firstOrFail();

            if ($locked->status->isTerminal()) {
                throw DomainRuleViolation::conflict('This copy has already ended.');
            }

            $import = $locked->importRun;

            if ($locked->status === CopyRunStatus::Importing) {
                if (! $import instanceof DataImportRun || ! $import->status->isWaiting()) {
                    throw DomainRuleViolation::conflict('The data is already being loaded and can’t be stopped halfway. Wait for it to finish.');
                }

                $this->cancelImport->handle($import, $actor);
            } elseif ($locked->exportRun?->daemon_task_id !== null) {
                $task = DaemonTask::query()->find($locked->exportRun->daemon_task_id);

                if ($task instanceof DaemonTask) {
                    $this->tasks->cancel($task);
                }
            }

            $locked->transitionTo(CopyRunStatus::Cancelled);

            if ($locked->exportRun?->transfer_id !== null) {
                DataTransfer::query()->whereKey($locked->exportRun->transfer_id)->update(['expires_at' => now()]);
            }

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::DataCopyCancelled,
                actor: $actor,
                teamId: $locked->team_id,
                subjectType: 'service',
                subjectId: $locked->target->service->public_id,
                meta: ['copy' => $locked->public_id],
            ));
        });

        return $run->refresh();
    }
}
