<?php

declare(strict_types=1);

use App\Http\Controllers\Blueprints\CatalogController;
use App\Http\Controllers\Blueprints\ComposeImportController;
use App\Http\Controllers\Blueprints\TemplateController;
use App\Support\PublicId;
use Illuminate\Support\Facades\Route;

/*
| Templates and Compose import (docs/17). Registered from bootstrap/app.php
| next to routes/projects.php.
*/

Route::middleware(['web', 'auth', 'verified'])
    ->where([
        'project' => PublicId::routePattern('prj'),
        'environment' => PublicId::routePattern('env'),
        'template' => '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?',
    ])
    ->group(function (): void {
        Route::get('projects/new/compose', [ComposeImportController::class, 'create'])->name('projects.compose');
        Route::post('projects/-/compose/preview', [ComposeImportController::class, 'preview'])
            ->middleware('throttle:60,1')
            ->name('projects.compose.preview');
        Route::post('projects/compose', [ComposeImportController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('projects.compose.store');

        Route::get('projects/new/templates', [TemplateController::class, 'index'])->name('projects.templates');
        Route::post('projects/templates/{template}', [TemplateController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('projects.templates.store');

        Route::get('p/{project}/{environment}/catalog', [CatalogController::class, 'index'])->name('projects.catalog');
        Route::post('p/{project}/{environment}/catalog/{template}', [CatalogController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('projects.catalog.store');
    });
