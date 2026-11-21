<?php

declare(strict_types=1);

namespace App\Queries\Errors;

use App\Data\Errors\ErrorSuggestionData;
use App\Enums\Http\MissingResource;
use App\Http\Routing\TeamScopedBindings;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Support\Links;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate as GateFacade;

/**
 * "Did you mean" for a 404 on a resource path (docs/08 §24).
 *
 * Walks `/p/{project}/{environment}/s/{service}` and `/servers/{server}`
 * level by level through the route binder's own scoping
 * ({@see TeamScopedBindings::find()}) plus the `view` policy, and stops at
 * the first level that doesn't resolve. Suggestions are siblings at that
 * level the user may view. A row in another team resolves exactly like a
 * missing one, so the answer never tells the two apart.
 */
final readonly class MissingResourceSuggestions
{
    private const int LIMIT = 4;

    /**
     * @return array{missing: ?MissingResource, suggestions: list<ErrorSuggestionData>}
     */
    public function for(User $user, string $path): array
    {
        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn (string $segment): bool => $segment !== ''));
        $gate = GateFacade::forUser($user);

        return match ($segments[0] ?? null) {
            'p' => $this->project($user, $gate, $segments),
            'servers' => $this->server($user, $gate, $segments[1] ?? null),
            default => self::none(),
        };
    }

    /**
     * @param  list<string>  $segments
     * @return array{missing: ?MissingResource, suggestions: list<ErrorSuggestionData>}
     */
    private function project(User $user, Gate $gate, array $segments): array
    {
        $project = $this->resolve($user, $gate, 'project', $segments[1] ?? null);

        if (! $project instanceof Project) {
            return self::missing(MissingResource::Project, $this->projects($user, $gate));
        }

        $environmentId = $segments[2] ?? null;

        if ($environmentId === null || ! str_starts_with($environmentId, Environment::publicIdPrefix().'_')) {
            return self::none();
        }

        $environment = $this->resolve($user, $gate, 'environment', $environmentId);

        if (! $environment instanceof Environment || $environment->project_id !== $project->id) {
            return self::missing(MissingResource::Environment, $this->environments($project, $gate));
        }

        if (($segments[3] ?? null) !== 's' || ! isset($segments[4])) {
            return self::none();
        }

        $service = $this->resolve($user, $gate, 'service', $segments[4]);
        $present = $service instanceof Service
            && $service->project_id === $project->id
            && $environment->serviceInstances()->current()->where('service_id', $service->id)->exists();

        return $present ? self::none() : self::missing(MissingResource::Service, $this->services($environment, $gate));
    }

    /**
     * @return array{missing: ?MissingResource, suggestions: list<ErrorSuggestionData>}
     */
    private function server(User $user, Gate $gate, ?string $id): array
    {
        if ($id === null || ! str_starts_with($id, Server::publicIdPrefix().'_')) {
            return self::none();
        }

        if ($this->resolve($user, $gate, 'server', $id) instanceof Server) {
            return self::none();
        }

        $teamId = $user->getAttributes()['current_team_id'] ?? null;
        $servers = Server::query()->whereIn('team_id', $user->teamIdsQuery())->where('team_id', $teamId)->orderBy('name')->limit(self::LIMIT)->get();

        return self::missing(MissingResource::Server, $servers
            ->filter(static fn (Server $server): bool => $gate->allows('view', $server))
            ->map(static fn (Server $server): ErrorSuggestionData => new ErrorSuggestionData($server->name, Links::server($server)))
            ->pipe(self::asList(...)));
    }

    /**
     * The user's most recently changed projects in their current team.
     *
     * @return list<ErrorSuggestionData>
     */
    private function projects(User $user, Gate $gate): array
    {
        $teamId = $user->getAttributes()['current_team_id'] ?? null;

        return Project::query()
            ->whereIn('team_id', $user->teamIdsQuery())
            ->where('team_id', $teamId)
            ->latest('updated_at')
            ->limit(self::LIMIT)
            ->get()
            ->filter(static fn (Project $project): bool => $gate->allows('view', $project))
            ->map(static fn (Project $project): ErrorSuggestionData => new ErrorSuggestionData($project->name, Links::project($project)))
            ->pipe(self::asList(...));
    }

    /**
     * @return list<ErrorSuggestionData>
     */
    private function environments(Project $project, Gate $gate): array
    {
        return $project->environments()
            ->inDisplayOrder()
            ->limit(self::LIMIT)
            ->get()
            ->filter(static fn (Environment $environment): bool => $gate->allows('view', $environment))
            ->map(static fn (Environment $environment): ErrorSuggestionData => new ErrorSuggestionData($environment->name, Links::environment($environment), $project->name))
            ->pipe(self::asList(...));
    }

    /**
     * @return list<ErrorSuggestionData>
     */
    private function services(Environment $environment, Gate $gate): array
    {
        return $environment->serviceInstances()
            ->current()
            ->with('service')
            ->get()
            ->filter(static fn (ServiceInstance $instance): bool => $gate->allows('view', $instance->service))
            ->sortBy(static fn (ServiceInstance $instance): string => $instance->service->name)
            ->take(self::LIMIT)
            ->map(static fn (ServiceInstance $instance): ErrorSuggestionData => new ErrorSuggestionData($instance->service->name, Links::service($instance), $environment->name))
            ->pipe(self::asList(...));
    }

    /** A row behind a raw public id, scoped like its route binding and viewable by the user. */
    private function resolve(User $user, Gate $gate, string $parameter, ?string $id): ?Model
    {
        $model = $id === null ? null : TeamScopedBindings::find($parameter, $id, $user);

        return $model instanceof Model && $gate->allows('view', $model) ? $model : null;
    }

    /**
     * @param  Collection<int, ErrorSuggestionData>  $suggestions
     * @return list<ErrorSuggestionData>
     */
    private static function asList(Collection $suggestions): array
    {
        return array_values($suggestions->all());
    }

    /**
     * @param  list<ErrorSuggestionData>  $suggestions
     * @return array{missing: ?MissingResource, suggestions: list<ErrorSuggestionData>}
     */
    private static function missing(MissingResource $missing, array $suggestions): array
    {
        return ['missing' => $missing, 'suggestions' => $suggestions];
    }

    /**
     * @return array{missing: ?MissingResource, suggestions: list<ErrorSuggestionData>}
     */
    private static function none(): array
    {
        return ['missing' => null, 'suggestions' => []];
    }
}
