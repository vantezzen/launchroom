<?php

declare(strict_types=1);

use App\Http\Controllers\Dockerfiles\DockerfileGenerationSettingsController;
use App\Http\Controllers\Dockerfiles\ServiceDockerfileGenerationController;
use App\Http\Middleware\RequireRecentPassword;
use App\Support\PublicId;
use Illuminate\Support\Facades\Route;

/*
| Generate a Dockerfile (docs/20, D48). Loaded by
| App\Providers\DockerfileGenerationServiceProvider with the `web` group.
*/

Route::middleware(['web', 'auth', 'verified'])->group(function (): void {
    Route::get('settings/dockerfile-generation', [DockerfileGenerationSettingsController::class, 'edit'])
        ->name('dockerfile-generation.edit');
    Route::put('settings/dockerfile-generation', [DockerfileGenerationSettingsController::class, 'update'])
        ->middleware([RequireRecentPassword::class, 'throttle:10,1'])
        ->name('dockerfile-generation.update');
    Route::put('settings/dockerfile-generation/team', [DockerfileGenerationSettingsController::class, 'team'])
        ->middleware('throttle:20,1')
        ->name('dockerfile-generation.team');

    Route::post('p/{project}/{environment}/s/{service}/dockerfile-generations', [ServiceDockerfileGenerationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('projects.services.dockerfile-generations.store');
    Route::get('p/{project}/{environment}/s/{service}/dockerfile-generations/{generation}', [ServiceDockerfileGenerationController::class, 'show'])
        ->where('generation', PublicId::routePattern('dfg'))
        ->middleware('throttle:120,1')
        ->name('projects.services.dockerfile-generations.show');
    Route::post('p/{project}/{environment}/s/{service}/dockerfile-generations/{generation}/use', [ServiceDockerfileGenerationController::class, 'use'])
        ->where('generation', PublicId::routePattern('dfg'))
        ->middleware('throttle:20,1')
        ->name('projects.services.dockerfile-generations.use');
    Route::post('p/{project}/{environment}/s/{service}/dockerfile-generations/{generation}/pull-request', [ServiceDockerfileGenerationController::class, 'pullRequest'])
        ->where('generation', PublicId::routePattern('dfg'))
        ->middleware('throttle:6,1')
        ->name('projects.services.dockerfile-generations.pull-request');
    Route::delete('p/{project}/{environment}/s/{service}/dockerfile-generations/{generation}', [ServiceDockerfileGenerationController::class, 'destroy'])
        ->where('generation', PublicId::routePattern('dfg'))
        ->middleware('throttle:20,1')
        ->name('projects.services.dockerfile-generations.destroy');
});
