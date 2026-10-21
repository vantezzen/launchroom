<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Projects\DeleteProjectData;
use App\Enums\ActorType;
use App\Enums\AuditAction;
use App\Enums\Backups\DeletionOutcome;
use App\Enums\Backups\DeletionRequestStatus;
use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Events\NodeStateInvalidated;
use App\Exceptions\DomainRuleViolation;
use App\Models\Backups\DeletionRequest;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Queries\ProjectBackupFootprint;
use App\Services\Backups\FinalBackups;
use App\Services\Deployments\InstanceTeardown;
use App\Services\Projects\ProjectIconStore;
use App\Services\Volumes\VolumeRetirements;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a project with everything in it (docs/08 §2 "danger").
 *
 * - The typed name must match (`confirmName`; `$data = null` continues a
 *   deletion whose final backups finished, called by the backup tick).
 * - Final backups first (docs/14 §9.13, {@see FinalBackups}): databases and
 *   services with volumes that have backups configured are backed up while
 *   they still run; the project stays until they succeeded. A failure blocks
 *   the deletion until it is retried or overridden (`skipFinalBackup`,
 *   audited). Snapshots stay in the destination; the schedules (and their
 *   retention) end with the project.
 * - Refused while another backup or restore of one of its services runs.
 * - Teardown goes through desired state: every instance's workloads leave
 *   the NodeState (InstanceTeardown), the edges drop the routes, then the
 *   rows are deleted (foreign keys cascade). Volumes stay on the servers
 *   for the retention period and are then deleted through an explicit
 *   NodeState request (App\Services\Volumes\VolumeRetirements, docs/05 §1.1).
 */
final readonly class DeleteProject
{
    public function __construct(
        private InstanceTeardown $teardown,
        private RecordAuditEvent $audit,
        private ProjectBackupFootprint $backups,
        private VolumeRetirements $retirements,
        private FinalBackups $finalBackups,
        private ProjectIconStore $icons,
    ) {}

    /**
     * @throws DomainRuleViolation
     */
    public function handle(Project $project, ?DeleteProjectData $data, AuditActor $actor): DeletionOutcome
    {
        if ($data instanceof DeleteProjectData && trim($data->confirmName) !== $project->name) {
            throw DomainRuleViolation::on('confirmName', "Type {$project->name} to confirm.");
        }

        [$outcome, $servers] = DB::transaction(function () use ($project, $data, $actor): array {
            $project = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $instances = ServiceInstance::query()
                ->whereIn('environment_id', $project->environments()->select('id'))
                ->with(['service', 'volumes'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $user = $actor->type === ActorType::User && $actor->id !== null ? User::query()->find($actor->id) : null;

            $request = $this->finalBackups->gate(
                DeletionRequest::PROJECT,
                $project->id,
                $project->public_id,
                $project->name,
                $project->team_id,
                $instances,
                $user,
                $actor,
                $data instanceof DeleteProjectData && $data->skipFinalBackup,
            );

            if ($request instanceof DeletionRequest && ! $request->status->allowsDeletion()) {
                return [DeletionOutcome::forStatus($request->status), []];
            }

            $footprint = $this->backups->for($project);

            if ($footprint['inFlight']) {
                throw DomainRuleViolation::on('project', 'A backup or restore of a service in this project is running. Wait for it to finish, then delete the project.');
            }

            $backedUp = $footprint['servicesWithBackups'];
            $servers = [];

            foreach ($instances as $instance) {
                $servers = [...$servers, ...$this->teardown->handle($instance)];
            }

            // Volumes stay on their servers for the retention period, then
            // are deleted explicitly (docs/05 §1.1); backed-up ones once their
            // final backup exists or the deletion was overridden.
            $this->retirements->retire($instances, 'project_deleted', $this->finalBackups->runsByVolume($request), $request?->status === DeletionRequestStatus::Overridden, $request?->overridden_by);

            // Edges drop the project's routes.
            /** @var list<int> $edges */
            $edges = Server::query()
                ->where('team_id', $project->team_id)
                ->whereIn('status', [ServerStatus::Online->value, ServerStatus::Unreachable->value, ServerStatus::Offline->value])
                ->whereJsonContains('roles', ServerRole::Edge->value)
                ->pluck('id')
                ->all();

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
                    'servicesWithBackups' => $backedUp,
                    'finalBackup' => $request?->status->value,
                ],
            ));

            $project->delete();
            $request?->transitionTo(DeletionRequestStatus::Completed);

            return [DeletionOutcome::Deleted, array_values(array_unique([...$servers, ...$edges]))];
        });

        if ($servers !== []) {
            event(new NodeStateInvalidated($servers));
        }

        if ($outcome === DeletionOutcome::Deleted) {
            $this->icons->deleteAll($project);
        }

        return $outcome;
    }
}
