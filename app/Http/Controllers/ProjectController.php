<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Models\Project;
use App\Models\Team;
use App\Services\GitHub;
use App\Settings\InstanceSettings;
use Illuminate\Encryption\Encrypter;
use Inertia\Inertia;

use function Illuminate\Log\log;

class ProjectController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Team $team)
    {
        return redirect()->route('teams.show', $team->slug);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Team $team)
    {
        if (empty($team->github_token)) {
            return Inertia::render('teams/github');
        }

        $github = new GitHub($team);

        return Inertia::render('projects/create', [
            'repositories' => $github->getRepositories(),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreProjectRequest $request, Team $team, InstanceSettings $settings)
    {
        $slug = getUniqueSlug($request->name, 'projects');

        $project = $team->projects()->create([
            'name' => $request->name,
            'slug' => $slug,
            'repository' => $request->repository,
        ]);

        $appKey = 'base64:'.base64_encode(
            Encrypter::generateKey('AES-256-CBC')
        );
        $project->environments()->create([
            'name' => 'Production',
            'type' => 'production',
            'domains' => [
                getCloudDomain($slug, 'production'),
            ],
            'environment_variables' => [
                'APP_KEY' => $appKey,
            ],
        ]);

        if ($settings->publicly_accessible || config('app.env') === 'local') {
            log('Setting up GitHub webhook for project: '.$project->name);
            $github = new GitHub($team);
            $github->setupWebhook($project->repository);
        }

        return redirect()->to(frontendRoute('teams.projects.show', $project));
    }

    /**
     * Display the specified resource.
     */
    public function show(string $team, Project $project)
    {
        return redirect()->to(frontendRoute('teams.projects.environments.show', $project->environments->first()));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Project $project)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateProjectRequest $request, Team $team, Project $project)
    {
        $project->update($request->validated());

        return back()->with('success', 'Project updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Project $project)
    {
        //
    }
}
