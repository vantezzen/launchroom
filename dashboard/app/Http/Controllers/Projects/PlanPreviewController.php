<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Contracts\Git\SourceRepository;
use App\Data\Git\PlanPreviewInputData;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Service;
use App\Services\Canvas\PlanPreviewer;
use App\Services\Git\GitProviders;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * "We detected…" while adding a service from a repository (docs/04 §4).
 * JSON; the add-service sheet polls while the answer is `pending`.
 */
final class PlanPreviewController extends Controller
{
    public function __invoke(Project $project, PlanPreviewInputData $data, PlanPreviewer $previewer, GitProviders $providers): JsonResponse
    {
        Gate::authorize('create', [Service::class, $project]);

        $repository = $providers->repository($project->team, $data->repository);

        abort_if(! $repository instanceof SourceRepository, 404);
        Gate::authorize('view', $repository);

        return new JsonResponse($previewer->preview($project, $repository, $data->branch, $data->rootDirectory ?? '', $data->retry));
    }
}
