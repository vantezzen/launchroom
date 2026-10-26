<?php

declare(strict_types=1);

namespace App\Actions\Transfers;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Transfers\ImportInspectionData;
use App\Data\Transfers\Inputs\InspectImportInput;
use App\Enums\AuditAction;
use App\Enums\Transfers\DataSubject;
use App\Exceptions\DomainRuleViolation;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Services\Transfers\ImportInspections;
use App\Services\Transfers\ImportTarget;
use App\Services\Transfers\SourceUrl;
use App\Services\Transfers\TransferTasks;

/**
 * Looks at an import's target before anything is chosen (docs/14 §12.5): a
 * db_import / volume_import task with mode `inspect` (empty or not, size,
 * version, the volume root's owner). With a connection URL it also checks
 * the source answers; the URL is sealed to the server for that task only.
 */
final readonly class InspectImportTarget
{
    public function __construct(
        private TransferTasks $tasks,
        private ImportInspections $inspections,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(ServiceInstance $instance, InspectImportInput $input, User $user, AuditActor $actor): ImportInspectionData
    {
        $instance->loadMissing(['service', 'environment']);
        $target = ImportTarget::for($instance);

        // A stopped service runs nothing to import into (D68).
        if ($instance->stopped_at !== null) {
            throw DomainRuleViolation::on('service', 'This service is stopped. Start it, then import.');
        }

        if (! $target->importSupported()) {
            throw DomainRuleViolation::on('service', 'Importing into this service isn’t supported.');
        }

        if ($target->subject === DataSubject::Volume) {
            $volume = $target->volume($input->volume);
            $task = $this->tasks->inspectVolume($volume->server()->firstOrFail(), $instance->team_id, $volume->public_id);
        } else {
            $placement = $target->placement();
            $taskId = TransferTasks::reserveTaskId();
            $sealed = null;

            if ($input->sourceUrl !== null && $input->sourceUrl !== '') {
                $url = SourceUrl::parse($input->sourceUrl, $target->engine());
                $sealed = $this->tasks->sealSourceUrl($placement->server, $taskId, $url->url);
                $this->audit->handle(new AuditEntryData(
                    action: AuditAction::DataImportSourceChecked,
                    actor: $actor,
                    teamId: $instance->team_id,
                    subjectType: 'service',
                    subjectId: $instance->service->public_id,
                    meta: ['host' => $url->host, 'environment' => $instance->environment->public_id],
                ));
            }

            $task = $this->tasks->inspectDatabase($placement->server, $instance->team_id, $target->importEngine(), $placement->workload_id, $taskId, $sealed);
        }

        $this->inspections->remember($task, $instance, $user);

        return new ImportInspectionData($task->public_id, 'pending');
    }
}
