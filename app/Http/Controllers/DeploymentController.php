<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDeploymentRequest;
use App\Http\Requests\UpdateDeploymentRequest;
use App\Models\Deployment;
use App\Models\Environment;

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
        $environment = Environment::find($request->environment_id);
        $deployment = $environment->startDeployment();

        return redirect()->to(frontendRoute('teams.projects.environments.deployments.show', $deployment));
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
