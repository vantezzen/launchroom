<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\AppUpdates\StartAppUpdate;
use App\Data\Api\AppUpdateResource;
use App\Data\Api\ListAppUpdatesQuery;
use App\Data\AppUpdates\ServiceUpdateData;
use App\Data\AppUpdates\StartAppUpdateData;
use App\Http\Api\ApiOperation;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Routing\RouteScope;
use App\Models\AppUpdates\AppUpdate;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Models\User;
use App\Queries\AppUpdates\ServiceUpdates;
use App\Support\PublicId;
use App\Support\Refusal;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * App updates through the API (D106, docs/04 §14, docs/16 §2.2): the list
 * the Projects page shows, and the panel's Update with the same rules
 * (only a version the last check found, a major only with the release notes
 * read, refused while other changes are staged or a deploy runs).
 */
final class AppUpdateController extends Controller
{
    use ScopesApiRoutes;

    #[ApiOperation(id: 'listAppUpdates', summary: 'Image services with a newer release or an update in flight', tag: 'App updates', response: AppUpdateResource::class, list: true, query: ListAppUpdatesQuery::class, description: 'Updates in flight first, then majors and versions more than a year old. `project` narrows to one project. A scoped token sees its own project or service only. Services built from a repository never appear: they update with a deploy.')]
    public function index(ListAppUpdatesQuery $query, #[CurrentUser] User $user, ServiceUpdates $updates): JsonResponse
    {
        $team = $this->team($user);
        Gate::authorize('view', $team);

        $token = $this->token($user)->loadMissing('service:id,public_id');
        $projectId = $token->project_id;

        if ($query->project !== null) {
            $requested = PublicId::isValid($query->project, 'prj')
                ? Project::query()->where('team_id', $team->id)->where('public_id', $query->project)->value('id')
                : null;
            abort_if(! is_int($requested) || ($projectId !== null && $projectId !== $requested), 404);
            $projectId = $requested;
        }

        $service = $token->service?->public_id;
        $rows = array_values(array_filter(
            $updates->forTeam($team, $user, $projectId),
            static fn (ServiceUpdateData $row): bool => $service === null || $row->serviceId === $service,
        ));

        return ApiResponse::list(array_map($this->resource(...), $rows));
    }

    #[ApiOperation(id: 'applyAppUpdate', summary: 'Update an image service to a newer release', tag: 'App updates', response: AppUpdateResource::class, request: StartAppUpdateData::class, status: 202, description: 'Backs up the service\'s data first when backup storage is set up, then changes the image to `tag` (pinned to the digest the check saw) and deploys it, then watches it like any deploy and rolls back if it fails. `tag` must be one of the service\'s `candidates`; a `major` needs `releaseNotesRead: true`. Refused (422) while other changes are staged, a deployment runs, the service is stopped or held. The other environments of the project get the new image as a staged change. Needs `update` and `deploy`.')]
    public function apply(Request $request, Environment $environment, Service $service, StartAppUpdateData $data, #[CurrentUser] User $user, StartAppUpdate $start, ServiceUpdates $updates): JsonResponse
    {
        $instance = RouteScope::editable(RouteScope::instanceIn($environment, $service));
        Gate::authorize('create', [AppUpdate::class, $instance]);

        Refusal::orThrow($start->handle($instance, $data, $user, $this->auditActor($request, $user)));

        $update = $updates->for($instance->refresh(), $user);
        abort_unless($update instanceof ServiceUpdateData, 404);

        return ApiResponse::item($this->resource($update), 202);
    }

    private function resource(ServiceUpdateData $update): AppUpdateResource
    {
        return AppUpdateResource::fromUpdate($update, route('projects.services.show', [$update->projectId, $update->environmentId, $update->serviceId]));
    }
}
