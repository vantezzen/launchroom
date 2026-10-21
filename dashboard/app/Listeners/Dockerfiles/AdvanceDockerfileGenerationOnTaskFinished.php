<?php

declare(strict_types=1);

namespace App\Listeners\Dockerfiles;

use App\Events\DaemonTaskFinished;
use App\Jobs\Dockerfiles\AdvanceDockerfileGenerationJob;
use App\Models\Dockerfiles\DockerfileGeneration;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * A plan, build or run_once task of a Dockerfile generation finished: move
 * the generation on now instead of waiting for the sweep (docs/20 §5).
 */
final class AdvanceDockerfileGenerationOnTaskFinished
{
    public function handle(DaemonTaskFinished $event): void
    {
        $ids = DockerfileGeneration::query()
            ->where(static fn (Builder $query) => $query
                ->where('plan_task_id', $event->taskId)
                ->orWhere('build_task_id', $event->taskId)
                ->orWhere('run_task_id', $event->taskId))
            ->pluck('id');

        foreach ($ids as $id) {
            if (is_int($id)) {
                dispatch(new AdvanceDockerfileGenerationJob($id));
            }
        }
    }
}
