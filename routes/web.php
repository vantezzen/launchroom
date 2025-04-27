<?php

use App\Http\Controllers\DeploymentController;
use App\Http\Controllers\EnvironmentController;
use App\Http\Controllers\EnvironmentSettingsController;
use App\Http\Controllers\MetricsController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\TeamController;
use App\Http\Middleware\RequireSetupDone;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';

Route::resource('setup', SetupController::class)->only(['index', 'store']);

Route::middleware(['auth', 'verified', RequireSetupDone::class])->group(function () {
    // Views
    Route::resource('teams', TeamController::class)->parameters([
        'teams' => 'team:slug',
    ]);
    Route::resource('teams.projects', ProjectController::class)->parameters([
        'projects' => 'project:slug',
        'teams' => 'team:slug',
    ]);
    Route::resource('teams.projects.environments', EnvironmentController::class)->parameters([
        'projects' => 'project:slug',
        'teams' => 'team:slug',
    ]);
    Route::get('teams/{team:slug}/projects/{project:slug}/environments/{environment}/metrics', [EnvironmentController::class, 'metrics']);
    Route::get('teams/{team:slug}/projects/{project:slug}/environments/{environment}/logs', [EnvironmentController::class, 'logs']);

    // Environment Settings Routes
    Route::get('teams/{team:slug}/projects/{project:slug}/environments/{environment}/settings', [EnvironmentSettingsController::class, 'show'])->name('teams.projects.environments.settings');
    Route::patch('teams/{team:slug}/projects/{project:slug}/environments/{environment}/settings', [EnvironmentSettingsController::class, 'update'])->name('teams.projects.environments.settings.update');
    Route::patch('teams/{team:slug}/projects/{project:slug}', [ProjectController::class, 'update'])->name('teams.projects.update');
    Route::post('teams/{team:slug}/projects/{project:slug}/transfer', [EnvironmentSettingsController::class, 'transferProject'])->name('teams.projects.transfer');
    Route::delete('teams/{team:slug}/projects/{project:slug}', [EnvironmentSettingsController::class, 'destroy'])->name('teams.projects.delete');

    Route::resource('teams.projects.environments.deployments', DeploymentController::class)->parameters([
        'projects' => 'project:slug',
        'teams' => 'team:slug',
    ]);
    Route::resource('teams.projects.environments.services', ServiceController::class)->parameters([
        'projects' => 'project:slug',
        'teams' => 'team:slug',
    ]);
    // API
    Route::resource('projects', ProjectController::class);
    Route::resource('services', ServiceController::class);
    Route::resource('environments', EnvironmentController::class);
    Route::resource('deployments', DeploymentController::class);

    Route::get('metrics', [MetricsController::class, 'index'])->name('metrics.index');

    Route::get('/', function () {
        if (Auth::user()->teams->count() > 0) {
            return redirect()->route('teams.show', Auth::user()->teams->first());
        }

        return Inertia::render('dashboard');
    })->name('home');
});
