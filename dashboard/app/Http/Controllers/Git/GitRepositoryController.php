<?php

declare(strict_types=1);

namespace App\Http\Controllers\Git;

use App\Contracts\Git\SourceRepository;
use App\Data\Git\GitRepositoryData;
use App\Enums\Git\GitProviderKind;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Teams\TeamSettingsController;
use App\Models\Git\GitHubRepository;
use App\Models\User;
use App\Services\Git\Exceptions\GitConnectionUnusable;
use App\Services\Git\Exceptions\GitProviderRequestFailed;
use App\Services\Git\GitProviders;
use App\Services\GitHub\Exceptions\GitHubRequestFailed;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * JSON endpoints for the repository picker (docs/19 §2): repositories across
 * every connected provider (optionally one, `?provider=gitlab`), and their
 * branches.
 */
final class GitRepositoryController extends Controller
{
    public function index(Request $request, #[CurrentUser] User $user, GitProviders $providers): JsonResponse
    {
        $team = TeamSettingsController::currentTeam($user);
        Gate::authorize('viewAny', [GitHubRepository::class, $team]);

        $search = $request->query('search');
        $search = is_string($search) ? mb_substr(trim($search), 0, 100) : '';
        $only = is_string($request->query('provider')) ? GitProviderKind::tryFrom($request->query('provider')) : null;

        $repositories = [];
        $sources = 0;

        foreach ($providers->all() as $provider) {
            if ($only === null || $only === $provider->kind()) {
                $found = $provider->searchRepositories($team, $search);
                $sources += $found === [] ? 0 : 1;
                $repositories = [...$repositories, ...$found];
            }
        }

        // One provider keeps its own order; several merge by newest push.
        if ($sources > 1) {
            usort($repositories, static fn (SourceRepository $a, SourceRepository $b): int => ($b->pickerData()->pushedAt?->getTimestamp() ?? 0) <=> ($a->pickerData()->pushedAt?->getTimestamp() ?? 0));
        }

        return new JsonResponse(['repositories' => array_map(
            static fn (SourceRepository $repository): GitRepositoryData => $repository->pickerData(),
            array_slice($repositories, 0, 50),
        )]);
    }

    public function branches(string $repository, #[CurrentUser] User $user, GitProviders $providers): JsonResponse
    {
        $team = TeamSettingsController::currentTeam($user);
        $source = $providers->repository($team, $repository);

        abort_if(! $source instanceof SourceRepository, 404);
        Gate::authorize('view', $source);

        try {
            return new JsonResponse(['branches' => $providers->for($source->repositoryRef()->provider)->branches($source)]);
        } catch (GitHubRequestFailed|GitProviderRequestFailed|GitConnectionUnusable) {
            return new JsonResponse(['message' => 'The git server didn’t return the branches. Try again in a moment.'], 502);
        }
    }
}
