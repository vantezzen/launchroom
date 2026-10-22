<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Contracts\Git\SourceRepository;
use App\Contracts\PlanPreviewer;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Service;
use App\Services\Git\GitProviders;
use App\Support\Git\GitRef;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * "We detected…" while adding a service from a repository (docs/04 §4).
 * JSON; the add-service sheet polls while the answer is `pending`.
 */
final class PlanPreviewController extends Controller
{
    public function __invoke(Request $request, Project $project, PlanPreviewer $previewer, GitProviders $providers): JsonResponse
    {
        Gate::authorize('create', [Service::class, $project]);

        $request->validate([
            'repository' => ['required', 'string', 'regex:'.GitProviders::REPOSITORY_ID_PATTERN],
            'branch' => ['required', 'string', 'max:255', static function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value) || ! GitRef::isValid('refs/heads/'.$value)) {
                    $fail('Enter a valid branch name.');
                }
            }],
            'rootDirectory' => ['nullable', 'string', 'max:1024', 'regex:/^(?:[A-Za-z0-9_.@+=,-]+(?:\/[A-Za-z0-9_.@+=,-]+)*)?$/D'],
            'retry' => ['sometimes', 'boolean'],
        ]);

        $team = $project->team;
        $repository = $providers->repository($team, $request->string('repository')->toString());

        abort_if(! $repository instanceof SourceRepository, 404);
        Gate::authorize('view', $repository);

        return new JsonResponse($previewer->preview($project, $repository, $request->string('branch')->toString(), $request->string('rootDirectory')->toString(), $request->boolean('retry')));
    }
}
