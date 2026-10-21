<?php

declare(strict_types=1);

namespace App\Actions\Dockerfiles;

use App\Enums\DaemonTaskStatus;
use App\Enums\Dockerfiles\DockerfileGenerationStatus;
use App\Exceptions\DomainRuleViolation;
use App\Jobs\Dockerfiles\DraftDockerfileJob;
use App\Models\DaemonTask;
use App\Models\Dockerfiles\DockerfileGeneration;
use App\Services\Dockerfiles\DockerfileAttempts;
use App\Services\Dockerfiles\ProjectSecretValues;
use App\Services\Dockerfiles\VerificationRuns;
use App\Services\Tasks\TaskCancellation;
use App\Support\Dockerfiles\RepositoryContext;
use Illuminate\Support\Facades\DB;

/**
 * Moves a generation forward from what its daemon tasks report (docs/20 §5),
 * row-locked and idempotent; dispatched when one of its tasks finishes, after
 * a draft and by the minute sweep (which also enforces step timeouts).
 *
 * reading → drafting: the plan task's inspection, filtered and scrubbed
 * again, becomes the context; drafting is DraftDockerfile's (a model call is
 * never made under this lock); building → starting: the verification build
 * pushed an image, a run_once with a probe starts it; starting → verified
 * when the app answered on its port. A failed build or start goes back to
 * drafting with its masked log tail while attempts remain.
 */
final readonly class AdvanceDockerfileGeneration
{
    public function __construct(
        private ProjectSecretValues $secrets,
        private VerificationRuns $runs,
        private DockerfileAttempts $attempts,
        private TaskCancellation $cancellation,
    ) {}

    public function handle(int $generationId): void
    {
        $draft = DB::transaction(function () use ($generationId): bool {
            $generation = DockerfileGeneration::query()->with(['serviceInstance.environment.project', 'service'])->whereKey($generationId)->lockForUpdate()->first();

            if (! $generation instanceof DockerfileGeneration || $generation->status->isTerminal()) {
                return false;
            }

            if ($this->timedOut($generation)) {
                $this->attempts->fail($generation, $this->timeoutMessage($generation->status));
                $this->cancelTasks($generation);

                return false;
            }

            return match ($generation->status) {
                DockerfileGenerationStatus::Reading => $this->reading($generation),
                DockerfileGenerationStatus::Building => $this->building($generation),
                DockerfileGenerationStatus::Starting => $this->starting($generation),
                default => false,
            };
        });

        if ($draft) {
            dispatch(new DraftDockerfileJob($generationId));
        }
    }

    /**
     * @return bool whether a draft should start
     */
    private function reading(DockerfileGeneration $generation): bool
    {
        $task = $this->task($generation->plan_task_id);

        if (! $task instanceof DaemonTask) {
            $this->attempts->fail($generation, 'launchroom could not start reading the repository.');

            return false;
        }

        if (! $task->status->isTerminal()) {
            return false;
        }

        $result = $task->result ?? [];
        $inspection = is_array($result['inspection'] ?? null) ? $result['inspection'] : null;

        if ($inspection === null) {
            $this->attempts->fail($generation, 'launchroom could not read the repository'.($task->error !== null ? ': '.mb_substr($task->error, 0, 300) : '.'));

            return false;
        }

        $project = $generation->serviceInstance->environment->project;
        $context = RepositoryContext::sanitize($inspection, $this->secrets->for($project));

        $failure = is_array($result['failure'] ?? null) ? $result['failure'] : [];
        $plan = is_array($result['plan'] ?? null) ? $result['plan'] : [];
        $summary = is_array($plan['summary'] ?? null) ? $plan['summary'] : (is_array($failure['summary'] ?? null) ? $failure['summary'] : []);

        $generation->forceFill([
            'context' => $context,
            'files_sent' => array_map(static fn (array $file): array => ['path' => $file['path'], 'bytes' => strlen($file['content']), 'truncated' => $file['truncated']], $context['files']),
            'detected' => [
                ...DockerfileAttempts::facts($summary),
                'error' => $task->status === DaemonTaskStatus::Succeeded ? null : RepositoryContext::scrub(mb_substr($task->error ?? '', 0, 1000)),
                'healthPath' => is_string($summary['healthPath'] ?? null) && $summary['healthPath'] !== '/' ? $summary['healthPath'] : null,
            ],
        ]);
        $generation->transitionTo(DockerfileGenerationStatus::Drafting);

        return true;
    }

    private function building(DockerfileGeneration $generation): bool
    {
        $task = $this->task($generation->build_task_id);

        if (! $task instanceof DaemonTask || ! $task->status->isTerminal()) {
            return false;
        }

        $built = is_array($task->result['build'] ?? null) ? $task->result['build'] : [];
        $image = $built['image'] ?? null;

        if ($task->status !== DaemonTaskStatus::Succeeded || ! is_string($image)) {
            $failure = is_array($task->result['failure'] ?? null) ? $task->result['failure'] : [];
            $step = is_string($failure['step'] ?? null) ? $failure['step'] : null;
            $message = $task->status === DaemonTaskStatus::Cancelled ? 'The build was cancelled.' : ($task->error ?? 'The build failed.');

            return $this->attempts->repairOrFail($generation, 'build', ($step !== null ? "({$step}) " : '').mb_substr($message, 0, 600), $this->attempts->logTail($task));
        }

        $generation->forceFill(['image' => $image]);

        try {
            $run = $this->runs->create($generation, $image, $task);
        } catch (DomainRuleViolation $e) {
            $this->attempts->fail($generation, $e->getMessage());

            return false;
        }

        $generation->forceFill(['run_task_id' => $run->id]);
        $this->attempts->record($generation, ['buildTask' => $task->public_id, 'buildMs' => is_int($built['durationMs'] ?? null) ? $built['durationMs'] : null]);
        $generation->transitionTo(DockerfileGenerationStatus::Starting);

        return false;
    }

    private function starting(DockerfileGeneration $generation): bool
    {
        $task = $this->task($generation->run_task_id);

        if (! $task instanceof DaemonTask || ! $task->status->isTerminal()) {
            return false;
        }

        $run = is_array($task->result['runOnce'] ?? null) ? $task->result['runOnce'] : [];
        $probe = is_array($run['probe'] ?? null) ? $run['probe'] : [];

        if ($task->status === DaemonTaskStatus::Succeeded && ($probe['listening'] ?? false) === true) {
            $this->attempts->record($generation, ['outcome' => 'verified', 'runTask' => $task->public_id, 'startedAfterMs' => $probe['afterMs'] ?? null, 'httpStatus' => $probe['httpStatus'] ?? null]);
            $generation->forceFill(['error' => null]);
            $generation->transitionTo(DockerfileGenerationStatus::Verified);

            return false;
        }

        return $this->attempts->repairOrFail($generation, 'start', $this->startMessage($generation, $task, $run, $probe), $this->attempts->logTail($task));
    }

    /**
     * "Exited with code 1", "Didn't listen on 3000 within 90 s; it listens on 5173".
     *
     * @param  array<mixed>  $run
     * @param  array<mixed>  $probe
     */
    private function startMessage(DockerfileGeneration $generation, DaemonTask $task, array $run, array $probe): string
    {
        $port = $generation->port ?? 0;

        if ($task->status !== DaemonTaskStatus::Succeeded) {
            return 'The app could not be started'.($task->error !== null ? ': '.mb_substr($task->error, 0, 400) : '.');
        }

        if (($probe['exited'] ?? false) === true) {
            return 'The app exited with code '.(is_int($run['exitCode'] ?? null) ? $run['exitCode'] : '?')." before it listened on port {$port}.";
        }

        $ports = array_values(array_filter(is_array($probe['listeningPorts'] ?? null) ? $probe['listeningPorts'] : [], is_int(...)));
        $seconds = config()->integer('launchroom.dockerfile_generation.probe_seconds');

        return "The app did not listen on port {$port} within {$seconds} seconds".($ports === [] ? '.' : '; it listens on '.implode(', ', $ports).' instead.');
    }

    private function task(?int $id): ?DaemonTask
    {
        return $id === null ? null : DaemonTask::query()->find($id);
    }

    private function timedOut(DockerfileGeneration $generation): bool
    {
        $limits = config('launchroom.dockerfile_generation.step_timeout_seconds');
        $limit = is_array($limits) && is_int($limits[$generation->status->value] ?? null) ? $limits[$generation->status->value] : null;
        $since = $generation->step_started_at ?? $generation->created_at;

        return $limit !== null && $since !== null && $since->addSeconds($limit)->isPast();
    }

    private function timeoutMessage(DockerfileGenerationStatus $status): string
    {
        return match ($status) {
            DockerfileGenerationStatus::Reading => 'Reading the repository took too long. Check that a build server is online.',
            DockerfileGenerationStatus::Drafting => 'The model took too long to answer.',
            DockerfileGenerationStatus::Building => 'The verification build took too long. Check that a build server is online.',
            default => 'Starting the app took too long. Check that a server is online.',
        };
    }

    private function cancelTasks(DockerfileGeneration $generation): void
    {
        foreach ([$generation->plan_task_id, $generation->build_task_id, $generation->run_task_id] as $id) {
            $task = $this->task($id);

            if ($task instanceof DaemonTask && ! $task->status->isTerminal()) {
                $this->cancellation->cancel($task);
            }
        }
    }
}
