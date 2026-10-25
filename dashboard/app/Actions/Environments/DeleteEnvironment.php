<?php

declare(strict_types=1);

namespace App\Actions\Environments;

use App\Actions\Alerts\DetachAlerts;
use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Environments\DeleteEnvironmentData;
use App\Enums\ActorType;
use App\Enums\AuditAction;
use App\Enums\Backups\DeletionOutcome;
use App\Enums\Backups\DeletionRequestStatus;
use App\Enums\EnvironmentKind;
use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Events\NodeStateInvalidated;
use App\Exceptions\DomainRuleViolation;
use App\Models\Backups\DeletionRequest;
use App\Models\Environment;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Models\Variable;
use App\Queries\ProjectBackupFootprint;
use App\Services\Backups\FinalBackups;
use App\Services\Deployments\InstanceTeardown;
use App\Services\Volumes\VolumeRetirements;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a persistent or preview environment (never production) like
 * DeleteProject does a project:
 *
 * - the typed name must match; refused while a backup or restore of one of
 *   its services runs;
 * - final backups first (docs/14 §9.13, {@see FinalBackups}): databases and
 *   services with volumes that have backups configured are backed up while
 *   they still run. The environment stays until they succeeded (the backup
 *   tick then calls this again with `$data = null`); a failure blocks the
 *   deletion until it is retried or overridden (`skipFinalBackup`, audited);
 * - teardown goes through desired state (InstanceTeardown: workloads leave
 *   the NodeStates, edges drop the routes), then the rows go: instances,
 *   deployments, revisions and promotions cascade; variables that only
 *   applied here are deleted, other rows just lose this target; services that
 *   existed only here are deleted. Volumes stay on the servers for the
 *   retention period, then are deleted explicitly (VolumeRetirements).
 *
 * Reusable by previews (closing a pull request) with `$data = null` (no typed confirmation).
 */
final readonly class DeleteEnvironment
{
    public function __construct(
        private InstanceTeardown $teardown,
        private RecordAuditEvent $audit,
        private ProjectBackupFootprint $backups,
        private VolumeRetirements $retirements,
        private FinalBackups $finalBackups,
        private DetachAlerts $alerts,
    ) {}

    /**
     * @throws DomainRuleViolation
     */
    public function handle(Environment $environment, ?DeleteEnvironmentData $data, AuditActor $actor): DeletionOutcome
    {
        if ($environment->kind === EnvironmentKind::Production) {
            throw DomainRuleViolation::on('environment', 'Production can’t be deleted. Delete the project instead.');
        }

        if ($data instanceof DeleteEnvironmentData && trim($data->confirmName) !== $environment->name) {
            throw DomainRuleViolation::on('confirmName', "Type {$environment->name} to confirm.");
        }

        [$outcome, $servers] = DB::transaction(function () use ($environment, $data, $actor): array {
            $environment = Environment::query()->whereKey($environment->id)->lockForUpdate()->firstOrFail();
            $instances = ServiceInstance::query()->where('environment_id', $environment->id)->with(['service', 'volumes'])->orderBy('id')->lockForUpdate()->get();
            $user = $actor->type === ActorType::User && $actor->id !== null ? User::query()->find($actor->id) : null;

            $request = $this->finalBackups->gate(
                DeletionRequest::ENVIRONMENT,
                $environment->id,
                $environment->public_id,
                $environment->name,
                $environment->team_id,
                $instances,
                $user,
                $actor,
                $data instanceof DeleteEnvironmentData && $data->skipFinalBackup,
            );

            if ($request instanceof DeletionRequest && ! $request->status->allowsDeletion()) {
                return [DeletionOutcome::forStatus($request->status), []];
            }

            if ($this->backups->forEnvironment($environment)['inFlight']) {
                throw DomainRuleViolation::on('environment', 'A backup or restore of a service in this environment is running. Wait for it to finish, then delete the environment.');
            }

            $servers = [];

            foreach ($instances as $instance) {
                $servers = [...$servers, ...$this->teardown->handle($instance)];
            }

            // Volumes stay on their servers for the retention period, then
            // are deleted explicitly (docs/05 §1.1); backed-up ones once their
            // final backup exists or the deletion was overridden.
            $this->retirements->retire($instances, 'environment_deleted', $this->finalBackups->runsByVolume($request), $request?->status === DeletionRequestStatus::Overridden, $request?->overridden_by);

            /** @var list<int> $edges */
            $edges = Server::query()
                ->where('team_id', $environment->team_id)
                ->whereIn('status', [ServerStatus::Online->value, ServerStatus::Unreachable->value, ServerStatus::Offline->value])
                ->whereJsonContains('roles', ServerRole::Edge->value)
                ->pluck('id')
                ->all();

            // Rows whose only target is this environment go with it.
            $onlyHere = Variable::query()
                ->where('project_id', $environment->project_id)
                ->whereNull('connection_id')
                ->whereHas('targets', static fn (Builder $query) => $query->where('environment_id', $environment->id))
                ->whereDoesntHave('targets', static fn ($query) => $query->where(static fn ($other) => $other->whereNull('environment_id')->orWhere('environment_id', '!=', $environment->id)))
                ->pluck('id')
                ->all();

            $serviceIds = $instances->pluck('service_id')->all();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::EnvironmentDeleted,
                actor: $actor,
                teamId: $environment->team_id,
                subjectType: 'environment',
                subjectId: $environment->public_id,
                meta: ['name' => $environment->name, 'kind' => $environment->kind->value, 'services' => count($serviceIds)],
            ));

            Variable::query()->whereKey($onlyHere)->delete();
            Environment::query()->where('base_environment_id', $environment->id)->update(['base_environment_id' => null]);
            // Alerts let go first: the cascade would trip over them (DetachAlerts).
            $this->alerts->handle('The environment was deleted.', null, [$environment->id], $instances->modelKeys());
            $environment->delete();

            // Services that only existed here (added in this environment).
            Service::query()->whereKey($serviceIds)->whereDoesntHave('instances')->delete();

            $request?->transitionTo(DeletionRequestStatus::Completed);

            return [DeletionOutcome::Deleted, array_values(array_unique([...$servers, ...$edges]))];
        });

        if ($servers !== []) {
            event(new NodeStateInvalidated($servers));
        }

        return $outcome;
    }
}
