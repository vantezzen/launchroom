<?php

declare(strict_types=1);

use App\Http\Controllers\Previews\PreviewAuthController;
use App\Http\Controllers\Previews\PreviewController;
use App\Http\Controllers\Previews\PreviewSettingsController;
use App\Support\PublicId;
use Illuminate\Support\Facades\Route;

/*
| Preview environments (docs/06 §5). Registered from bootstrap/app.php.
*/

// Forward auth for protected previews: asked by edge Caddies on every
// request to a preview host. No session, cookies or CSRF (web group off);
// it reads the preview host's own cookie (docs/09 R20a).
Route::get('auth/preview', [PreviewAuthController::class, 'check'])
    ->withoutMiddleware('web')
    ->name('previews.auth');

Route::middleware(['web', 'auth', 'verified'])
    ->where([
        'project' => PublicId::routePattern('prj'),
        'service' => PublicId::routePattern('svc'),
        'preview' => PublicId::routePattern('prv'),
        'shareLink' => PublicId::routePattern('psl'),
    ])
    ->group(function (): void {
        Route::get('auth/preview/start', [PreviewAuthController::class, 'start'])
            ->middleware('throttle:60,1')
            ->name('previews.auth.start');

        Route::get('p/{project}/previews', [PreviewController::class, 'index'])->name('projects.previews');
        Route::post('p/{project}/previews/{preview}/approve', [PreviewController::class, 'approve'])->name('projects.previews.approve');
        Route::post('p/{project}/previews/{preview}/share-links', [PreviewController::class, 'share'])
            ->middleware('throttle:30,1')
            ->name('projects.previews.share-links.store');
        Route::delete('p/{project}/previews/{preview}/share-links/{shareLink}', [PreviewController::class, 'revoke'])->name('projects.previews.share-links.destroy');

        Route::put('p/{project}/previews/settings', [PreviewSettingsController::class, 'update'])->name('projects.previews.settings');
        Route::put('p/{project}/previews/services/{service}', [PreviewSettingsController::class, 'strategy'])->name('projects.previews.strategy');
        Route::post('p/{project}/previews/bypass-secret', [PreviewSettingsController::class, 'rotateBypass'])
            ->middleware('throttle:20,1')
            ->name('projects.previews.bypass');
    });
