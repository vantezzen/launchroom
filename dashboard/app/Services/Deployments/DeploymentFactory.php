<?php

declare(strict_types=1);

namespace App\Services\Deployments;

use App\Data\ConfigAsCode\DeploymentConfigErrorsData;
use App\Data\Deployments\DeploymentCommit;
use App\Data\Services\DatabaseSource;
use App\Data\Services\ImageSource;
use App\Data\Specs\EnvironmentSpec;
use App\Data\Specs\ServiceSpec;
use App\Drivers\DatabaseEngines\DatabaseEngines;
use App\Enums\DeploymentFailureClass;
use App\Enums\DeploymentPurpose;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Events\DeploymentQueued;
use App\Models\Deployment;
use App\Models\EnvironmentRevision;
use App\Models\Project;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Services\Builds\BuildOrchestrator;
use App\Services\Crypto\ProjectKeyring;
use App\Support\BuildKey;
use App\Support\CanonicalJson;
use App\Support\Images\ImageReference;
use App\Support\Images\RegistryLayout;
use App\Support\Variables\ResolvedEnvironment;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * The only way a deployment is created (docs/04 §6), inside the caller's
 * transaction:
 *
 * - its image when already known (database engines, digest-pinned image
 *   references), its build key, its commit and how it is shown, and its env
 *   snapshot ({@see DeploymentSnapshots});
 * - a deployment that goes live collapses older queued ones of the instance
 *   (superseded); a push also supersedes a build still running for an older
 *   commit;
 * - {@see DeploymentQueued} after commit: its listener starts it and reports
 *   "queued" to the git provider.
 */
final readonly class DeploymentFactory
{
    public function __construct(
        private ProjectKeyring $keyring,
        private DatabaseEngines $engines,
        private DeploymentSnapshots $snapshots,
        private BuildOrchestrator $builds,
    ) {}

    /**
     * @param  bool  $supersedesOlderBuilds  a push: a build of an older commit is superseded too
     */
    public function create(
        Project $project,
        ServiceInstance $instance,
        EnvironmentRevision $revision,
        EnvironmentSpec $spec,
        ServiceSpec $service,
        ResolvedEnvironment $env,
        DeploymentTrigger $trigger,
        ?User $actor,
        ?DeploymentCommit $commit = null,
        bool $holdTraffic = false,
        bool $supersedesOlderBuilds = false,
    ): Deployment {
        $deployment = $this->insert($instance, $revision->id, $trigger, $actor, $commit, [
            'image' => $this->knownImage($service),
            'build_key' => $service->kind->isBuilt() ? $this->buildKey($project, $spec, $service, $env, $commit?->sha) : null,
            // Two copies never share data: a service that stops before it starts isn't staged.
            'hold_traffic' => $holdTraffic && ! $instance->stopsBeforeStart($service),
        ]);

        $this->snapshots->store($project, $deployment, $env->all());
        $this->supersedeOlder($deployment, $supersedesOlderBuilds);
        event(new DeploymentQueued($deployment->id));

        return $deployment;
    }

    /**
     * Builds $service's image for $platform without rolling it out
     * ({@see DeploymentPurpose::Prebuild}).
     */
    public function prebuild(Project $project, ServiceInstance $instance, EnvironmentRevision $revision, ServiceSpec $service, ResolvedEnvironment $env, string $platform, ?User $actor, ?DeploymentCommit $commit): Deployment
    {
        $deployment = $this->insert($instance, $revision->id, DeploymentTrigger::Manual, $actor, $commit, [
            'build_key' => $this->buildKey($project, $revision->spec, $service, $env, $commit?->sha),
            'purpose' => DeploymentPurpose::Prebuild,
            'target_platform' => $platform,
        ]);

        $this->snapshots->store($project, $deployment, $env->all());
        event(new DeploymentQueued($deployment->id));

        return $deployment;
    }

    /**
     * Starts a never-deployed database alone for an import until $until
     * ({@see DeploymentPurpose::PrepareImport}): staged, never routed.
     */
    public function prepareImport(Project $project, ServiceInstance $instance, EnvironmentRevision $revision, ServiceSpec $service, ResolvedEnvironment $env, CarbonInterface $until, User $actor): Deployment
    {
        $deployment = $this->insert($instance, $revision->id, DeploymentTrigger::Manual, $actor, null, [
            'image' => $this->knownImage($service),
            'hold_traffic' => true,
            'purpose' => DeploymentPurpose::PrepareImport,
            'prepared_until' => $until,
        ]);

        $this->snapshots->store($project, $deployment, $env->all());
        event(new DeploymentQueued($deployment->id));

        return $deployment;
    }

    /**
     * Runs $source's image again as it was (D68): same image, revision, build
     * summary and environment, re-encrypted under the new id. No build and no
     * release command ({@see DeploymentTrigger::rerunsImage()}). Restart,
     * Start and Rollback (`rollback_of_id` = $source) use it.
     */
    public function rerun(Deployment $source, DeploymentTrigger $trigger, ?User $actor, bool $rollback = false): Deployment
    {
        $source->loadMissing(['environment.project', 'serviceInstance']);
        $project = $source->environment->project;

        $deployment = $this->insert($source->serviceInstance, $source->revision_id, $trigger, $actor, DeploymentCommit::of($source), [
            'build_key' => $source->build_key,
            'image' => $source->image,
            'plan' => $source->plan,
            'build_server_id' => $source->build_server_id,
            'rollback_of_id' => $rollback ? $source->id : null,
        ]);

        $this->snapshots->store($project, $deployment, $this->snapshots->carried($project, $source));
        $this->supersedeOlder($deployment, false);
        event(new DeploymentQueued($deployment->id));

        return $deployment;
    }

    /**
     * A deployment of a push that failed before it started (launchroom.json
     * problems, docs/18 §6): created and failed at once, never started.
     */
    public function rejected(ServiceInstance $instance, ?int $revisionId, DeploymentTrigger $trigger, DeploymentCommit $commit, DeploymentConfigErrorsData $configErrors, string $message): Deployment
    {
        $deployment = $this->insert($instance, $revisionId, $trigger, null, $commit, ['config_errors' => $configErrors]);
        $deployment->fail(DeploymentFailureClass::ConfigError, $message);

        return $deployment;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function insert(ServiceInstance $instance, ?int $revisionId, DeploymentTrigger $trigger, ?User $actor, ?DeploymentCommit $commit, array $attributes): Deployment
    {
        $deployment = new Deployment;
        $deployment->forceFill([
            'team_id' => $instance->team_id,
            'service_instance_id' => $instance->id,
            'environment_id' => $instance->environment_id,
            'revision_id' => $revisionId,
            'trigger' => $trigger,
            'git_ref' => $commit?->ref,
            'commit_sha' => $commit?->sha,
            'commit_message' => $commit?->message,
            'commit_author' => $commit?->author,
            'commit_author_avatar_url' => $commit?->authorAvatarUrl,
            'queued_at' => now(),
            'actor_id' => $actor?->id,
            ...$attributes,
        ])->save();

        return $deployment;
    }

    /**
     * Collapse to latest (docs/04 §6): older queued deployments of the
     * instance are superseded by $deployment; for a push also a build still
     * running for another commit (its task is cancelled).
     */
    private function supersedeOlder(Deployment $deployment, bool $olderBuilds): void
    {
        $older = Deployment::query()
            ->where('service_instance_id', $deployment->service_instance_id)
            ->whereKeyNot($deployment->id)
            ->withPurpose(DeploymentPurpose::Release)
            ->where(static fn (Builder $query) => $query
                ->where('status', DeploymentStatus::Queued->value)
                ->when($olderBuilds, static fn (Builder $building) => $building->orWhere(static fn (Builder $build) => $build
                    ->where('status', DeploymentStatus::Building->value)
                    ->where('commit_sha', '!=', $deployment->commit_sha))))
            ->lockForUpdate()
            ->get();

        foreach ($older as $previous) {
            if ($previous->status === DeploymentStatus::Building) {
                $this->builds->cancel($previous);
            }

            $previous->forceFill(['superseded_by_id' => $deployment->id]);
            $previous->transitionTo(DeploymentStatus::Superseded);
        }
    }

    private function knownImage(ServiceSpec $service): ?string
    {
        $source = $service->source();
        $mirror = RegistryLayout::mirrorPrefix();

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
