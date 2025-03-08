<?php

use App\Http\Controllers\DeploymentController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectEnvironmentController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\TeamController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('teams', TeamController::class)->parameters([
        'teams' => 'team:slug',
    ]);
    Route::resource('teams.projects', ProjectController::class)->parameters([
        'projects' => 'project:slug',
        'teams' => 'team:slug',
    ]);
    Route::resource('teams.projects.environments', ProjectEnvironmentController::class)->parameters([
        'projects' => 'project:slug',
        'teams' => 'team:slug',
    ]);
    Route::resource('teams.projects.environments.deployments', DeploymentController::class)->parameters([
        'projects' => 'project:slug',
        'teams' => 'team:slug',
    ]);
    Route::resource('teams.projects.environments.services', ServiceController::class)->parameters([
        'projects' => 'project:slug',
        'teams' => 'team:slug',
    ]);

    Route::resource('deployments', DeploymentController::class);

    Route::get('/', function () {
        if (auth()->user()->teams->count() > 0) {
            return redirect()->route('teams.show', auth()->user()->teams->first());
        }

        return Inertia::render('dashboard');
    })->name('home');
});
