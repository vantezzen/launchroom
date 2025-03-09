<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProjectEnvironmentRequest;
use App\Http\Requests\UpdateProjectEnvironmentRequest;
use App\Models\ProjectEnvironment;
use Inertia\Inertia;

class ProjectEnvironmentController extends Controller
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
    public function store(StoreProjectEnvironmentRequest $request, string $teamSlug, string $projectSlug)
    {
        $team = auth()->user()->teams()->where('slug', $teamSlug)->firstOrFail();
        $project = $team->projects()->where('slug', $projectSlug)->firstOrFail();
        $projectEnvironment = $project->environments()->create($request->validated());

        return redirect()->route('teams.projects.environments.show', [
            'team' => $teamSlug,
            'project' => $projectSlug,
            'environment' => $projectEnvironment->id,
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(ProjectEnvironment $projectEnvironment)
    {
        return Inertia::render('environments/show');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(ProjectEnvironment $projectEnvironment)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateProjectEnvironmentRequest $request, string $teamSlug, string $projectSlug, ProjectEnvironment $projectEnvironment)
    {
        $projectEnvironment->update($request->validated());

        return redirect()->route('teams.projects.environments.show', [
            'team' => $teamSlug,
            'project' => $projectSlug,
            'environment' => $projectEnvironment->first()->id,
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ProjectEnvironment $projectEnvironment)
    {
        //
    }

    public function metrics()
    {
        return Inertia::render('environments/metrics');
    }
}
