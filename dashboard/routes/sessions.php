<?php

declare(strict_types=1);

use App\Http\Controllers\Sessions\InteractiveSessionController;
use App\Http\Controllers\Sessions\ServiceSessionController;
use App\Http\Controllers\Teams\MembershipController;
use App\Http\Controllers\Teams\TeamSettingsController;
use App\Http\Middleware\RequireRecentPassword;
use App\Support\PublicId;
use Illuminate\Support\Facades\Route;

/*
| Interactive sessions: Console and Data tabs (docs/03 §11, docs/08 §15,
| docs/09 R33). Loaded by App\Providers\InteractiveSessionsServiceProvider
| with the `web` group here. Opening asks for a fresh password inline (423).
*/

Route::middleware(['web', 'auth', 'verified'])->group(function (): void {
    Route::get('sessions/p/{project}/{environment}/s/{service}/{kind}', [ServiceSessionController::class, 'show'])
        ->whereIn('kind', ['console', 'data'])
        ->middleware('throttle:120,1')
        ->name('sessions.services.show');
    Route::post('sessions/p/{project}/{environment}/s/{service}/{kind}', [ServiceSessionController::class, 'store'])
        ->whereIn('kind', ['console', 'data'])
        ->middleware('throttle:20,1')
        ->name('sessions.services.open');

    Route::get('sessions/{interactiveSession}', [InteractiveSessionController::class, 'show'])
        ->where('interactiveSession', PublicId::routePattern('ses'))
        ->middleware('throttle:120,1')
        ->name('sessions.show');
    Route::post('sessions/{interactiveSession}/authorize', [InteractiveSessionController::class, 'authorizeChannel'])
        ->where('interactiveSession', PublicId::routePattern('ses'))
        ->middleware('throttle:30,1')
        ->name('sessions.authorize');
    Route::post('sessions/{interactiveSession}/commands', [InteractiveSessionController::class, 'command'])
        ->where('interactiveSession', PublicId::routePattern('ses'))
        ->middleware('throttle:120,1')
        ->name('sessions.commands');
    Route::post('sessions/{interactiveSession}/end', [InteractiveSessionController::class, 'end'])
        ->where('interactiveSession', PublicId::routePattern('ses'))
        ->middleware('throttle:60,1')
        ->name('sessions.end');
    Route::post('sessions/{interactiveSession}/recording', [InteractiveSessionController::class, 'recording'])
        ->where('interactiveSession', PublicId::routePattern('ses'))
        ->middleware('throttle:10,1')
        ->name('sessions.recording');

    // Team settings: developers' Console/Data access, opt-in transcripts.
    Route::put('settings/team/members/{membership}/permissions', [MembershipController::class, 'permissions'])
        ->middleware(RequireRecentPassword::class)
        ->name('team.members.permissions');
    Route::put('settings/team/console-recording', [TeamSettingsController::class, 'recording'])
        ->middleware(RequireRecentPassword::class)
        ->name('team.console-recording');
});
