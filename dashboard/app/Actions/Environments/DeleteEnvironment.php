<?php

declare(strict_types=1);

namespace App\Actions\Environments;

use App\Actions\Alerts\DetachAlerts;
use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Backups\DeletionSubject;
use App\Data\Environments\DeleteEnvironmentData;
use App\Enums\AuditAction;
use App\Enums\Backups\DeletionOutcome;
use App\Enums\EnvironmentKind;
use App\Exceptions\DomainRuleViolation;
use App\Models\Environment;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\Variable;
use App\Queries\ProjectBackupFootprint;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a persistent or preview environment (never production) like
 * DeleteProject does a project: the typed name must match (`$data = null`
 * continues a deletion whose final backups finished, and is how previews
 * close), then {@see TearDownInstances} (final backups, refused while a
 * backup or restore runs, teardown through desired state, volume
 * retirement). The rows go: instances, deployments, revisions and
 * promotions cascade; variables that only applied here are deleted, other
 * rows just lose this target; services that existed only here are deleted.
 */
final readonly class DeleteEnvironment
{
    public function __construct(
        private TearDownInstances $tearDown,
        private RecordAuditEvent $audit,
        private ProjectBackupFootprint $backups,
        private DetachAlerts $alerts,
    ) {}

    /**
     * @throws DomainRuleViolation for production or a wrong typed name
     */
    public function handle(Environment $environment, ?DeleteEnvironmentData $data, AuditActor $actor): DeletionOutcome
    {
        if ($environment->kind === EnvironmentKind::Production) {
            throw DomainRuleViolation::on('environment', 'Production can’t be deleted. Delete the project instead.');
        }

        if ($data instanceof DeleteEnvironmentData && trim($data->confirmName) !== $environment->name) {
            throw DomainRuleViolation::on('confirmName', "Type {$environment->name} to confirm.");
        }

        return DB::transaction(function () use ($environment, $data, $actor): DeletionOutcome {
            $environment = Environment::query()->whereKey($environment->id)->lockForUpdate()->firstOrFail();
            $instances = ServiceInstance::query()->where('environment_id', $environment->id)->with(['service', 'volumes'])->orderBy('id')->lockForUpdate()->get();

            return $this->tearDown->handle(
                DeletionSubject::environment($environment),
                $instances,
                $actor,
                $data instanceof DeleteEnvironmentData && $data->skipFinalBackup,
                $this->backups->forEnvironment($environment)['inFlight'],
                'environment_deleted',
                function () use ($environment, $instances, $actor): void {
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
                },
            );
        });
    }
}
