<?php

declare(strict_types=1);

namespace App\Services\Builds;

use App\Contracts\BuildOrchestrator;
use App\Data\Deployments\StepOutcome;
use App\Data\Protocol\V1\BuildResult;
use App\Data\Services\GitSource;
use App\Data\Specs\ServiceSpec;
use App\Enums\DaemonTaskStatus;
use App\Enums\DaemonTaskType;
use App\Enums\DeploymentFailureClass;
use App\Enums\DeploymentStatus;
use App\Exceptions\DomainRuleViolation;
use App\Exceptions\GitHeadUnavailable;
use App\Models\DaemonTask;
use App\Models\Deployment;
use App\Models\ServiceInstance;
use App\Scheduling\BuildRoutingFailed;
use App\Services\Crypto\ProjectKeyring;
use App\Services\Deployments\DeploymentFactory;
use App\Services\Git\GitHeads;
use App\Services\Moves\Prebuilds;
use App\Services\Tasks\TaskCancellation;
use App\Support\BuildKey;
use App\Support\CanonicalJson;
use App\Support\Deployments\GitHeadLookup;
use Illuminate\Contracts\Database\Query\Builder;
use Throwable;

/**
 * Builds deployments through `build` daemon tasks (docs/04 §5–7, §12–13).
 *
 * advance() (deployment row-locked, idempotent):
 * 1. workers/crons take the image of their parent app's deployment of the
 *    same revision (or its active one);
 * 2. without a commit (Deploy of staged changes) the branch head is resolved
 *    (by lookupHead() before the row lock, so the GitHub call never runs
 *    inside the locked transaction) and the build key recomputed with it;
 * 3. an image with the same build key (same project, same service) is reused;
 * 4. otherwise one `build` task per deployment is created and followed: on
 *    success the digest-pinned image, the build summary, secrets hash and
 *    attestations are stored (`deployments.image`, `deployments.plan`);
 *    failures map `failure.class` onto the deployment's failure class, and a
 *    retryable (`transient`) failure gets one new task.
 */
final readonly class TaskBuildOrchestrator implements BuildOrchestrator
{
    /** Build tasks per deployment: the first plus one retry for transient failures. */
    private const int MAX_TASKS = 2;

    public function __construct(
        private BuildTasks $tasks,
        private GitHeads $heads,
        private ProjectKeyring $keyring,
        private TaskCancellation $cancellation,
        private DeploymentFactory $factory,
        private BuildRouting $routing,
    ) {}

    public function lookupHead(Deployment $deployment): ?GitHeadLookup
    {
        if ($deployment->commit_sha !== null || $deployment->image !== null) {
            return null;
        }

        $deployment->loadMissing(['serviceInstance.service', 'serviceInstance.environment', 'revision']);
        $service = $deployment->revision?->spec->service($deployment->serviceInstance->service->public_id);
        $source = $service?->source();

        if (! $service instanceof ServiceSpec || $service->kind->requiresParent() || ! $source instanceof GitSource || $this->latestTask($deployment) instanceof DaemonTask) {
            return null;
        }

        return $this->resolveHead($deployment, $source);
    }

    public function advance(Deployment $deployment, ?GitHeadLookup $head = null): StepOutcome
    {
        $deployment->loadMissing(['serviceInstance.service', 'serviceInstance.environment', 'environment.project', 'revision']);
        $service = $deployment->revision?->spec->service($deployment->serviceInstance->service->public_id);

        if (! $service instanceof ServiceSpec) {
            return StepOutcome::failed(DeploymentFailureClass::ConfigError, 'The deployment has no configuration snapshot.');
        }

        if ($service->kind->requiresParent()) {
            return $this->fromParent($deployment, $service);
        }

        $source = $service->source();

        if (! $source instanceof GitSource) {
            return StepOutcome::failed(DeploymentFailureClass::ConfigError, 'The service has no repository to build.');
        }

        $task = $this->latestTask($deployment);

        if (! $task instanceof DaemonTask) {
            return $this->start($deployment, $service, $source, $head);
        }

        return match ($task->status) {
            DaemonTaskStatus::Pending, DaemonTaskStatus::Leased => StepOutcome::pending(),
            DaemonTaskStatus::Succeeded => $this->succeeded($deployment, $task, $service),
            DaemonTaskStatus::Failed => $this->failed($deployment, $task, $service, $source),
            DaemonTaskStatus::Cancelled => StepOutcome::failed(DeploymentFailureClass::BuildError, 'The build was cancelled.'),
        };
    }

    public function cancel(Deployment $deployment): void
    {
        $this->cancellation->cancelForDeployment($deployment->id);
    }

    private function start(Deployment $deployment, ServiceSpec $service, GitSource $source, ?GitHeadLookup $head): StepOutcome
    {
        if ($deployment->commit_sha === null) {
            // Normally resolved before the lock; resolving here is the fallback.
            $head ??= $this->resolveHead($deployment, $source);

            if ($head->sha === null || $head->branch === null) {
                return StepOutcome::failed(DeploymentFailureClass::ConfigError, $head->error ?? 'The branch has no commit to build.');
            }

            $deployment->forceFill(['commit_sha' => $head->sha, 'git_ref' => 'refs/heads/'.$head->branch])->save();
            $this->refreshBuildKey($deployment, $service);
        }

        try {
            $platform = $this->routing->platformFor($deployment, $service);
        } catch (BuildRoutingFailed $e) {
            return StepOutcome::failed(DeploymentFailureClass::ConfigError, $e->getMessage());
        }

        $reused = $this->reusable($deployment, $platform);

        if ($reused instanceof Deployment) {
            $deployment->forceFill([
                'image' => $reused->image,
                'plan' => array_filter([...($reused->plan ?? []), 'reusedFrom' => $reused->public_id, Prebuilds::KEY => Prebuilds::platform($deployment)], static fn (mixed $value): bool => $value !== null),
                'build_server_id' => $reused->build_server_id,
            ])->save();

            return StepOutcome::succeeded("Reused the image of {$reused->public_id} (same commit and build inputs).");
        }

        return $this->createTask($deployment, $service, $source);
    }

    private function resolveHead(Deployment $deployment, GitSource $source): GitHeadLookup
    {
        try {
            $branch = $this->heads->branchFor($deployment->serviceInstance, $source);

            return GitHeadLookup::resolved($branch, $this->heads->head($deployment->team_id, $source, $branch));
        } catch (GitHeadUnavailable $e) {
            return GitHeadLookup::failed($e->getMessage());
        }
    }

    private function createTask(Deployment $deployment, ServiceSpec $service, GitSource $source): StepOutcome
    {
        $revision = $deployment->revision;

        if ($revision === null || $deployment->commit_sha === null) {
            return StepOutcome::failed(DeploymentFailureClass::ConfigError, 'The deployment has no commit to build.');
        }

        try {
            $this->tasks->build($deployment, $revision->spec, $service, $source, $deployment->commit_sha);
        } catch (DomainRuleViolation $e) {
            return StepOutcome::failed(DeploymentFailureClass::ConfigError, $e->getMessage());
        }

        return StepOutcome::pending();
    }

    private function succeeded(Deployment $deployment, DaemonTask $task, ServiceSpec $service): StepOutcome
    {
        try {
            $result = BuildResult::from(is_array($task->result['build'] ?? null) ? $task->result['build'] : []);
        } catch (Throwable) {
            return StepOutcome::failed(DeploymentFailureClass::BuildError, 'The build finished without a result.');
        }

        // The daemon may only report an image of this service's repository.
        $prefix = config()->string('launchroom.images.apps_prefix').'/'.strtolower($service->id).'@sha256:';

        if (! str_starts_with($result->image, $prefix)) {
            return StepOutcome::failed(DeploymentFailureClass::BuildError, 'The build reported an image outside the service\'s repository.');
        }

        $platform = $task->spec->platform ?? null;

        $deployment->forceFill([
            'image' => $result->image,
            'build_server_id' => $task->server_id,
            'plan' => array_filter([
                'task' => $task->public_id,
                // The image's platform: the scheduler only places it on servers of it.
                'platform' => is_string($platform) ? $platform : null,
                'durationMs' => $result->durationMs,
                'summary' => $result->summary?->toArray(),
                'secretsHash' => $result->secretsHash,
                'attestations' => $result->attestations,
                Prebuilds::KEY => Prebuilds::platform($deployment),
            ], static fn (mixed $value): bool => $value !== null),
        ])->save();

        return StepOutcome::succeeded('Built in '.max(1, (int) round($result->durationMs / 1000)).' s.');
    }

    private function failed(Deployment $deployment, DaemonTask $task, ServiceSpec $service, GitSource $source): StepOutcome
    {
        $failure = is_array($task->result['failure'] ?? null) ? $task->result['failure'] : [];
        $class = is_string($failure['class'] ?? null) ? $failure['class'] : 'build_error';
        $retryable = ($failure['retryable'] ?? false) === true || $class === 'transient';
        $tasks = DaemonTask::query()->where('deployment_id', $deployment->id)->where('type', DaemonTaskType::Build->value)->count();

        if ($retryable && $tasks < self::MAX_TASKS) {
            return $this->createTask($deployment, $service, $source);
        }

        if (is_array($failure['summary'] ?? null)) {
            $deployment->forceFill(['plan' => [...($deployment->plan ?? []), 'task' => $task->public_id, 'summary' => $failure['summary']]])->save();
        }

        $step = is_string($failure['step'] ?? null) && $failure['step'] !== '' ? " ({$failure['step']})" : '';
        $message = $task->error ?? 'The build failed.';

        return StepOutcome::failed(self::failureClass($class), mb_substr("Build failed{$step}: {$message}", 0, 4000));
    }

    /**
     * Protocol FailureClass → deployment failure class (docs/04 §6).
     */
    public static function failureClass(string $class): DeploymentFailureClass
    {
        return match ($class) {
            'plan_error' => DeploymentFailureClass::PlanError,
            'transient', 'internal_error' => DeploymentFailureClass::Transient,
            'clone_error', 'config_error' => DeploymentFailureClass::ConfigError,
            default => DeploymentFailureClass::BuildError,
        };
    }

    /**
     * Workers and crons run their parent app's image (docs/04 §1).
     */
    private function fromParent(Deployment $deployment, ServiceSpec $service): StepOutcome
    {
        $instance = $deployment->serviceInstance;
        $parent = $instance->service->parent_service_id === null ? null : ServiceInstance::query()
            ->where('environment_id', $instance->environment_id)
            ->where('service_id', $instance->service->parent_service_id)
            ->first();

        if (! $parent instanceof ServiceInstance) {
            return StepOutcome::failed(DeploymentFailureClass::ConfigError, "The app {$service->name} runs on is not part of this environment.");
        }

        $build = Deployment::query()
            ->where('service_instance_id', $parent->id)
            ->where('revision_id', $deployment->revision_id)
            ->latest('id')
            ->first()
            ?? ($parent->active_deployment_id === null ? null : Deployment::query()->find($parent->active_deployment_id));

        if (! $build instanceof Deployment) {
            return StepOutcome::failed(DeploymentFailureClass::ConfigError, 'Deploy the app this process belongs to first.');
        }

        if ($build->image !== null) {
            $deployment->forceFill([
                'image' => $build->image,
                'commit_sha' => $deployment->commit_sha ?? $build->commit_sha,
                'plan' => [...($build->plan ?? []), 'reusedFrom' => $build->public_id],
            ])->save();

            return StepOutcome::succeeded("Uses the image of {$build->public_id}.");
        }

        if ($build->status->isTerminal()) {
            return StepOutcome::failed(DeploymentFailureClass::BuildError, "The app build ({$build->public_id}) did not produce an image.");
        }

        return StepOutcome::pending();
    }

    private function latestTask(Deployment $deployment): ?DaemonTask
    {
        return DaemonTask::query()
            ->where('deployment_id', $deployment->id)
            ->where('type', DaemonTaskType::Build->value)
            ->latest('id')
            ->first();
    }

    /**
     * The newest deployment of the same service in the same project with an
     * equal build key and an image of the target platform (docs/04 §7).
     */
    private function reusable(Deployment $deployment, string $platform): ?Deployment
    {
        if ($deployment->build_key === null) {
            return null;
        }

        return Deployment::query()
            ->where('team_id', $deployment->team_id)
            ->whereKeyNot($deployment->id)
            ->where('build_key', $deployment->build_key)
            ->whereNotNull('image')
            ->whereIn('service_instance_id', ServiceInstance::query()->select('id')->where('service_id', $deployment->serviceInstance->service_id))
            ->whereNotIn('status', [DeploymentStatus::Failed->value])
            // Images are single-platform; older ones without a recorded platform match.
            ->where(static fn (Builder $query) => $query->whereNull('plan->platform')->orWhere('plan->platform', $platform))
            ->latest('id')
            ->first();
    }

    /**
     * Recomputes the build key once the commit is known (the env snapshot
     * holds the build-scoped values; equal to DeploymentFactory's key) and
     * re-stores the snapshot with the commit variable.
     */
    private function refreshBuildKey(Deployment $deployment, ServiceSpec $service): void
    {
        $revision = $deployment->revision;

        if ($revision === null || $deployment->env_snapshot === null) {
            return;
        }

        $project = $deployment->environment->project;
        $env = $this->keyring->decryptSnapshot($project, $deployment->public_id, $deployment->env_snapshot);
        $values = [];

        foreach (BuildKey::buildScopedKeys($revision->spec, $service) as $key) {
            $values[$key] = $env[$key] ?? null;
        }

        $deployment->forceFill([
            'build_key' => BuildKey::compute($service, $deployment->commit_sha, $this->keyring->mac($project, 'build-secrets', CanonicalJson::encode($values))),
        ])->save();

        // Adds LAUNCHROOM_GIT_COMMIT_SHA now that the commit is known.
        $this->factory->storeSnapshot($project, $deployment, $env);
    }
}
