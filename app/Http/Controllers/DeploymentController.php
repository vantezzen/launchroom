<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDeploymentRequest;
use App\Http\Requests\UpdateDeploymentRequest;
use App\Jobs\DeployJob;
use App\Models\Deployment;
use App\Models\ProjectEnvironment;
use App\Services\GitHub;

class DeploymentController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return inertia('deployments/index');
    }

    /**
     * Trigger a new deployment.
     */
    public function store(StoreDeploymentRequest $request)
    {
        $environment = ProjectEnvironment::find($request->project_environment_id);
        $project = $environment->project;
        $team = $project->team;

        $github = new GitHub(decrypt($team->github_token));

        $deployment = Deployment::create([
            'project_environment_id' => $environment->id,
            'commit_hash' => $github->getLatestCommitHashInRepository($environment->project->repository),
            'status' => 'pending',
            'output' => '',
            'is_latest' => true,
        ]);

        $deployment->addLogSection('Deployment started', 'The deployment process has started.');

        DeployJob::dispatch($deployment);

        return response()->redirectTo(route('teams.projects.environments.deployments.show', [
            $team->slug,
            $project->slug,
            $environment->id,
            $deployment->id,
        ]));
    }

    /**
     * Display the specified resource.
     */
    public function show(string $team, string $project, string $env, Deployment $Deployment)
    {
        return inertia('deployments/show', [
            'deployment' => $Deployment->load('environment.project.team'),
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Deployment $Deployment)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateDeploymentRequest $request, Deployment $Deployment)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Deployment $Deployment)
    {
        //
    }
}
