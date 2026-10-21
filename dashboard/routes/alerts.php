<?php

declare(strict_types=1);

use App\Http\Controllers\Alerts\AlertChannelController;
use App\Http\Controllers\Alerts\AlertRulesController;
use App\Http\Controllers\Alerts\NotificationSettingsController;
use Illuminate\Support\Facades\Route;

/*
| Alerts and notifications (docs/15 §3). Loaded by
| App\Providers\AlertsServiceProvider; every route gets the `web` group here.
| Controllers authorize through AlertChannelPolicy.
*/

Route::middleware(['web', 'auth', 'verified'])->group(function (): void {
    Route::get('settings/notifications', [NotificationSettingsController::class, 'edit'])->name('notifications.edit');

    Route::post('settings/notifications/channels', [AlertChannelController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('notifications.channels.store');
    Route::patch('settings/notifications/channels/{alertChannel}', [AlertChannelController::class, 'update'])
        ->middleware('throttle:30,1')
        ->name('notifications.channels.update');
    Route::delete('settings/notifications/channels/{alertChannel}', [AlertChannelController::class, 'destroy'])
        ->name('notifications.channels.destroy');
    Route::post('settings/notifications/channels/{alertChannel}/test', [AlertChannelController::class, 'test'])
        ->middleware('throttle:6,1')
        ->name('notifications.channels.test');

    Route::put('settings/notifications/rules', [AlertRulesController::class, 'update'])
        ->middleware('throttle:30,1')
        ->name('notifications.rules.update');
});
