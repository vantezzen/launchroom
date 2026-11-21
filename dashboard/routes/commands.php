<?php

declare(strict_types=1);

use App\Http\Controllers\Commands\ServiceCommandController;
use Illuminate\Support\Facades\Route;

/*
| One-off commands: the service panel's Commands tab (D105, docs/08 §22).
| Loaded by App\Providers\CommandsServiceProvider. The public API has the
| same under /api/v1 (routes/api.php, ability `run`).
*/

Route::middleware(['web', 'auth', 'verified'])->group(function (): void {
    Route::get('commands/p/{project}/{environment}/s/{service}', [ServiceCommandController::class, 'show'])
        ->middleware('throttle:120,1')
        ->name('commands.services.show');
    Route::post('commands/p/{project}/{environment}/s/{service}', [ServiceCommandController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('commands.services.run');

    Route::get('commands/runs/{commandRun}', [ServiceCommandController::class, 'run'])
        ->middleware('throttle:240,1')
        ->name('commands.runs.show');
    Route::get('commands/runs/{commandRun}/output', [ServiceCommandController::class, 'output'])
        ->middleware('throttle:240,1')
        ->name('commands.runs.output');
    Route::post('commands/runs/{commandRun}/cancel', [ServiceCommandController::class, 'cancel'])
        ->middleware('throttle:30,1')
        ->name('commands.runs.cancel');
});
