<?php

declare(strict_types=1);

namespace App\Actions\Deployments;

use App\Actions\Services\UpdateServiceSettings;
use App\Data\Audit\AuditActor;
use App\Data\Services\DeploySettings;
use App\Data\Services\UpdateServiceSettingsData;
use App\Enums\DeploymentFailureClass;
use App\Enums\DeploymentStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Deployment;
use App\Models\DeploymentStep;
use App\Models\Environment;
use App\Models\ServiceInstance;
use App\Queries\CurrentEnvironmentSpec;
use App\Support\Changes\SpecDiff;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Adopts the port an app listens on when nobody set one (OD14): a
 * deployment that failed because its app listens on exactly one other port
 * (outside loopback) while the service has no port of its own records it
 * (AdvanceDeployment, step meta `adoptPort`). This sets the service's port,
 * a staged change like any other, and deploys it right away when it is the
 * environment's only staged change (the revision says "launchroom set …",
 * the failure message says what happened). With other changes staged it
 * only stages. Never over an explicit port, and only once per deployment.
 */
final readonly class AdoptDetectedPort
{
    public function __construct(
        private UpdateServiceSettings $settings,
        private DeployEnvironment $deploy,
        private CurrentEnvironmentSpec $currentSpec,
    ) {}

    public function handle(int $deploymentId): ?Deployment
    {
        $deployment = Deployment::query()->whereKey($deploymentId)->with('serviceInstance.service')->first();

        if (! $deployment instanceof Deployment || $deployment->status !== DeploymentStatus::Failed || $deployment->failure_class !== DeploymentFailureClass::PortMismatch) {
            return null;
        }

        $step = DeploymentStep::query()
            ->where('deployment_id', $deployment->id)
            ->whereNotNull('meta->adoptPort')
            ->latest('id')
            ->first();
        $port = $step?->meta['adoptPort'] ?? null;

        if (! $step instanceof DeploymentStep || ! is_int($port) || isset($step->meta['adoptedAt'])) {
            return null;
        }

        try {
            return $this->adopt($deployment, $step, $port);
        } catch (DomainRuleViolation $e) {
            // The deploy was refused (a broken reference elsewhere): nothing changed.
            Log::info('A detected port was not adopted: the deploy was refused.', ['deployment' => $deployment->public_id, 'reason' => $e->getMessage()]);

            return null;
        }
    }

    private function adopt(Deployment $deployment, DeploymentStep $step, int $port): ?Deployment
    {
        return DB::transaction(function () use ($deployment, $step, $port): ?Deployment {
            $environment = Environment::query()->whereKey($deployment->environment_id)->with('lastAppliedRevision')->lockForUpdate()->firstOrFail();
            $step = DeploymentStep::query()->whereKey($step->id)->lockForUpdate()->firstOrFail();
            $instance = ServiceInstance::query()->current()->whereKey($deployment->service_instance_id)->with('service')->first();

            if (isset($step->meta['adoptedAt']) || ! $instance instanceof ServiceInstance || $instance->deploy->port !== null) {
                return null;
            }

            $step->forceFill(['meta' => [...$step->meta, 'adoptedAt' => now()->toIso8601ZuluString()]])->save();
            $alreadyStaged = ! SpecDiff::between($environment->lastAppliedRevision?->spec, $this->currentSpec->get($environment))->isEmpty();
            $name = $instance->service->name;

            $this->settings->handle($instance, new UpdateServiceSettingsData(deploy: DeploySettings::from([...$instance->deploy->toArray(), 'port' => $port])));

            // First, so the cut at the message limit never drops it.
            $fact = "Your app listens on {$port}, so launchroom set {$name}’s port to {$port}".($alreadyStaged
                ? '. It deploys with your other staged changes. '
                : ' and deployed again. ');
            $deployment->forceFill(['failure_message' => mb_substr($fact.$deployment->failure_message, 0, Deployment::FAILURE_MESSAGE_LIMIT)])->save();

            if ($alreadyStaged) {
                return null;
            }

            $result = $this->deploy->handle($environment, null, AuditActor::system(), "launchroom set {$name}’s port to {$port}, the port the app listens on");

            foreach ($result->deployments as $created) {
                if ($created->service_instance_id === $instance->id) {
                    return $created;
                }
            }

            return null;
        });
    }
}
