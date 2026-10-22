<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Projects\ImportRepository;
use App\Contracts\Git\SourceRepository;
use App\Contracts\PlanPreviewer;
use App\Data\Canvas\DatabaseEngineOptionData;
use App\Data\Projects\ImportRepositoryData;
use App\Drivers\DatabaseEngines\DatabaseEngines;
use App\Enums\DatabaseEngine;
use App\Enums\DeploymentTrigger;
use App\Enums\Git\GitConnectionStatus;
use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Exceptions\DomainRuleViolation;
use App\Exceptions\UnresolvedReferences;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Teams\TeamSettingsController;
use App\Models\Git\GitConnection;
use App\Models\Git\GitHubApp;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Git\Exceptions\GitConnectionUnusable;
use App\Services\Git\Exceptions\GitProviderRequestFailed;
use App\Services\Git\Exceptions\RepositoryFileUnavailable;
use App\Services\Git\GitProviders;
use App\Services\GitHub\Exceptions\GitHubRequestFailed;
use App\Services\Projects\RepositoryInspector;
use App\Support\Git\GitRef;
use Closure;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Import a repository" (`/projects/new`, flows.md §3): pick a repository,
 * see what we detected (apps, plan per app, suggested databases), name it
 * and deploy. The page is guided; these endpoints feed it.
 */
final class ImportController extends Controller
{
    public function __construct(
        private readonly GitProviders $providers,
    ) {}

    public function create(#[CurrentUser] User $user, DatabaseEngines $engines): Response
    {
        $team = TeamSettingsController::currentTeam($user);
        Gate::authorize('create', [Project::class, $team]);

        $app = GitHubApp::query()->where('team_id', $team->id)->oldest('id')->first();
        $connections = GitConnection::query()
            ->where('team_id', $team->id)
            ->where('status', GitConnectionStatus::Active->value)
            ->orderBy('id')
            ->get(['id', 'public_id', 'provider', 'server_kind', 'name']);

        return Inertia::render('projects/new', [
            'githubConnected' => $app instanceof GitHubApp,
            // Every provider the team can import from (docs/19): the filter chips.
            'providers' => array_values(array_unique([
                ...($app instanceof GitHubApp ? ['github'] : []),
                ...$connections->map(static fn (GitConnection $connection): string => $connection->provider->value)->all(),
            ])),
            'installUrl' => $app?->installUrl(),
            'buildServerOnline' => $this->hasBuildServer($team),
            'engines' => array_map(static function (DatabaseEngine $engine) use ($engines): DatabaseEngineOptionData {
                $driver = $engines->for($engine);

                return new DatabaseEngineOptionData($engine, $engine->label(), $driver->versions(), $driver->defaultVersion(), $engine->tagline());
            }, DatabaseEngine::cases()),
        ]);
    }

    /**
     * The apps of a repository branch and the databases they probably use.
     */
    public function inspect(Request $request, #[CurrentUser] User $user, RepositoryInspector $inspector): JsonResponse
    {
        $team = TeamSettingsController::currentTeam($user);
        Gate::authorize('create', [Project::class, $team]);
        [$repository, $branch] = $this->repository($request, $team);

        try {
            return new JsonResponse($inspector->inspect($repository, $branch));
        } catch (GitHubRequestFailed $e) {
            return new JsonResponse(['message' => $e->status === 404
                ? "GitHub doesn’t know the branch {$branch}."
                : 'GitHub didn’t answer. Try again in a moment.'], $e->status === 404 ? 422 : 502);
        } catch (GitProviderRequestFailed $e) {
            return new JsonResponse(['message' => $e->isNotFound()
                ? "The repository has no branch {$branch}."
                : 'The git server didn’t answer. Try again in a moment.'], $e->isNotFound() ? 422 : 502);
        } catch (RepositoryFileUnavailable|GitConnectionUnusable $e) {
            return new JsonResponse(['message' => $e->getMessage()], 502);
        }
    }

    /**
     * "We detected…" per app, before the project exists (polled while pending).
     */
    public function planPreview(Request $request, #[CurrentUser] User $user, PlanPreviewer $previewer): JsonResponse
    {
        $team = TeamSettingsController::currentTeam($user);
        Gate::authorize('create', [Project::class, $team]);
        [$repository, $branch] = $this->repository($request, $team);
        $request->validate([
            'rootDirectory' => ['nullable', 'string', 'max:1024', 'regex:/^(?:[A-Za-z0-9_.@+=,-]+(?:\/[A-Za-z0-9_.@+=,-]+)*)?$/D'],
            'retry' => ['sometimes', 'boolean'],
        ]);

        return new JsonResponse($previewer->previewForTeam($repository->repositoryTeamId(), $repository, $branch, $request->string('rootDirectory')->toString(), $request->boolean('retry')));
    }

    /**
     * Creates everything and deploys it right away; lands on the canvas.
     */
    public function store(Request $request, ImportRepositoryData $data, #[CurrentUser] User $user, ImportRepository $import, DeployEnvironment $deploy): RedirectResponse
    {
        $team = TeamSettingsController::currentTeam($user);
        Gate::authorize('create', [Project::class, $team]);

        $actor = $this->auditActor($request, $user);
        $project = $import->handle($team, $data, $user, $actor);
        $production = $project->production()->sole();

        if (Gate::allows('deploy', $production)) {
            try {
                $deploy->handle($production, $user, $actor, 'Imported from '.$this->repositoryName($data->repository, $team), DeploymentTrigger::Config);
                Inertia::flash('toast', ['type' => 'success', 'message' => "{$project->name} is deploying. Watch it go live here."]);
            } catch (DomainRuleViolation|UnresolvedReferences $e) {
                Inertia::flash('toast', ['type' => 'warning', 'message' => "{$project->name} is ready but didn’t deploy: {$e->getMessage()}"]);
            }
        }

        return to_route('projects.canvas', [$project, $production]);
    }

    /**
     * @return array{0: SourceRepository, 1: string}
     */
    private function repository(Request $request, Team $team): array
    {
        $request->validate([
            'repository' => ['required', 'string', 'regex:'.GitProviders::REPOSITORY_ID_PATTERN],
            'branch' => ['required', 'string', 'max:255', static function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || ! GitRef::isValid('refs/heads/'.$value)) {
                    $fail('Enter a valid branch name.');
                }
            }],
        ]);

        $repository = $this->providers->repository($team, $request->string('repository')->toString());

        abort_if(! $repository instanceof SourceRepository, 404);
        Gate::authorize('view', $repository);

        return [$repository, $request->string('branch')->toString()];
    }

    private function repositoryName(string $publicId, Team $team): string
    {
        return $this->providers->repository($team, $publicId)?->repositoryFullName() ?? 'a repository';
    }

    private function hasBuildServer(Team $team): bool
    {
        return Server::query()
            ->where('team_id', $team->id)
            ->where('status', ServerStatus::Online->value)
            ->whereJsonContains('roles', ServerRole::Build->value)
            ->exists();
    }
}
