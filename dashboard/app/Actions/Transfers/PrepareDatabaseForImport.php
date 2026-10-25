<?php

declare(strict_types=1);

namespace App\Actions\Transfers;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Specs\ServiceSpec;
use App\Enums\AuditAction;
use App\Enums\DeploymentTrigger;
use App\Exceptions\DomainRuleViolation;
use App\Exceptions\UnresolvedReferences;
use App\Jobs\AdvanceDeploymentJob;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\EnvironmentRevision;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Queries\CurrentEnvironmentSpec;
use App\Services\Deployments\DeploymentFactory;
use App\Services\Transfers\DatabasePreparations;
use App\Services\Variables\SpecVariableResolver;
use Illuminate\Support\Facades\DB;

/**
 * Starts a database that was never deployed on its own, so data can be
 * imported before the first deploy (docs/14 §12.9): a staged deployment of
 * only this service from the current configuration (`hold_traffic`, marked
 * `plan.prepareImport`). It is placed like any deployment (its volume gets
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
        [$deployment, $created] = DB::transaction(function () use ($instance, $user, $actor): array {
            $instance = ServiceInstance::query()->whereKey($instance->id)->with('service')->lockForUpdate()->firstOrFail();
            $until = now()->addSeconds(DatabasePreparations::LEASE_SECONDS)->toIso8601String();
            $current = DatabasePreparations::current($instance);

            if ($current instanceof Deployment) {
                $plan = $current->plan ?? [];
                $plan[DatabasePreparations::KEY] = ['until' => $until, 'by' => $user->id];
                $current->forceFill(['plan' => $plan])->save();

                return [$current, false];
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
            $revision = EnvironmentRevision::query()->create([
                'team_id' => $environment->team_id,
                'environment_id' => $environment->id,
                'number' => $this->nextRevisionNumber($environment),
                'spec' => $spec,
                'message' => "Started {$instance->service->name} alone to import data before the first deploy",
                'created_by' => $user->id,
                'created_at' => now(),
            ]);

            $deployment = $this->factory->create($environment->project, $instance, $revision, $spec, $service, $env, DeploymentTrigger::Manual, $user);
            $deployment->forceFill([
                'hold_traffic' => true,
                'plan' => [DatabasePreparations::KEY => ['until' => $until, 'by' => $user->id]],
            ])->save();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::DataImportPrepared,
                actor: $actor,
                teamId: $instance->team_id,
                subjectType: 'service',
                subjectId: $instance->service->public_id,
                meta: ['deployment' => $deployment->public_id, 'environment' => $environment->public_id],
            ));

            return [$deployment, true];
        });

        if ($created) {
            dispatch(new AdvanceDeploymentJob($deployment->id))->afterCommit();
        }

        return $deployment;
    }

    private function nextRevisionNumber(Environment $environment): int
    {
        $max = EnvironmentRevision::query()->where('environment_id', $environment->id)->max('number');

        return (is_numeric($max) ? (int) $max : 0) + 1;
    }
}
