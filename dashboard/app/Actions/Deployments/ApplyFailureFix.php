<?php

declare(strict_types=1);

namespace App\Actions\Deployments;

use App\Actions\Services\UpdateServiceSettings;
use App\Data\Audit\AuditActor;
use App\Data\Deployments\FailureFixData;
use App\Data\Services\DeploySettings;
use App\Data\Services\UpdateServiceSettingsData;
use App\Enums\FailureFixAction;
use App\Exceptions\DomainRuleViolation;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Queries\CurrentEnvironmentSpec;
use App\Support\Changes\SpecDiff;
use Illuminate\Support\Facades\DB;

/**
 * The one-click fix of an explained failure (flows §6, FailureCatalogue):
 * stages the suggested setting on the failed deployment's service (port, or
 * a longer health check start period) and deploys it right away when it is
 * the environment's only staged change. With other changes already staged
 * it only stages, so a fix never ships someone else's work unreviewed.
 *
 * Returns the new deployment of the service, or null when only staged.
 */
final readonly class ApplyFailureFix
{
    public function __construct(
        private UpdateServiceSettings $settings,
        private DeployEnvironment $deploy,
        private CurrentEnvironmentSpec $currentSpec,
    ) {}

    public function handle(Deployment $deployment, FailureFixData $fix, User $user, AuditActor $actor): ?Deployment
    {
        return DB::transaction(function () use ($deployment, $fix, $user, $actor): ?Deployment {
            $environment = Environment::query()->whereKey($deployment->environment_id)->with('lastAppliedRevision')->lockForUpdate()->firstOrFail();
            $instance = ServiceInstance::query()->current()->whereKey($deployment->service_instance_id)->with('service')->first();

            if (! $instance instanceof ServiceInstance) {
                throw DomainRuleViolation::on('action', 'This service is no longer part of the environment.');
            }

            $alreadyStaged = ! SpecDiff::between($environment->lastAppliedRevision?->spec, $this->currentSpec->get($environment))->isEmpty();

            $settings = $instance->deploy->toArray();
            $settings = match ($fix->action) {
                FailureFixAction::SetPort => [...$settings, 'port' => $fix->port],
                FailureFixAction::RaiseHealthTimeout => [...$settings, 'healthcheck' => [...$instance->deploy->healthcheck->toArray(), 'startPeriodSeconds' => $fix->seconds]],
                FailureFixAction::AddVariable => throw DomainRuleViolation::on('action', 'Add the variable in the service’s variables.'),
                FailureFixAction::GenerateDockerfile => throw DomainRuleViolation::on('action', 'Generate the Dockerfile in the service’s build settings.'),
            };

            $this->settings->handle($instance, new UpdateServiceSettingsData(deploy: DeploySettings::from($settings)));

            if ($alreadyStaged) {
                return null;
            }

            $message = $fix->action === FailureFixAction::SetPort
                ? "Use port {$fix->port} for {$instance->service->name}"
                : "Give {$instance->service->name} {$fix->seconds}s to start";
            $result = $this->deploy->handle($environment, $user, $actor, $message);

            foreach ($result->deployments as $created) {
                if ($created->service_instance_id === $instance->id) {
                    return $created;
                }
            }

            return null;
        });
    }
}
