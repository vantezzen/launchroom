<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreServiceRequest;
use App\Http\Requests\UpdateServiceRequest;
use App\Models\ProjectEnvironment;
use App\Models\Service;
use App\Services\DeploymentManager;

class ServiceController extends Controller
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
    public function store(StoreServiceRequest $request, string $team, string $project, string $environment)
    {
        $class = DeploymentManager::SERVICES[$request->type];
        if (! class_exists($class)) {
            abort(400, 'Invalid service type');
        }

        $env = ProjectEnvironment::findOrFail($environment);
        $service = $class::createServiceInEnvironment($env, $request->validated());

        return redirect()->route('teams.projects.environments.show', [$team, $project, $env]);
    }

    /**
     * Display the specified resource.
     */
    public function show(Service $service)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Service $service)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateServiceRequest $request, Service $service)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Service $service)
    {
        //
    }
}
