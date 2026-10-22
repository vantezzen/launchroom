<?php

declare(strict_types=1);

use App\Http\Controllers\Environments\EnvironmentController;
use App\Http\Controllers\Environments\ProjectActivityController;
use App\Http\Controllers\Environments\PromotionController;
use App\Support\PublicId;
use Illuminate\Support\Facades\Route;

/*
| Environments (docs/06 §1–2), Compare & promote (docs/06 §6), staged
| production deployments, revert and the project activity list. Registered
| from bootstrap/app.php. Ids are constrained to their grammar (see
| routes/projects.php); controllers check that nested resources belong
| together and authorize every action.
*/

Route::middleware(['web', 'auth', 'verified'])
    ->where([
        'project' => PublicId::routePattern('prj'),
        'environment' => PublicId::routePattern('env'),
        'deployment' => PublicId::routePattern('dpl'),
        'revision' => PublicId::routePattern('rev'),
    ])
    ->group(function (): void {
        Route::patch('p/{project}/environments/{environment}', [EnvironmentController::class, 'update'])->name('environments.update');
        Route::delete('p/{project}/environments/{environment}', [EnvironmentController::class, 'destroy'])

            ->name('environments.destroy');
        Route::post('p/{project}/environments/{environment}/resume', [EnvironmentController::class, 'resume'])->name('environments.resume');
        Route::put('p/{project}/settings/secrets-policy', [EnvironmentController::class, 'secretsPolicy'])->name('environments.secrets-policy');

        Route::post('p/{project}/{environment}/revisions/{revision}/revert', [EnvironmentController::class, 'revert'])->name('environments.revert');
        Route::post('p/{project}/{environment}/d/{deployment}/promote', [EnvironmentController::class, 'promoteDeployment'])->name('environments.promote-deployment');

        Route::get('p/{project}/promote', [PromotionController::class, 'show'])->name('promotions.show');
        Route::post('p/{project}/promote', [PromotionController::class, 'store'])->name('promotions.store');
        Route::get('p/{project}/{environment}/-/promotion-hint', [PromotionController::class, 'hint'])
            ->middleware('throttle:60,1')
            ->name('promotions.hint');

        Route::get('p/{project}/activity', ProjectActivityController::class)->name('projects.activity');
    });
