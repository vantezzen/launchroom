<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateEnvironmentSettingsRequest;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Team;
use App\Services\DomainValidator;
use App\Settings\InstanceSettings;
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

    /**
     * Show the domain settings page.
     */
    public function showDomains(Team $team, Project $project, Environment $environment)
    {
        // Load relationships
        $project->load('team');
        $environment->load('project');

        // Prepare domain validation
        $validator = app(DomainValidator::class);
        $validation = $validator->validateEnvironmentDomains($environment);
        $settings = app(InstanceSettings::class);

        // Set shared data
        $sharedProps = [
            'currentTeam' => $team,
            'currentProject' => $project,
            'currentEnvironment' => $environment,
            'validation' => $validation,
            'server_ip' => baseIp(),
            'is_public' => $settings->publicly_accessible || config('app.env') === 'local',
        ];

        return Inertia::render('environments/settings-domains', $sharedProps);
    }

    /**
     * Update the environment domains.
     */
    public function updateDomains(Request $request, Team $team, Project $project, Environment $environment)
    {
        $request->validate([
            'domains' => ['required', 'array'],
            'domains.*' => ['required', 'string', 'max:255'],
        ]);

        // Get current domains
        $currentDomains = $environment->domains;

        // Ensure the first domain (default) is preserved
        $newDomains = $request->domains;
        if (! empty($currentDomains)) {
            $newDomains[0] = $currentDomains[0];
        }

        $environment->update([
            'domains' => $newDomains,
        ]);

        return back()->with('success', 'Domains updated successfully.');
    }

    /**
     * Validate environment domains.
     */
    public function validateDomains(Team $team, Project $project, Environment $environment)
    {
        $validator = app(DomainValidator::class);
        $validation = $validator->validateEnvironmentDomains($environment);
        $settings = app(InstanceSettings::class);

        return response()->json([
            'domains' => $environment->domains,
            'validation' => $validation,
            'server_ip' => baseIp(),
            'is_public' => $settings->publicly_accessible || config('app.env') === 'local',
        ]);
    }
}
