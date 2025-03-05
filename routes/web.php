<?php

use App\Http\Controllers\DeploymentController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectEnvironmentController;
use App\Http\Controllers\TeamController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('welcome');
})->name('home');


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
    Route::resource('environments', ProjectEnvironmentController::class);
    Route::resource('deployments', DeploymentController::class);


    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');
});