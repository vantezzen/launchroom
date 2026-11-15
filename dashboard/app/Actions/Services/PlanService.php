<?php

declare(strict_types=1);

namespace App\Actions\Services;

use App\Data\Services\GitSource;
use App\Data\Services\ServicePlanData;
use App\Data\Specs\ServiceSpec;
use App\Enums\ServiceKind;
use App\Enums\ServicePlanStatus;
use App\Enums\TaskFailureClass;
use App\Exceptions\DomainRuleViolation;
use App\Exceptions\GitHeadUnavailable;
use App\Models\DaemonTask;
use App\Models\ServiceInstance;
use App\Queries\CurrentEnvironmentSpec;
use App\Services\Builds\BuildTasks;
use App\Services\Git\GitHeads;
use App\Services\Tasks\TaskCancellation;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;

/**
 * Starts a `plan` task for an app service instance (docs/04 §4): clone the
 * branch head, detect, `railpack prepare`, nothing built. Runs when a service
 * is created and when its build settings change; the result lands in
 * `service_instances.plan` (App\Actions\Services\RecordPlanResult), shown as
 * App\Data\Services\ServicePlanData.
 *
 * Idempotent per (instance, commit, build inputs): a plan already queued or
 * running for the same head and settings is not started again; one for
 * outdated settings is cancelled when the new one starts.
 */
final readonly class PlanService
{
    public function __construct(
        private CurrentEnvironmentSpec $currentSpec,
        private GitHeads $heads,
        private BuildTasks $tasks,
        private TaskCancellation $cancellation,
    ) {}

    public function handle(ServiceInstance $instance): ?DaemonTask
    {
        $instance = ServiceInstance::query()->with(['service', 'environment.project'])->whereKey($instance->id)->first();

        if (! $instance instanceof ServiceInstance || $instance->removed_at !== null || $instance->service->kind !== ServiceKind::App) {
            return null;
        }

        $source = $instance->service->source;

        if (! $source instanceof GitSource) {
            return null;
        }

        $branch = $this->heads->branchFor($instance, $source);

        try {
            $sha = $this->heads->head($instance->team_id, $source, $branch);
        } catch (GitHeadUnavailable $e) {
            $this->record($instance, new ServicePlanData(ServicePlanStatus::Failed, branch: $branch, requestedAt: now()->toIso8601ZuluString(), finishedAt: now()->toIso8601ZuluString(), error: $e->getMessage(), failureClass: TaskFailureClass::CloneError->value));

            return null;
        }

        $environment = $this->currentSpec->get($instance->environment);
        $service = $environment->service($instance->service->public_id);

        if (! $service instanceof ServiceSpec) {
            return null;
        }

        return DB::transaction(function () use ($instance, $environment, $service, $source, $sha, $branch): ?DaemonTask {
            $instance = ServiceInstance::query()->whereKey($instance->id)->lockForUpdate()->firstOrFail();
            $current = $instance->plan;
            // Same commit and same build inputs: the running plan already answers.
            $inputs = hash('sha256', CanonicalJson::encode(['sha' => $sha, 'source' => $service->source, 'build' => $service->build->toArray()]));
            $running = $current?->task !== null ? DaemonTask::query()
                ->where('team_id', $instance->team_id)
                ->where('public_id', $current->task)
                ->open()
                ->first() : null;

            if ($running instanceof DaemonTask && $current->sha === $sha && ($current->inputs ?? $inputs) === $inputs) {
                return null;
            }

            try {
                $task = $this->tasks->plan($instance, $environment, $service, $source, $sha);
            } catch (DomainRuleViolation $e) {
                $this->record($instance, new ServicePlanData(ServicePlanStatus::Failed, sha: $sha, branch: $branch, requestedAt: now()->toIso8601ZuluString(), finishedAt: now()->toIso8601ZuluString(), error: $e->getMessage(), failureClass: TaskFailureClass::ConfigError->value));

                return null;
            }

            // Keep the previous summary visible while the new plan runs.
            $this->record($instance, new ServicePlanData(
                status: ServicePlanStatus::Planning,
                task: $task->public_id,
                inputs: $inputs,
                sha: $sha,
                branch: $branch,
                requestedAt: now()->toIso8601ZuluString(),
                summary: $current?->summary,
            ));

            // A plan for outdated inputs is no longer anyone's answer.
            if ($running instanceof DaemonTask) {
                $this->cancellation->cancel($running);
            }

            return $task;
        });
    }

    private function record(ServiceInstance $instance, ServicePlanData $plan): void
    {
        $instance->forceFill(['plan' => $plan])->save();
    }
}
