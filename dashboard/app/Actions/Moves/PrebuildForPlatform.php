<?php

declare(strict_types=1);

namespace App\Actions\Moves;

use App\Enums\DeploymentTrigger;
use App\Exceptions\DomainRuleViolation;
use App\Exceptions\UnresolvedReferences;
use App\Jobs\AdvanceDeploymentJob;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Services\Deployments\DeploymentFactory;
use App\Services\Moves\Prebuilds;
use App\Services\Variables\SpecVariableResolver;
use Illuminate\Support\Facades\DB;

/**
 * Builds a service's current commit for another platform without rolling it
 * out (docs/14 §13.3): a deployment of the environment's last applied
 * revision at the active deployment's commit, marked `plan.prebuildFor`. It
 * ends as superseded once its image exists; the move's deployment on the
 * target reuses that image (same build key), so the build isn't part of the
 * service's downtime.
 */
final readonly class PrebuildForPlatform
{
    public function __construct(
        private SpecVariableResolver $resolver,
        private DeploymentFactory $factory,
    ) {}

    public function handle(ServiceInstance $instance, string $platform, ?User $user): Deployment
    {
        if (! in_array($platform, Prebuilds::PLATFORMS, true)) {
            throw DomainRuleViolation::on('platform', 'Unknown platform.');
        }

        $deployment = DB::transaction(function () use ($instance, $platform, $user): Deployment {
            $instance = ServiceInstance::query()->whereKey($instance->id)->with(['service', 'activeDeployment'])->lockForUpdate()->firstOrFail();
            $environment = Environment::query()->whereKey($instance->environment_id)->with(['project', 'lastAppliedRevision'])->firstOrFail();
            $revision = $environment->lastAppliedRevision;
            $service = $revision?->spec->service($instance->service->public_id);

            if ($revision === null || $service === null || ! $service->kind->isBuilt()) {
                throw DomainRuleViolation::on('service', "{$instance->service->name} isn’t built from source.");
            }

            try {
                $env = $this->resolver->resolve($environment->project, $revision->spec, $service);
            } catch (UnresolvedReferences $e) {
                throw $e->toDomainRuleViolation();
            }

            $source = $instance->activeDeployment;
            $deployment = $this->factory->create(
                $environment->project,
                $instance,
                $revision,
                $revision->spec,
                $service,
                $env,
                DeploymentTrigger::Manual,
                $user,
                $source?->commit_sha,
                $source?->git_ref,
            );
            $deployment->forceFill([
                'plan' => [Prebuilds::KEY => $platform],
                'commit_message' => $source?->commit_message,
                'commit_author' => $source?->commit_author,
                'commit_author_avatar_url' => $source?->commit_author_avatar_url,
            ])->save();

            return $deployment;
        });

        dispatch(new AdvanceDeploymentJob($deployment->id))->afterCommit();

        return $deployment;
    }
}
