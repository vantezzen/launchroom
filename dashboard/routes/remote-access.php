<?php

declare(strict_types=1);

use App\Http\Controllers\RemoteAccess\RemoteAccessController;
use App\Http\Controllers\RemoteAccess\ServiceRemoteAccessController;
use App\Support\PublicId;
use Illuminate\Support\Facades\Route;

/*
| Temporary remote access (D61, D66, docs/09 R39): Connect via SSH, Connect
| with SFTP (a volume) and temporary database access. Loaded by App\Providers\RemoteAccessServiceProvider.
*/

Route::middleware(['web', 'auth', 'verified'])->group(function (): void {
    Route::get('access/p/{project}/{environment}/s/{service}/{kind}', [ServiceRemoteAccessController::class, 'show'])
        ->whereIn('kind', ['ssh', 'database', 'volume'])
        ->middleware('throttle:120,1')
        ->name('remote-access.services.show');
    Route::post('access/p/{project}/{environment}/s/{service}/{kind}', [ServiceRemoteAccessController::class, 'store'])
        ->whereIn('kind', ['ssh', 'database', 'volume'])
        ->middleware('throttle:10,1')
        ->name('remote-access.services.open');

    Route::get('access/{remoteAccess}', [RemoteAccessController::class, 'show'])
        ->where('remoteAccess', PublicId::routePattern('acc'))
        ->middleware('throttle:120,1')
        ->name('remote-access.show');
    Route::post('access/{remoteAccess}/end', [RemoteAccessController::class, 'end'])
        ->where('remoteAccess', PublicId::routePattern('acc'))
        ->middleware('throttle:30,1')
        ->name('remote-access.end');
});
