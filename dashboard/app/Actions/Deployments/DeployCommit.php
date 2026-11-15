<?php

declare(strict_types=1);

namespace App\Actions\Deployments;

use App\Data\Deployments\CommitNotDeployed;
use App\Data\Deployments\DeploymentCommit;
use App\Data\Git\CommitData;
use App\Enums\DeploymentTrigger;
use App\Exceptions\UnresolvedReferences;
use App\Models\Deployment;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Services\Deployments\DeploymentFactory;
use App\Services\Variables\ProductionSecretPolicy;
use App\Services\Variables\SpecVariableResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Deploys a new commit of a Git-sourced service instance with the
 * environment's last applied configuration (docs/06 §2: pushes don't apply
 * staged changes). Collapses to the latest commit (docs/04 §6): queued
 * deployments of the instance are superseded, and a build still running for
 * an older commit is cancelled and superseded.
 *
 * Not deployed (logged, and returned with the reason): never deployed (no
 * applied revision), removed or disabled in that revision, this commit is
 * already queued or in flight, a violated "production secrets must differ"
 * policy, or broken references.
 */
final readonly class DeployCommit
{
    public function __construct(
        private SpecVariableResolver $resolver,
        private DeploymentFactory $factory,
        private ProductionSecretPolicy $secretPolicy,
    ) {}

    public function handle(ServiceInstance $instance, CommitData $commit, string $ref, DeploymentTrigger $trigger = DeploymentTrigger::Push, ?User $user = null): Deployment|CommitNotDeployed
    {
        $outcome = DB::transaction(function () use ($instance, $commit, $ref, $trigger, $user): Deployment|CommitNotDeployed {
            $instance = ServiceInstance::query()
                ->with(['service', 'environment.project', 'environment.lastAppliedRevision'])
                ->whereKey($instance->id)
                ->lockForUpdate()
                ->first();

            $revision = $instance?->environment->lastAppliedRevision;
            $spec = $revision?->spec;
            $service = $instance === null ? null : $spec?->service($instance->service->public_id);

            if ($instance === null || $revision === null || $spec === null || $service === null || ! $service->enabled || $instance->removed_at !== null) {
                return new CommitNotDeployed('The service isn’t deployed in this environment yet. Deploy its staged changes first.');
            }

            $duplicate = Deployment::query()
                ->where('service_instance_id', $instance->id)
                ->where('commit_sha', $commit->sha)
                ->unfinished()
                ->exists();

            if ($duplicate) {
                return new CommitNotDeployed('This commit is already deploying.');
            }

            $violations = $this->secretPolicy->violationsFor($instance->environment);

            if ($violations !== []) {
                return new CommitNotDeployed(implode(' ', $violations));
            }

            try {
                $env = $this->resolver->resolve($instance->environment->project, $spec, $service);
            } catch (UnresolvedReferences $e) {
                return new CommitNotDeployed('Broken variable references: '.$e->getMessage());
            }

            return $this->factory->create(
                $instance->environment->project,
                $instance,
                $revision,
                $spec,
                $service,
                $env,
                $trigger,
                $user,
                DeploymentCommit::pushed($commit, $ref),
                supersedesOlderBuilds: true,
            );
        });

        if ($outcome instanceof CommitNotDeployed) {
            Log::info('Commit not deployed.', ['instance' => $instance->public_id, 'sha' => $commit->sha, 'reason' => $outcome->message]);
        }

        return $outcome;
    }
}
