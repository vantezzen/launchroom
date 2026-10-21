<?php

declare(strict_types=1);

namespace App\Actions\Dockerfiles;

use App\Enums\Dockerfiles\DockerfileGenerationStatus;
use App\Models\DaemonTask;
use App\Models\Dockerfiles\DockerfileGeneration;
use App\Services\Tasks\TaskCancellation;
use Illuminate\Support\Facades\DB;

/**
 * Stops a running generation: pending tasks are cancelled directly, leased
 * ones on their next heartbeat; a model call in flight finishes but its
 * answer is dropped (DraftDockerfile re-checks the status).
 */
final readonly class CancelDockerfileGeneration
{
    public function __construct(
        private TaskCancellation $cancellation,
    ) {}

    public function handle(DockerfileGeneration $generation): void
    {
        DB::transaction(function () use ($generation): void {
            $generation = DockerfileGeneration::query()->whereKey($generation->id)->lockForUpdate()->firstOrFail();

            if ($generation->status->isTerminal()) {
                return;
            }

            foreach ([$generation->plan_task_id, $generation->build_task_id, $generation->run_task_id] as $id) {
                $task = $id === null ? null : DaemonTask::query()->find($id);

                if ($task instanceof DaemonTask && ! $task->status->isTerminal()) {
                    $this->cancellation->cancel($task);
                }
            }

            $generation->forceFill(['error' => null]);
            $generation->transitionTo(DockerfileGenerationStatus::Cancelled);
        });
    }
}
