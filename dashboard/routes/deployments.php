<?php

declare(strict_types=1);

use App\Http\Controllers\CommandPalette\CommandPaletteController;
use App\Http\Controllers\Deployments\DeploymentController;
use App\Http\Controllers\Deployments\DeploymentLogsController;
use App\Http\Controllers\Deployments\ProjectDeploymentsController;
use App\Http\Controllers\Deployments\ServiceDeploymentsController;
use App\Http\Controllers\Deployments\ServiceLifecycleController;
use App\Http\Controllers\StagedChanges\StagedChangesController;
use App\Http\Controllers\Variables\RawVariablesController;
use App\Http\Controllers\Variables\RevealVariableController;
use App\Http\Controllers\Variables\ServiceVariablesController;
use App\Http\Controllers\Variables\SharedVariableUsageController;
use App\Http\Controllers\Variables\VariableImportController;
use App\Support\PublicId;
use Illuminate\Support\Facades\Route;

/*
| Deployments, logs, variables, staged changes and the command palette
| (docs/08 §2–4). Registered from bootstrap/app.php. Ids are constrained to
| their grammar (see routes/projects.php). JSON endpoints used by the
| service panel tabs live under `/p/{project}/{environment}/-/`, which can't
| collide with page URLs. Controllers check that nested resources belong
| together (ScopesProjectRoutes) and authorize every action.
*/

Route::middleware(['web', 'auth', 'verified'])
    ->where([
        'project' => PublicId::routePattern('prj'),
        'environment' => PublicId::routePattern('env'),
        'service' => PublicId::routePattern('svc'),
        'deployment' => PublicId::routePattern('dpl'),
        'variable' => PublicId::routePattern('var'),
    ])
    ->group(function (): void {
        Route::get('-/palette', CommandPaletteController::class)->name('palette.index');

        Route::get('p/{project}/deployments', ProjectDeploymentsController::class)->name('deployments.project');

        Route::get('p/{project}/{environment}/d/{deployment}', [DeploymentController::class, 'show'])->name('deployments.show');
        Route::get('p/{project}/{environment}/d/{deployment}/logs', DeploymentLogsController::class)
            ->middleware('throttle:600,1')
            ->name('deployments.logs');
        Route::post('p/{project}/{environment}/d/{deployment}/rollback', [DeploymentController::class, 'rollback'])->name('deployments.rollback');
        Route::post('p/{project}/{environment}/d/{deployment}/redeploy', [DeploymentController::class, 'redeploy'])->name('deployments.redeploy');
        Route::post('p/{project}/{environment}/d/{deployment}/cancel', [DeploymentController::class, 'cancel'])->name('deployments.cancel');
        // One-click fix of an explained failure.
        Route::post('p/{project}/{environment}/d/{deployment}/fix', [DeploymentController::class, 'fix'])->name('deployments.fix');

        Route::get('p/{project}/{environment}/changes', [StagedChangesController::class, 'show'])->name('staged-changes.show');
        Route::post('p/{project}/{environment}/changes/discard', [StagedChangesController::class, 'discard'])->name('staged-changes.discard');
        Route::post('p/{project}/{environment}/deploy', [StagedChangesController::class, 'deploy'])->name('staged-changes.deploy');
        Route::get('p/{project}/{environment}/-/changes', [StagedChangesController::class, 'summary'])->name('staged-changes.summary');

        Route::get('p/{project}/{environment}/-/services/{service}/deployments', [ServiceDeploymentsController::class, 'index'])->name('deployments.service');
        Route::post('p/{project}/{environment}/-/services/{service}/redeploy', [ServiceDeploymentsController::class, 'redeploy'])->name('deployments.service.redeploy');
        // Service lifecycle (D68): deploy the latest version, restart, stop, start.
        Route::post('p/{project}/{environment}/-/services/{service}/deploy', [ServiceLifecycleController::class, 'deploy'])->name('services.lifecycle.deploy');
        Route::post('p/{project}/{environment}/-/services/{service}/restart', [ServiceLifecycleController::class, 'restart'])->name('services.lifecycle.restart');
        Route::post('p/{project}/{environment}/-/services/{service}/stop', [ServiceLifecycleController::class, 'stop'])->name('services.lifecycle.stop');
        Route::post('p/{project}/{environment}/-/services/{service}/start', [ServiceLifecycleController::class, 'start'])->name('services.lifecycle.start');

        Route::get('p/{project}/{environment}/-/services/{service}/variables', [ServiceVariablesController::class, 'index'])->name('variables.index');
        Route::post('p/{project}/{environment}/-/services/{service}/variables', [ServiceVariablesController::class, 'store'])->name('variables.store');
        Route::patch('p/{project}/{environment}/-/variables/{variable}', [ServiceVariablesController::class, 'update'])->name('variables.update');
        Route::delete('p/{project}/{environment}/-/variables/{variable}', [ServiceVariablesController::class, 'destroy'])->name('variables.destroy');
        Route::post('p/{project}/{environment}/-/variables/{variable}/reveal', RevealVariableController::class)
            ->middleware('throttle:secret-reveal')
            ->name('variables.reveal');
        Route::post('p/{project}/{environment}/-/services/{service}/variables/import/preview', [VariableImportController::class, 'preview'])->name('variables.import.preview');
        Route::post('p/{project}/{environment}/-/services/{service}/variables/import', [VariableImportController::class, 'store'])->name('variables.import');
        Route::get('p/{project}/{environment}/-/services/{service}/variables/raw', [RawVariablesController::class, 'show'])->name('variables.raw');
        Route::put('p/{project}/{environment}/-/services/{service}/variables/raw', [RawVariablesController::class, 'update'])->name('variables.raw.update');
        Route::put('p/{project}/{environment}/-/services/{service}/shared/{variable}', SharedVariableUsageController::class)->name('variables.shared.usage');
    });
