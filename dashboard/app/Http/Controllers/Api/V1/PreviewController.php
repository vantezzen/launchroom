<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Data\Api\ListPreviewsQuery;
use App\Data\Api\PreviewResource;
use App\Http\Api\ApiOperation;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Queries\Api\ApiPreviews;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Previews through the API (docs/06 §7, D111): what the Previews page
 * lists, for `launchroom preview wait` and CI.
 */
final class PreviewController extends Controller
{
    #[ApiOperation(id: 'listPreviews', summary: 'Previews of a project, open ones first', tag: 'Previews', response: PreviewResource::class, list: true, query: ListPreviewsQuery::class, description: '`pr` narrows to one pull request. A preview has settled for a commit when every deployment in `deployments` is `finished` and built from `headSha`.')]
    public function index(Project $project, ListPreviewsQuery $query, ApiPreviews $previews): JsonResponse
    {
        Gate::authorize('view', $project);

        return ApiResponse::list($previews->for($project, $query));
    }
}
