<?php

declare(strict_types=1);

namespace App\Http\Controllers\AppUpdates;

use App\Data\AppUpdates\ServiceUpdateData;
use App\Http\Controllers\Controller;
use App\Http\Routing\CurrentTeam;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Queries\AppUpdates\ServiceUpdates;
use App\Support\PublicId;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * App updates of the team (D106, docs/04 §14.4): image services with a newer
 * release or an update in flight, for the Projects page, or one project's
 * (`?project=prj_…`, a project of the team, else 404).
 */
final class AppUpdateListController extends Controller
{
    /**
     * @return list<ServiceUpdateData>
     */
    public function index(Request $request, #[CurrentUser] User $user, #[CurrentTeam] Team $team, ServiceUpdates $updates): array
    {
        Gate::authorize('view', $team);

        $project = $request->query('project');
        $projectId = null;

        if ($project !== null) {
            $projectId = is_string($project) && PublicId::isValid($project, 'prj')
                ? Project::query()->where('team_id', $team->id)->where('public_id', $project)->value('id')
                : null;
            abort_if(! is_int($projectId), 404);
        }

        return $updates->forTeam($team, $user, $projectId);
    }
}
