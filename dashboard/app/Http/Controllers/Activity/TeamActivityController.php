<?php

declare(strict_types=1);

namespace App\Http\Controllers\Activity;

use App\Data\Activity\ActivityFiltersData;
use App\Data\Canvas\ProjectLinkData;
use App\Data\Security\AuditVerificationData;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Teams\TeamSettingsController;
use App\Models\Project;
use App\Models\User;
use App\Queries\TeamActivity;
use App\Services\Audit\AuditVerificationStatus;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * `/activity`: the team-wide activity feed in the sidebar (direction §3.1,
 * §5.4): deploys, rollbacks, promotions and changes across the current
 * team's projects, filterable by project and kind.
 */
final class TeamActivityController extends Controller
{
    public function __invoke(Request $request, #[CurrentUser] User $user, TeamActivity $activity, AuditVerificationStatus $verification): Response
    {
        $team = TeamSettingsController::currentTeam($user);
        Gate::authorize('viewAny', [Project::class, $team]);
        $filters = ActivityFiltersData::validateAndCreate($request->query());

        $projects = Project::query()
            ->where('team_id', $team->id)
            ->orderBy('name')
            ->get(['public_id', 'name']);

        // A project of another team in the query reads as no project at all.
        if ($filters->project !== null && $projects->doesntContain('public_id', $filters->project)) {
            $filters->project = null;
        }

        return Inertia::render('activity', [
            'projects' => $projects
                ->map(static fn (Project $project): ProjectLinkData => new ProjectLinkData($project->public_id, $project->name))
                ->values()
                ->all(),
            'entries' => $activity->get($team->id, $filters),
            'filters' => ['project' => $filters->project, 'kind' => $filters->kind],
            // "Audit log verified ✓ up to <time>" (docs/09 R34): instance-wide, no details.
            'auditVerification' => $user->is_instance_owner === true
                ? AuditVerificationData::fromStatus($verification->current())
                : AuditVerificationData::fromStatus($verification->current())?->withoutDetails(),
        ]);
    }
}
