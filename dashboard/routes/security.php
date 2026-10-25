<?php

declare(strict_types=1);

use App\Http\Controllers\Security\FindingController;
use App\Http\Controllers\Security\SecurityController;
use App\Http\Controllers\Security\SecuritySourcesController;
use App\Http\Controllers\Security\ServiceFirewallController;
use App\Support\PublicId;
use Illuminate\Support\Facades\Route;

/*
| Security center (D63, docs/22 §9): the Security page, findings, the
| inventory search, a service's web firewall and the advisory sources.
| Loaded by App\Providers\SecurityServiceProvider.
*/

Route::middleware(['web', 'auth', 'verified'])->group(function (): void {
    Route::get('security', [SecurityController::class, 'show'])->name('security.index');
    Route::get('security/-/data', [SecurityController::class, 'data'])
        ->middleware('throttle:120,1')
        ->name('security.data');
    Route::post('security/check', [SecurityController::class, 'check'])
        ->middleware('throttle:10,1')
        ->name('security.check');
    Route::get('security/inventory', [SecurityController::class, 'inventory'])->name('security.inventory');
    Route::get('security/inventory/-/search', [SecurityController::class, 'search'])
        ->middleware('throttle:120,1')
        ->name('security.inventory.search');

    Route::get('security/findings/{finding}', [FindingController::class, 'show'])
        ->where('finding', PublicId::routePattern('fnd'))
        ->name('security.findings.show');
    Route::get('security/findings/{finding}/-/data', [FindingController::class, 'data'])
        ->where('finding', PublicId::routePattern('fnd'))
        ->middleware('throttle:120,1')
        ->name('security.findings.data');
    Route::post('security/findings/{finding}/dismiss', [FindingController::class, 'dismiss'])
        ->where('finding', PublicId::routePattern('fnd'))
        ->middleware('throttle:30,1')
        ->name('security.findings.dismiss');
    Route::post('security/findings/{finding}/reopen', [FindingController::class, 'reopen'])
        ->where('finding', PublicId::routePattern('fnd'))
        ->middleware('throttle:30,1')
        ->name('security.findings.reopen');
    Route::post('security/findings/{finding}/investigate', [FindingController::class, 'investigate'])
        ->where('finding', PublicId::routePattern('fnd'))
        ->middleware('throttle:12,1')
        ->name('security.findings.investigate');

    Route::get('p/{project}/{environment}/s/{service}/firewall', [ServiceFirewallController::class, 'show'])
        ->middleware('throttle:120,1')
        ->name('security.firewall.show');
    Route::patch('p/{project}/{environment}/s/{service}/firewall', [ServiceFirewallController::class, 'update'])
        ->middleware('throttle:30,1')
        ->name('security.firewall.update');
    Route::post('p/{project}/{environment}/s/{service}/firewall/exclusions', [ServiceFirewallController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('security.firewall.exclusions.store');
    Route::delete('p/{project}/{environment}/s/{service}/firewall/exclusions/{wafExclusion}', [ServiceFirewallController::class, 'destroy'])
        ->where('wafExclusion', PublicId::routePattern('wfx'))
        ->middleware('throttle:30,1')
        ->name('security.firewall.exclusions.destroy');
    Route::post('p/{project}/{environment}/s/{service}/investigate', [ServiceFirewallController::class, 'investigate'])
        ->middleware('throttle:12,1')
        ->name('security.services.investigate');

    Route::put('settings/instance-security/sources', [SecuritySourcesController::class, 'update'])
        ->middleware('throttle:20,1')
        ->name('instance-security.sources.update');
});
