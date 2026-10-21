<?php

declare(strict_types=1);

namespace App\Actions\Deployments;

use App\Contracts\BuildOrchestrator;
use App\Data\Git\CommitData;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\EnvironmentKind;
use App\Enums\Git\DeploymentReportState;
use App\Exceptions\UnresolvedReferences;
use App\Jobs\AdvanceDeploymentJob;
use App\Listeners\Git\ReportDeploymentToGitHub;
use App\Models\Deployment;
use App\Models\ServiceInstance;
use App\Services\Deployments\DeploymentFactory;
use App\Services\Variables\ProductionSecretPolicy;
use App\Services\Variables\SpecVariableResolver;
use App\Support\Git\GitHubAvatar;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Deploys a new commit of a Git-sourced service instance with the
 * environment's last applied configuration (docs/06 §2: pushes don't apply
 * staged changes). Collapses to the latest commit (docs/04 §6): queued
 * deployments of the instance are superseded, and a build still running for
 * an older commit is cancelled and superseded.
 *
 * Returns null (and logs why) when the instance can't be deployed this way:
 * never deployed (no applied revision), removed or disabled in that
 * revision, broken references, a violated "production secrets must differ"
 * policy (production and previews), or this commit is already queued/in flight.
 */
final readonly class DeployCommit
{
    public function __construct(
        private SpecVariableResolver $resolver,
        private DeploymentFactory $factory,
        private BuildOrchestrator $builds,
        private ProductionSecretPolicy $secretPolicy,
    ) {}

    public function handle(ServiceInstance $instance, CommitData $commit, string $ref, DeploymentTrigger $trigger = DeploymentTrigger::Push): ?Deployment
    {
        $deployment = DB::transaction(function () use ($instance, $commit, $ref, $trigger): ?Deployment {
            $instance = ServiceInstance::query()
                ->with(['service', 'environment.project', 'environment.lastAppliedRevision'])
                ->whereKey($instance->id)
                ->lockForUpdate()
                ->first();

            $revision = $instance?->environment->lastAppliedRevision;
            $spec = $revision?->spec;
            $service = $instance === null ? null : $spec?->service($instance->service->public_id);

            if ($instance === null || $revision === null || $spec === null || $service === null || ! $service->enabled || $instance->removed_at !== null) {
                Log::info('Push not deployed: the service is not live in this environment yet.', ['instance' => $instance?->public_id, 'sha' => $commit->sha]);

                return null;
            }

            $duplicate = Deployment::query()
                ->where('service_instance_id', $instance->id)
                ->where('commit_sha', $commit->sha)
                ->whereIn('status', DeploymentStatus::values([DeploymentStatus::Queued, ...DeploymentStatus::inFlight()]))
                ->exists();

            if ($duplicate) {
                return null;
            }

            // "Production secrets must differ" holds for push, tag and
            // preview deploys too (docs/06 §3.1).
            if (in_array($instance->environment->kind, [EnvironmentKind::Production, EnvironmentKind::Preview], true)) {
                $violations = $this->secretPolicy->violations($instance->environment->project);

                if ($violations !== []) {
                    Log::warning('Push not deployed: production secrets must differ from preview.', ['instance' => $instance->public_id, 'sha' => $commit->sha, 'error' => implode(' ', $violations)]);

                    return null;
                }
            }

            try {
                $env = $this->resolver->resolve($instance->environment->project, $spec, $service);
            } catch (UnresolvedReferences $e) {
                Log::warning('Push not deployed: broken variable references.', ['instance' => $instance->public_id, 'sha' => $commit->sha, 'error' => $e->getMessage()]);

                return null;
            }

            $deployment = $this->factory->create($instance->environment->project, $instance, $revision, $spec, $service, $env, $trigger, null, $commit->sha, $ref);
            $deployment->forceFill([
                'commit_message' => mb_substr($commit->message, 0, 4000),
                'commit_author' => $commit->authorLogin ?? $commit->authorName,
                'commit_author_avatar_url' => GitHubAvatar::url($commit->authorAvatarUrl, $commit->authorLogin),
            ])->save();

            $older = Deployment::query()
                ->where('service_instance_id', $instance->id)
                ->whereKeyNot($deployment->id)
                ->where(static fn (Builder $query) => $query
                    ->where('status', DeploymentStatus::Queued->value)
                    ->orWhere(static fn (Builder $building) => $building->where('status', DeploymentStatus::Building->value)->where('commit_sha', '!=', $commit->sha)))
                ->lockForUpdate()
                ->get();

            foreach ($older as $previous) {
                if ($previous->status === DeploymentStatus::Building) {
                    $this->builds->cancel($previous);
                }

                $previous->forceFill(['superseded_by_id' => $deployment->id]);
                $previous->transitionTo(DeploymentStatus::Superseded);
            }

            return $deployment;
        });

        if ($deployment instanceof Deployment) {
            ReportDeploymentToGitHub::report($deployment, DeploymentReportState::Queued);
            dispatch(new AdvanceDeploymentJob($deployment->id));
        }

        return $deployment;
    }
}
