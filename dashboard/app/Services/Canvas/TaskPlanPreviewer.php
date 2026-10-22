<?php

declare(strict_types=1);

namespace App\Services\Canvas;

use App\Contracts\Git\SourceRepository;
use App\Contracts\PlanPreviewer;
use App\Data\Canvas\DetectedPlanData;
use App\Data\Canvas\PlanPreviewData;
use App\Data\Projects\PlanSuggestionsData;
use App\Data\Services\GitSource;
use App\Enums\DaemonTaskStatus;
use App\Enums\EnvironmentKind;
use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Exceptions\DomainRuleViolation;
use App\Exceptions\GitHeadUnavailable;
use App\Models\DaemonTask;
use App\Models\Project;
use App\Models\Server;
use App\Services\Builds\BuildTasks;
use App\Services\Git\GitHeads;
use App\Services\Tasks\TaskCancellation;
use App\Support\PublicId;
use Closure;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * "We detected…" before a service exists (docs/04 §4): the first call
 * resolves the branch head and starts a `plan` task for (repository, branch,
 * root directory); later calls (the add-service sheet polls) look the task
 * up and answer `pending` until it finished, then `ready` with the detected
 * plan or `failed` with the reason.
 *
 * The task id is remembered per (team, repository, branch, root directory)
 * for {@see self::TTL_SECONDS}; afterwards a new head and task are resolved.
 * Without an online build server nothing would ever pick the task up, so the
 * answer is `unavailable` and detection happens with the first build.
 *
 * A task never ends up "pending" forever: one nobody leased within
 * {@see self::LEASE_WAIT_SECONDS} is cancelled and reported as failed, and
 * one past its deadline is reported as failed before the lease reaper gets
 * to it. `retry` forgets a failed, cancelled or stale task and starts anew.
 */
final readonly class TaskPlanPreviewer implements PlanPreviewer
{
    public const int TTL_SECONDS = 600;

    /** How long a plan task may wait for a build server to lease it. */
    public const int LEASE_WAIT_SECONDS = 120;

    public function __construct(
        private GitHeads $heads,
        private BuildTasks $tasks,
        private Cache $cache,
        private TaskCancellation $cancellation,
    ) {}

    public function preview(Project $project, SourceRepository $repository, string $branch, string $rootDirectory, bool $retry = false): PlanPreviewData
    {
        return $this->run($project->team_id, fn (): string => $this->environmentId($project), $repository, $branch, $rootDirectory, $retry);
    }

    /**
     * Before the project exists (import flow): nothing is deployed into the
     * plan's environment, so a fresh id stands in.
     */
    public function previewForTeam(int $teamId, SourceRepository $repository, string $branch, string $rootDirectory, bool $retry = false): PlanPreviewData
    {
        return $this->run($teamId, static fn (): string => PublicId::generate('env'), $repository, $branch, $rootDirectory, $retry);
    }

    /**
     * @param  Closure(): string  $environmentId
     */
    private function run(int $teamId, Closure $environmentId, SourceRepository $repository, string $branch, string $rootDirectory, bool $retry): PlanPreviewData
    {
        $key = 'plan-preview:'.$teamId.':'.$repository->repositoryRef()->key().':'.hash('sha256', $branch."\0".$rootDirectory);
        $remembered = $this->cache->get($key);
        $task = is_string($remembered) ? DaemonTask::query()->where('team_id', $teamId)->where('public_id', $remembered)->first() : null;

        if ($task instanceof DaemonTask && $this->leaseWaitOver($task)) {
            // Nobody picked it up: don't let it run minutes later.
            $this->cancellation->cancel($task);
            $task->refresh();
        }

        if ($task instanceof DaemonTask && $retry && (($task->status->isTerminal() && $task->status !== DaemonTaskStatus::Succeeded) || $this->pastDeadline($task))) {
            $this->cancellation->cancel($task);
            $task = null;
        }

        if (! $task instanceof DaemonTask) {
            if (! $this->hasBuildServer($teamId)) {
                return PlanPreviewData::unavailable('No server with the build role is online, so launchroom detects the framework, commands and port with the first build. You can review and override them in Settings afterwards.');
            }

            $source = GitSource::from($repository->gitSource($rootDirectory));

            try {
                $sha = $this->heads->head($teamId, $source, $branch);
                $task = $this->tasks->previewPlan($teamId, $environmentId(), $source, $sha);
            } catch (GitHeadUnavailable|DomainRuleViolation $e) {
                return new PlanPreviewData('failed', null, $e->getMessage());
            }

            $this->cache->put($key, $task->public_id, self::TTL_SECONDS);
        }

        $result = $task->result ?? [];
        $plan = is_array($result['plan'] ?? null) ? $result['plan'] : [];
        $failure = is_array($result['failure'] ?? null) ? $result['failure'] : [];

        if ($this->pastDeadline($task)) {
            return new PlanPreviewData('failed', null, 'Detection did not finish in time. Retry, or add the service: the first build detects again.');
        }

        return match ($task->status) {
            DaemonTaskStatus::Pending => new PlanPreviewData('pending', null, 'Waiting for a build server'),
            DaemonTaskStatus::Leased => new PlanPreviewData('pending', null, $this->inspecting($task)),
            DaemonTaskStatus::Succeeded => new PlanPreviewData('ready', DetectedPlanData::fromStored($plan['summary'] ?? null), suggestions: PlanSuggestionsData::fromStored($plan['summary'] ?? null, $rootDirectory)),
            DaemonTaskStatus::Failed, DaemonTaskStatus::Cancelled => new PlanPreviewData(
                'failed',
                DetectedPlanData::fromStored($failure['summary'] ?? null),
                match (true) {
                    $task->status === DaemonTaskStatus::Failed => 'Detection failed: '.mb_substr($task->error ?? 'the plan task failed', 0, 500),
                    $task->leased_at === null => 'No build server picked up the detection. Check that your build server is online, then retry.',
                    default => 'The detection was cancelled.',
                },
            ),
        };
    }

    private function inspecting(DaemonTask $task): string
    {
        $name = $task->loadMissing('server')->server?->name;

        return $name === null || $name === '' ? 'Inspecting the repository on a build server' : "Inspecting the repository on the server {$name}";
    }

    /** Still pending after the lease wait (since created or requeued): no build server takes it. */
    private function leaseWaitOver(DaemonTask $task): bool
    {
        $since = $task->updated_at ?? $task->created_at;

        return $task->status === DaemonTaskStatus::Pending
            && $since !== null
            && $since->lt(now()->subSeconds(self::LEASE_WAIT_SECONDS));
    }

    /** Leased but past its deadline: the lease reaper fails it soon. */
    private function pastDeadline(DaemonTask $task): bool
    {
        return $task->status === DaemonTaskStatus::Leased && ($task->deadline_at?->isPast() ?? false);
    }

    private function hasBuildServer(int $teamId): bool
    {
        return Server::query()
            ->where('team_id', $teamId)
            ->where('status', ServerStatus::Online->value)
            ->whereJsonContains('roles', ServerRole::Build->value)
            ->exists();
    }

    /**
     * Plan specs name an environment; the production one stands in (nothing
     * is deployed into it).
     */
    private function environmentId(Project $project): string
    {
        $environment = $project->environments()->where('kind', EnvironmentKind::Production->value)->value('public_id')
            ?? $project->environments()->orderBy('id')->value('public_id');

        if (! is_string($environment)) {
            throw DomainRuleViolation::on('project', 'This project has no environment yet.');
        }

        return $environment;
    }
}
