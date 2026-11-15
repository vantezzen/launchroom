<?php

declare(strict_types=1);

namespace App\Actions\Moves;

use App\Data\Deployments\DeploymentCommit;
use App\Exceptions\UnresolvedReferences;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Services\Deployments\DeploymentFactory;
use App\Services\Variables\SpecVariableResolver;
use App\Support\Refusal;
use App\Support\ServerPlatform;
use Illuminate\Support\Facades\DB;

/**
 * Builds a service's current commit for another platform without rolling it
 * out (docs/14 §13.3): a deployment of the environment's last applied
 * revision at the active deployment's commit ({@see DeploymentFactory::prebuild()}).
 * It ends as superseded once its image exists; the move's deployment on the
 * target reuses that image (same build key), so the build isn't part of the
 * service's downtime. Only server moves use it; they branch on the refusal.
 */
final readonly class PrebuildForPlatform
{
    public function __construct(
        private SpecVariableResolver $resolver,
        private DeploymentFactory $factory,
    ) {}

    public function handle(ServiceInstance $instance, string $platform, ?User $user): Deployment|Refusal
    {
        if (! in_array($platform, ServerPlatform::ALL, true)) {
            return Refusal::on('platform', 'Unknown platform.');
        }

        return DB::transaction(function () use ($instance, $platform, $user): Deployment|Refusal {
            $instance = ServiceInstance::query()->whereKey($instance->id)->with(['service', 'activeDeployment'])->lockForUpdate()->firstOrFail();
            $environment = Environment::query()->whereKey($instance->environment_id)->with(['project', 'lastAppliedRevision'])->firstOrFail();
            $revision = $environment->lastAppliedRevision;
            $service = $revision?->spec->service($instance->service->public_id);

            if ($revision === null || $service === null || ! $service->kind->isBuilt()) {
                return Refusal::on('service', "{$instance->service->name} isn’t built from source.");
            }

            try {
                $env = $this->resolver->resolve($environment->project, $revision->spec, $service);
            } catch (UnresolvedReferences $e) {
                return $e->toRefusal();
            }

            return $this->factory->prebuild($environment->project, $instance, $revision, $service, $env, $platform, $user, DeploymentCommit::of($instance->activeDeployment));
        });
    }
}
