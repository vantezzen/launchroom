<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Git\AddPublicRepository;
use App\Actions\Git\CheckPublicRepository;
use App\Actions\Projects\ImportRepository;
use App\Contracts\Git\SourceRepository;
use App\Data\Git\PlanPreviewInputData;
use App\Data\Git\PublicRepositoryUrlData;
use App\Data\Git\RepositoryBranchData;
use App\Data\Projects\ImportRepositoryData;
use App\Drivers\DatabaseEngines\DatabaseEngines;
use App\Enums\DeploymentTrigger;
use App\Enums\Git\GitConnectionStatus;
use App\Exceptions\DomainRuleViolation;
use App\Exceptions\Git\GitProviderRequestFailed;
use App\Exceptions\Git\RepositoryFileUnavailable;
use App\Exceptions\UnresolvedReferences;
use App\Http\Controllers\Controller;
use App\Http\Routing\CurrentTeam;
use App\Http\Toast;
use App\Models\Git\GitConnection;
use App\Models\Git\GitHubApp;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Canvas\PlanPreviewer;
use App\Services\Git\GitProviders;
use App\Services\Git\Providers\GitHub\Exceptions\GitHubRequestFailed;
use App\Services\Projects\RepositoryInspector;
use App\Services\Templates\TemplateCatalog;
use App\Support\Templates\TemplateCompiler;
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

    public function create(DatabaseEngines $engines, TemplateCatalog $catalog, #[CurrentTeam] Team $team): Response
    {
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
            // Owners and admins connect providers (docs/19 §4); others see who to ask.
            'canConnectGit' => Gate::allows('create', [GitConnection::class, $team]),
            'templates' => $catalog->summaries(),
            'categories' => TemplateCompiler::CATEGORIES,
            'buildServerOnline' => $this->hasBuildServer($team),
            'engines' => $engines->options(),
        ]);
    }

    /**
     * "Paste a Git URL" (docs/19 §4.3): grammar, branches over smart HTTP and
     * the matching template. Nothing is stored.
     */
    public function checkUrl(Request $request, CheckPublicRepository $check, #[CurrentTeam] Team $team): JsonResponse
    {
        Gate::authorize('create', [Project::class, $team]);
        $data = PublicRepositoryUrlData::validateAndCreate(['url' => $request->query('url')]);

        return new JsonResponse($check->handle($team, $data->url));
    }

    /**
     * Continue with a pasted public repository: the picker row the import
     * flow goes on with (a plain git connection, reused per URL).
     */
    public function addUrl(Request $request, PublicRepositoryUrlData $data, #[CurrentUser] User $user, AddPublicRepository $add, #[CurrentTeam] Team $team): JsonResponse
    {
        Gate::authorize('create', [Project::class, $team]);
        Gate::authorize('addPublic', [GitConnection::class, $team]);

        $repository = $add->handle($team, $user, $data->url, $this->auditActor($request, $user));

        return new JsonResponse($repository->pickerData());
    }

    /**
     * The apps of a repository branch and the databases they probably use.
     */
    public function inspect(RepositoryBranchData $data, RepositoryInspector $inspector, #[CurrentTeam] Team $team): JsonResponse
    {
        Gate::authorize('create', [Project::class, $team]);
        $repository = $this->repository($data->repository, $team);
        $branch = $data->branch;

        try {
            return new JsonResponse($inspector->inspect($repository, $branch));
        } catch (RepositoryFileUnavailable $e) {
            $cause = $e->getPrevious();

            return match (true) {
                $cause instanceof GitHubRequestFailed => new JsonResponse(['message' => $cause->isNotFound()
                    ? "GitHub doesn’t know the branch {$branch}."
                    : 'GitHub didn’t answer. Try again in a moment.'], $cause->isNotFound() ? 422 : 502),
                $cause instanceof GitProviderRequestFailed => new JsonResponse(['message' => $cause->isNotFound()
                    ? "The repository has no branch {$branch}."
                    : 'The git server didn’t answer. Try again in a moment.'], $cause->isNotFound() ? 422 : 502),
                default => new JsonResponse(['message' => $e->getMessage()], 502),
            };
        }
    }

    /**
     * "We detected…" per app, before the project exists (polled while pending).
     */
    public function planPreview(PlanPreviewInputData $data, PlanPreviewer $previewer, #[CurrentTeam] Team $team): JsonResponse
    {
        Gate::authorize('create', [Project::class, $team]);
        $repository = $this->repository($data->repository, $team);

        return new JsonResponse($previewer->previewForTeam($repository->repositoryTeamId(), $repository, $data->branch, $data->rootDirectory ?? '', $data->retry));
    }

    public function store(Request $request, ImportRepositoryData $data, #[CurrentUser] User $user, ImportRepository $import, DeployEnvironment $deploy, #[CurrentTeam] Team $team): RedirectResponse
    {
        Gate::authorize('create', [Project::class, $team]);

        $actor = $this->auditActor($request, $user);
        $project = $import->handle($team, $data, $user, $actor);
        $production = $project->production()->sole();

        if (! $data->deploy) {
            Toast::success("{$project->name} is ready. Set its variables, then deploy.");
        } elseif (Gate::allows('deploy', $production)) {
            try {
                $deploy->handle($production, $user, $actor, 'Imported from '.$this->repositoryName($data->repository, $team), DeploymentTrigger::Config);
                Toast::success("{$project->name} is deploying.");
            } catch (DomainRuleViolation|UnresolvedReferences $e) {
                Toast::warning("{$project->name} is ready but didn’t deploy: {$e->getMessage()}");
            }
        }

        return to_route('projects.canvas', [$project, $production]);
    }

    private function repository(string $publicId, Team $team): SourceRepository
    {
        $repository = $this->providers->repository($team, $publicId);

        abort_if(! $repository instanceof SourceRepository, 404);
        Gate::authorize('view', $repository);

        return $repository;
    }

    private function repositoryName(string $publicId, Team $team): string
    {
        return $this->providers->repository($team, $publicId)?->repositoryFullName() ?? 'a repository';
    }

    private function hasBuildServer(Team $team): bool
    {
        return Server::query()
            ->where('team_id', $team->id)
            ->onlineBuilders()
            ->exists();
    }
}
