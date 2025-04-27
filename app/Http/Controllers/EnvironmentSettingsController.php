<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateEnvironmentSettingsRequest;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class EnvironmentSettingsController extends Controller
{
    /**
     * Show the environment settings page.
     */
    public function show(Team $team, Project $project, Environment $environment)
    {
        // Load relationships
        $project->load('team');
        $environment->load('project');

        // Get user's teams for team transfer
        $userTeams = Auth::user()->teams()->where('teams.id', '!=', $team->id)->get();

        // Set shared data
        $sharedProps = [
            'currentTeam' => $team,
            'currentProject' => $project,
            'currentEnvironment' => $environment,
            'userTeams' => $userTeams,
        ];

        return Inertia::render('environments/settings', $sharedProps);
    }

    /**
     * Update the environment settings.
     */
    public function update(UpdateEnvironmentSettingsRequest $request, Team $team, Project $project, Environment $environment)
    {
        $environment->update($request->validated());

        return back()->with('success', 'Environment updated successfully.');
    }

    /**
     * Transfer the project to another team.
     */
    public function transferProject(Request $request, Team $team, Project $project)
    {
        $request->validate([
            'team_id' => ['required', 'string', 'exists:teams,id'],
        ]);

        $targetTeam = Team::find($request->team_id);

        if (! $targetTeam) {
            throw ValidationException::withMessages([
                'team_id' => 'Team not found',
            ]);
        }

        // Check if user has permission in target team
        if (! $targetTeam->users->contains(Auth::user())) {
            throw ValidationException::withMessages([
                'team_id' => 'You do not have permission to transfer to this team',
            ]);
        }

        $project->team_id = $targetTeam->id;
        $project->save();

        return redirect()->route('teams.projects.show', [
            'team' => $targetTeam->slug,
            'project' => $project->slug,
        ])->with('success', 'Project transferred successfully.');
    }

    /**
     * Delete the project.
     */
    public function destroy(Request $request, Team $team, Project $project)
    {
        $request->validate([
            'project_name' => ['required', 'string', 'in:'.$project->name],
        ]);

        // Delete all environments and their deployments
        foreach ($project->environments as $environment) {
            foreach ($environment->deployments as $deployment) {
                $deployment->delete();
            }
            $environment->delete();
        }

        $project->delete();

        return redirect()->route('teams.show', $team->slug)
            ->with('success', 'Project deleted successfully.');
    }
}
