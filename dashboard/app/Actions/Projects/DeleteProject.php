<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Actions\Alerts\DetachAlerts;
use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Environments\TearDownInstances;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Backups\DeletionSubject;
use App\Data\Projects\DeleteProjectData;
use App\Enums\AuditAction;
use App\Enums\Backups\DeletionOutcome;
use App\Exceptions\DomainRuleViolation;
use App\Models\Backups\DeletionRequest;
use App\Models\Project;
use App\Models\ServiceInstance;
use App\Queries\ProjectBackupFootprint;
use App\Services\Projects\ProjectIconStore;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a project with everything in it (docs/08 §2 "danger"): the typed
 * name must match (`confirmName`; `$data = null` continues a deletion whose
 * final backups finished, called by the backup tick), then
 * {@see TearDownInstances} (final backups, refused while a backup or restore
 * runs, teardown through desired state, volume retirement) and the rows go
 * (foreign keys cascade). Snapshots stay in the destination; the schedules
 * (and their retention) end with the project.
 */
final readonly class DeleteProject
{
    public function __construct(
        private TearDownInstances $tearDown,
        private RecordAuditEvent $audit,
        private ProjectBackupFootprint $backups,
        private ProjectIconStore $icons,
        private DetachAlerts $alerts,
    ) {}

    /**
     * @throws DomainRuleViolation for a wrong typed name
     */
    public function handle(Project $project, ?DeleteProjectData $data, AuditActor $actor): DeletionOutcome
    {
        if ($data instanceof DeleteProjectData && trim($data->confirmName) !== $project->name) {
            throw DomainRuleViolation::on('confirmName', "Type {$project->name} to confirm.");
        }

        $outcome = DB::transaction(function () use ($project, $data, $actor): DeletionOutcome {
            $project = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $instances = ServiceInstance::query()
                ->whereIn('environment_id', $project->environments()->select('id'))
                ->with(['service', 'volumes'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $footprint = $this->backups->for($project);

            return $this->tearDown->handle(
                DeletionSubject::project($project),
                $instances,
                $actor,
                $data instanceof DeleteProjectData && $data->skipFinalBackup,
                $footprint['inFlight'],
                'project_deleted',
                function (?DeletionRequest $request) use ($project, $instances, $actor, $footprint): void {
                    $this->audit->handle(new AuditEntryData(
                        action: AuditAction::ProjectDeleted,
                        actor: $actor,
                        teamId: $project->team_id,
                        subjectType: 'project',
                        subjectId: $project->public_id,
                        meta: [
                            'name' => $project->name,
                            'environments' => $project->environments()->count(),
                            'services' => $project->services()->count(),
                            'servicesWithBackups' => $footprint['servicesWithBackups'],
                            'finalBackup' => $request?->status->value,
                        ],
                    ));

                    // Alerts let go first: the cascade would trip over them (DetachAlerts).
                    /** @var list<int> $environmentIds */
                    $environmentIds = $project->environments()->pluck('id')->all();
                    $this->alerts->handle('The project was deleted.', $project->id, $environmentIds, $instances->modelKeys());
                    $project->delete();
                },
            );
        });

        if ($outcome === DeletionOutcome::Deleted) {
            $this->icons->deleteAll($project);
        }

        return $outcome;
    }
}
