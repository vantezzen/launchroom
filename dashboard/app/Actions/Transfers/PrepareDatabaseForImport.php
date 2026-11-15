<?php

declare(strict_types=1);

namespace App\Actions\Transfers;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Specs\ServiceSpec;
use App\Enums\AuditAction;
use App\Exceptions\DomainRuleViolation;
use App\Exceptions\UnresolvedReferences;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Queries\CurrentEnvironmentSpec;
use App\Services\Deployments\DeploymentFactory;
use App\Services\Environments\EnvironmentRevisions;
use App\Services\Transfers\DatabasePreparations;
use App\Services\Variables\SpecVariableResolver;
use Illuminate\Support\Facades\DB;

/**
 * Starts a database that was never deployed on its own, so data can be
 * imported before the first deploy (docs/14 §12.9): a staged deployment of
 * only this service from the current configuration
 * ({@see DeploymentFactory::prepareImport()}). It is placed like any deployment (its volume gets
 * its server), runs with the credentials its apps will get and stops in
 * `ready`. The revision it snapshots is not applied: the environment stays
 * undeployed and no app starts. Calling it again while it runs keeps it
 * waiting for the import ({@see DatabasePreparations::LEASE_SECONDS});
 * {@see EndDatabasePreparation} stops it.
 */
final readonly class PrepareDatabaseForImport
{
    public function __construct(
        private CurrentEnvironmentSpec $currentSpec,
        private SpecVariableResolver $resolver,
        private DeploymentFactory $factory,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(ServiceInstance $instance, User $user, AuditActor $actor): Deployment
    {
        return DB::transaction(function () use ($instance, $user, $actor): Deployment {
            $instance = ServiceInstance::query()->whereKey($instance->id)->with('service')->lockForUpdate()->firstOrFail();
            $until = now()->addSeconds(DatabasePreparations::LEASE_SECONDS);
            $current = DatabasePreparations::current($instance);

            if ($current instanceof Deployment) {
                $current->forceFill(['prepared_until' => $until])->save();

                return $current;
            }

            if (! DatabasePreparations::preparable($instance)) {
                throw DomainRuleViolation::on('service', "{$instance->service->name} is deployed already or being deployed. Import into it as usual.");
            }

            // The environment's lock orders revision numbers like a deploy does.
            $environment = Environment::query()->whereKey($instance->environment_id)->with('project')->lockForUpdate()->firstOrFail();
            $spec = $this->currentSpec->get($environment);
            $service = $spec->service($instance->service->public_id);

            if (! $service instanceof ServiceSpec || ! $service->enabled) {
                throw DomainRuleViolation::on('service', "{$instance->service->name} is turned off in this environment.");
            }

            try {
                $env = $this->resolver->resolve($environment->project, $spec, $service);
            } catch (UnresolvedReferences $e) {
                throw $e->toDomainRuleViolation();
            }

            // A snapshot for this deployment only: not the environment's applied revision.
            $revision = EnvironmentRevisions::append($environment, $spec, "Started {$instance->service->name} alone to import data before the first deploy", $user->id);
            $deployment = $this->factory->prepareImport($environment->project, $instance, $revision, $service, $env, $until, $user);

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::DataImportPrepared,
                actor: $actor,
                teamId: $instance->team_id,
                subjectType: 'service',
                subjectId: $instance->service->public_id,
                meta: ['deployment' => $deployment->public_id, 'environment' => $environment->public_id],
            ));

            return $deployment;
        });
    }
}
