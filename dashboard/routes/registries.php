<?php

declare(strict_types=1);

use App\Http\Controllers\Registry\RegistryCredentialController;
use Illuminate\Support\Facades\Route;

/*
| Private registries (D91, docs/05 §6): a team's sign-in details for
| upstream registries, used to copy private images into launchroom's
| registry at deploy time. Registered from bootstrap/app.php; web group here.
*/

Route::middleware(['web', 'auth', 'verified'])->group(function (): void {
    Route::get('settings/registries', [RegistryCredentialController::class, 'index'])->name('registry-credentials.index');
    Route::post('settings/registries', [RegistryCredentialController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('registry-credentials.store');
    Route::put('settings/registries/{registryCredential}', [RegistryCredentialController::class, 'update'])
        ->middleware('throttle:20,1')
        ->name('registry-credentials.update');
    Route::post('settings/registries/{registryCredential}/check', [RegistryCredentialController::class, 'check'])
        ->middleware('throttle:20,1')
        ->name('registry-credentials.check');
    Route::delete('settings/registries/{registryCredential}', [RegistryCredentialController::class, 'destroy'])->name('registry-credentials.destroy');
});
