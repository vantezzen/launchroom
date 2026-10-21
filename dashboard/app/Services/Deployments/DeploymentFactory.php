<?php

declare(strict_types=1);

namespace App\Services\Deployments;

use App\Data\Services\DatabaseSource;
use App\Data\Services\ImageSource;
use App\Data\Specs\EnvironmentSpec;
use App\Data\Specs\ServiceSpec;
use App\Drivers\DatabaseEngines\DatabaseEngines;
use App\Enums\DeploymentTrigger;
use App\Models\Deployment;
use App\Models\EnvironmentRevision;
use App\Models\Project;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Services\Crypto\ProjectKeyring;
use App\Support\BuildKey;
use App\Support\CanonicalJson;
use App\Support\Images\ImageReference;
use App\Support\Variables\ResolvedEnvironment;

/**
 * Creates a queued deployment: its image when it is already known
 * (database engines, digest-pinned image references), its build key, and its
 * env snapshot encrypted under the project DEK (docs/06 §3.5).
 */
final readonly class DeploymentFactory
{
    public function __construct(
        private ProjectKeyring $keyring,
        private DatabaseEngines $engines,
    ) {}

    public function create(
        Project $project,
        ServiceInstance $instance,
        EnvironmentRevision $revision,
        EnvironmentSpec $spec,
        ServiceSpec $service,
        ResolvedEnvironment $env,
        DeploymentTrigger $trigger,
        ?User $actor,
        ?string $commitSha = null,
        ?string $gitRef = null,
    ): Deployment {
        $deployment = Deployment::query()->create([
            'team_id' => $instance->team_id,
            'service_instance_id' => $instance->id,
            'environment_id' => $instance->environment_id,
            'revision_id' => $revision->id,
            'trigger' => $trigger,
            'git_ref' => $gitRef,
            'commit_sha' => $commitSha,
            'image' => $this->knownImage($service),
            'build_key' => $service->kind->isBuilt() ? $this->buildKey($project, $spec, $service, $env, $commitSha) : null,
            'queued_at' => now(),
            'actor_id' => $actor?->id,
        ]);

        $this->storeSnapshot($project, $deployment, $env->all());

        return $deployment;
    }

    /**
     * @param  array<string, string>  $env
     */
    public function storeSnapshot(Project $project, Deployment $deployment, array $env): void
    {
        $env['LAUNCHROOM_DEPLOYMENT_ID'] = $deployment->public_id;

        if ($deployment->commit_sha !== null) {
            $env['LAUNCHROOM_GIT_COMMIT_SHA'] = $deployment->commit_sha;
        }

        $deployment->forceFill(['env_snapshot' => $this->keyring->encryptSnapshot($project, $deployment->public_id, $env)])->save();
    }

    private function knownImage(ServiceSpec $service): ?string
    {
        $source = $service->source();
        $mirror = config()->string('launchroom.images.mirror_prefix');

        if ($source instanceof DatabaseSource) {
            return $this->engines->for($source->engine)->image($source->version)->mirrored($mirror);
        }

        if ($source instanceof ImageSource) {
            $reference = ImageReference::tryParse($source->reference);

            // Tags are resolved to a digest when the deployment starts.
            return $reference instanceof ImageReference && $reference->isPinned() ? $reference->mirrored($mirror) : null;
        }

        return null;
    }

    private function buildKey(Project $project, EnvironmentSpec $spec, ServiceSpec $service, ResolvedEnvironment $env, ?string $commitSha): string
    {
        $values = [];

        foreach (BuildKey::buildScopedKeys($spec, $service) as $key) {
            $values[$key] = $env->get($key);
        }

        return BuildKey::compute($service, $commitSha, $this->keyring->mac($project, 'build-secrets', CanonicalJson::encode($values)));
    }
}
