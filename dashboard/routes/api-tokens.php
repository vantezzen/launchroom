<?php

declare(strict_types=1);

use App\Http\Controllers\ApiTokens\AiAgentsController;
use App\Http\Controllers\ApiTokens\ApiDocsController;
use App\Http\Controllers\ApiTokens\ApiTokenController;
use App\Http\Controllers\ApiTokens\CliLoginController;
use App\Http\Controllers\ApiTokens\RobotTokenController;
use Illuminate\Support\Facades\Route;

/*
| Settings → API tokens, the CLI sign-in approval page and the API reference
| (docs/16 §1.3, §2.4, §4). Registered from bootstrap/app.php; dashboard
| session pages (web group). Tokens are bound as `{apiToken}`: only the
| signed-in user's own (ApiServiceProvider).
*/

Route::middleware(['web', 'auth', 'verified'])->group(function (): void {
    Route::get('settings/api-tokens', [ApiTokenController::class, 'index'])->name('api-tokens.index');
    Route::post('settings/api-tokens', [ApiTokenController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('api-tokens.store');
    Route::delete('settings/api-tokens/{apiToken}', [ApiTokenController::class, 'destroy'])
        ->name('api-tokens.destroy');

    // Settings → AI agents (docs/16 §7.6): connect an editor's agent through `launchroom mcp`.
    Route::get('settings/ai-agents', AiAgentsController::class)->name('ai-agents');

    // Settings → Robot tokens (docs/16 §1.4): team-owned, `{robotToken}` binds the team's robot tokens only.
    Route::get('settings/robot-tokens', [RobotTokenController::class, 'index'])->name('robot-tokens.index');
    Route::post('settings/robot-tokens', [RobotTokenController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('robot-tokens.store');
    Route::post('settings/robot-tokens/{robotToken}/rotate', [RobotTokenController::class, 'rotate'])
        ->middleware('throttle:10,1')
        ->name('robot-tokens.rotate');
    Route::delete('settings/robot-tokens/{robotToken}', [RobotTokenController::class, 'destroy'])
        ->name('robot-tokens.destroy');

    Route::get('cli/login', [CliLoginController::class, 'show'])
        ->middleware('throttle:30,1')
        ->name('cli-login.show');
    Route::post('cli/login/{code}/approve', [CliLoginController::class, 'approve'])
        ->where('code', '[A-Za-z]{4}-?[A-Za-z]{4}')
        ->middleware('throttle:10,1')
        ->name('cli-login.approve');
    Route::post('cli/login/{code}/deny', [CliLoginController::class, 'deny'])
        ->where('code', '[A-Za-z]{4}-?[A-Za-z]{4}')
        ->middleware('throttle:10,1')
        ->name('cli-login.deny');

    Route::get('docs/api', ApiDocsController::class)->name('api-docs');
});
