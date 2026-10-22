<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Contracts\Git\SourceRepository;
use App\Data\Canvas\PlanPreviewData;
use App\Models\Project;
use App\Services\Canvas\TaskPlanPreviewer;
use Illuminate\Container\Attributes\Bind;

/**
 * Runs (or looks up) a `plan` task for a repository before a service is
 * created, for the "We detected…" summary (docs/04 §4): `pending` until the
 * task completes, then `ready` with the plan (or `failed`); `unavailable`
 * when no build server could run it (detection happens on the first build).
 */
#[Bind(TaskPlanPreviewer::class)]
interface PlanPreviewer
{
    /** `retry` starts a new task when the last one failed, was cancelled or is stuck. */
    public function preview(Project $project, SourceRepository $repository, string $branch, string $rootDirectory, bool $retry = false): PlanPreviewData;

    /** Same, before a project exists (the import flow). */
    public function previewForTeam(int $teamId, SourceRepository $repository, string $branch, string $rootDirectory, bool $retry = false): PlanPreviewData;
}
