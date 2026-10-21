<?php

declare(strict_types=1);

use App\Http\Controllers\Backups\BackupDestinationController;
use App\Http\Controllers\Backups\BackupScheduleController;
use App\Http\Controllers\Backups\BackupSettingsController;
use App\Http\Controllers\Backups\DeletionRequestController;
use App\Http\Controllers\Backups\RecoveryKitController;
use App\Http\Controllers\Backups\ServiceBackupsController;
use App\Http\Middleware\RequireRecentPassword;
use Illuminate\Support\Facades\Route;

/*
| Backups (docs/14). Loaded by App\Providers\BackupsServiceProvider; every
| route gets the `web` group here. Destinations and schedules bind through
| team-scoped resolvers (the instance destination only for instance owners).
*/

Route::middleware(['web', 'auth', 'verified'])->group(function (): void {
    // Settings → Backups.
    Route::get('settings/backups', [BackupSettingsController::class, 'edit'])->name('backups.settings');

    Route::post('settings/backups/destinations', [BackupDestinationController::class, 'store'])
        ->middleware(RequireRecentPassword::class)
        ->name('backups.destinations.store');
    Route::put('settings/backups/destinations/{backupDestination}', [BackupDestinationController::class, 'update'])
        ->middleware(RequireRecentPassword::class)
        ->name('backups.destinations.update');
    Route::delete('settings/backups/destinations/{backupDestination}', [BackupDestinationController::class, 'destroy'])
        ->middleware(RequireRecentPassword::class)
        ->name('backups.destinations.destroy');
    Route::post('settings/backups/destinations/{backupDestination}/test', [BackupDestinationController::class, 'test'])
        ->middleware('throttle:10,1')
        ->name('backups.destinations.test');

    Route::put('settings/backups/schedules/{backupSchedule}', [BackupScheduleController::class, 'update'])->name('backups.schedules.update');
    Route::post('settings/backups/control-plane/run', [BackupScheduleController::class, 'runControlPlane'])
        ->middleware('throttle:6,1')
        ->name('backups.control-plane.run');

    // Recovery kit (instance owners).
    Route::post('settings/backups/recovery/passphrase', [RecoveryKitController::class, 'passphrase'])
        ->middleware([RequireRecentPassword::class, 'throttle:6,1'])
        ->name('backups.recovery.passphrase');
    Route::get('settings/backups/recovery/kit', [RecoveryKitController::class, 'download'])
        ->middleware([RequireRecentPassword::class, 'throttle:10,1'])
        ->name('backups.recovery.kit');
    Route::post('settings/backups/recovery/confirm', [RecoveryKitController::class, 'confirm'])->name('backups.recovery.confirm');

    // Backups tab of databases and services with volumes (JSON).
    Route::get('backups/p/{project}/{environment}/s/{service}', [ServiceBackupsController::class, 'lookup'])
        ->middleware('throttle:120,1')
        ->name('backups.services.lookup');
    Route::get('backups/services/{serviceInstance}', [ServiceBackupsController::class, 'show'])
        ->middleware('throttle:120,1')
        ->name('backups.services.show');
    Route::post('backups/services/{serviceInstance}/backup', [ServiceBackupsController::class, 'backup'])
        ->middleware('throttle:10,1')
        ->name('backups.services.backup');
    Route::post('backups/services/{serviceInstance}/snapshots', [ServiceBackupsController::class, 'snapshots'])
        ->middleware('throttle:10,1')
        ->name('backups.services.snapshots');
    Route::post('backups/services/{serviceInstance}/restore', [ServiceBackupsController::class, 'restore'])
        ->middleware('throttle:6,1')
        ->name('backups.services.restore');
    Route::post('backups/services/{serviceInstance}/restores/{restore}/swap', [ServiceBackupsController::class, 'swap'])
        ->middleware('throttle:6,1')
        ->name('backups.services.restores.swap');
    // Deletions waiting for a final backup (docs/14 §9.13).
    Route::post('deletions/{deletion}/retry', [DeletionRequestController::class, 'retry'])
        ->middleware('throttle:10,1')
        ->name('deletions.retry');
    Route::post('deletions/{deletion}/skip-final-backup', [DeletionRequestController::class, 'skip'])
        ->middleware([RequireRecentPassword::class, 'throttle:10,1'])
        ->name('deletions.skip');
    Route::put('backups/services/{serviceInstance}/volumes/{volume}', [ServiceBackupsController::class, 'updateVolume'])
        ->middleware('throttle:30,1')
        ->name('backups.services.volumes.update');
    // Point-in-time recovery and restore verification (docs/14 §10).
    Route::put('backups/services/{serviceInstance}/point-in-time', [ServiceBackupsController::class, 'pointInTime'])
        ->middleware('throttle:10,1')
        ->name('backups.services.point-in-time');
    Route::post('backups/services/{serviceInstance}/verify', [ServiceBackupsController::class, 'verify'])
        ->middleware('throttle:6,1')
        ->name('backups.services.verify');
});
