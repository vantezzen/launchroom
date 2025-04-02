<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEnvironmentRequest;
use App\Http\Requests\UpdateEnvironmentRequest;
use App\Models\Environment;
use App\Services\DeploymentManager;
use Inertia\Inertia;

class EnvironmentController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreEnvironmentRequest $request, string $teamSlug, string $projectSlug)
    {
        $team = auth()->user()->teams()->where('slug', $teamSlug)->firstOrFail();
        $project = $team->projects()->where('slug', $projectSlug)->firstOrFail();
        $projectEnvironment = $project->environments()->create($request->validated());

        return redirect()->to(frontendRoute('teams.projects.environments.show', $projectEnvironment));
    }

    /**
     * Display the specified resource.
     */
    public function show(Environment $projectEnvironment)
    {
        return Inertia::render('environments/show');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Environment $projectEnvironment)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateEnvironmentRequest $request, Environment $projectEnvironment)
    {
        $projectEnvironment = $projectEnvironment->first();
        $projectEnvironment->update($request->validated());

        return redirect()->to(frontendRoute('teams.projects.environments.show', $projectEnvironment));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Environment $projectEnvironment)
    {
        //
    }

    public function metrics(Environment $projectEnvironment)
    {
        $projectEnvironment = $projectEnvironment->first();

        $usage = $projectEnvironment->processingUsages()->latest(100)->get();

        return Inertia::render('environments/metrics', compact('usage'));
    }

    public function logs(Environment $projectEnvironment)
    {
        $projectEnvironment = $projectEnvironment->first();
        if (! $projectEnvironment->deployments()->exists()) {
            return Inertia::render('environments/logs', ['logs' => '', 'error' => 'no_deployments']);
        }
        $deploymentManager = new DeploymentManager($projectEnvironment->deployments()->latest()->first());
        $logs = $deploymentManager->getLogs();

        return Inertia::render('environments/logs', compact('logs'));
    }
}
