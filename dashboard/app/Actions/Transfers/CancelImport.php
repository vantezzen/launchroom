<?php

declare(strict_types=1);

namespace App\Actions\Transfers;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\Transfers\ImportRunStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Transfers\DataImportRun;
use App\Services\Transfers\ImportHolds;
use Illuminate\Support\Facades\DB;

/**
 * Cancels an import before its task starts (docs/14 §12.5): while the safety
 * snapshot runs or the services that use the data are stopping. Nothing was
 * imported; the held services start again and the uploaded file stays for
 * another try. Once launchroomd imports, the run can't be stopped halfway.
 */
final readonly class CancelImport
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(DataImportRun $run, AuditActor $actor): DataImportRun
    {
        DB::transaction(function () use ($run, $actor): void {
            $locked = DataImportRun::query()->whereKey($run->id)->with(['serviceInstance.service', 'volume'])->lockForUpdate()->firstOrFail();

            if ($locked->status === ImportRunStatus::Importing) {
                throw DomainRuleViolation::conflict('The import already started on the server and can’t be stopped halfway. Wait for it to finish.');
            }

            if (! $locked->status->canTransitionTo(ImportRunStatus::Cancelled)) {
                throw DomainRuleViolation::conflict('This import has already ended.');
            }

            $held = $locked->status->holds() ? ImportHolds::heldBy($locked) : [];
            $locked->transitionTo(ImportRunStatus::Cancelled);

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::DataImportCancelled,
                actor: $actor,
                teamId: $locked->team_id,
                subjectType: 'service',
                subjectId: $locked->serviceInstance->service->public_id,
                meta: ['import' => $locked->public_id],
            ));

            ImportHolds::release($locked, $held);
        });

        return $run->refresh();
    }
}
