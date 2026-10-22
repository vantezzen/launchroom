<?php

declare(strict_types=1);

use App\Http\Controllers\Settings\AuditSinkController;
use App\Http\Controllers\Settings\AuditVerificationController;
use App\Http\Controllers\Settings\ClientCertificatesController;
use App\Http\Controllers\Settings\ControlPlaneMoveController;
use App\Http\Controllers\Settings\DashboardDomainController;
use App\Http\Controllers\Settings\InstanceSecurityController;
use App\Http\Controllers\Settings\NetworkSettingsController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\RegistrySettingsController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\UpdatesController;
use App\Support\PublicId;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;
use Inertia\Controller as InertiaPage;

Route::middleware(['auth'])->group(function (): void {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])

        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::get('settings/appearance', InertiaPage::class)
        ->defaults('component', 'settings/appearance')
        ->defaults('props', [])
        ->name('appearance.edit');
});

// Settings → Updates (docs/11 §4), instance owners (authorized in the controller).
Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('settings/updates', [UpdatesController::class, 'edit'])->name('updates.edit');
    Route::post('settings/updates/check', [UpdatesController::class, 'check'])
        ->middleware('throttle:6,1')
        ->name('updates.check');
    Route::post('settings/updates/control-plane', [UpdatesController::class, 'updateControlPlane'])
        ->middleware('throttle:6,1')
        ->name('updates.control-plane');
    Route::put('settings/updates/preferences', [UpdatesController::class, 'updatePreferences'])
        ->middleware('throttle:6,1')
        ->name('updates.preferences');
    Route::post('settings/updates/servers', [UpdatesController::class, 'updateFleet'])
        ->middleware('throttle:6,1')
        ->name('updates.servers');

    // Settings → Network, the private network and public
    // traffic & certificates on one page (actions stay on servers/mesh/*, servers/ingress/*).
    Route::get('settings/network', [NetworkSettingsController::class, 'edit'])->name('network-settings.edit');

    // Settings → Dashboard domain (docs/05 §4.1): the dashboard at its own domain.
    Route::get('settings/dashboard-domain', [DashboardDomainController::class, 'edit'])->name('dashboard-domain.edit');
    Route::post('settings/dashboard-domain', [DashboardDomainController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('dashboard-domain.store');
    Route::post('settings/dashboard-domain/check', [DashboardDomainController::class, 'check'])
        ->middleware('throttle:20,1')
        ->name('dashboard-domain.check');
    Route::delete('settings/dashboard-domain', [DashboardDomainController::class, 'destroy'])
        ->middleware('throttle:10,1')
        ->name('dashboard-domain.destroy');
    Route::post('settings/dashboard-domain/switch', [DashboardDomainController::class, 'activate'])
        ->middleware('throttle:6,1')
        ->name('dashboard-domain.activate');
    Route::post('settings/dashboard-domain/switch-back', [DashboardDomainController::class, 'revert'])
        ->middleware('throttle:6,1')
        ->name('dashboard-domain.revert');

    // Settings → Security for this launchroom (D50): server certificates
    // (docs/03 §4.3) and the audit log (docs/09 R34). Section actions below.
    Route::get('settings/instance-security', [InstanceSecurityController::class, 'edit'])

        ->name('instance-security.edit');

    // Settings → Security → Server certificates (docs/03 §4.3).
    Route::put('settings/instance-security/client-certificates', [ClientCertificatesController::class, 'update'])
        ->middleware('throttle:10,1')
        ->name('client-certificates.update');
    Route::post('settings/instance-security/client-certificates/{serverId}/reissue', [ClientCertificatesController::class, 'reissue'])
        ->middleware('throttle:10,1')
        ->where('serverId', 'srv_[0-9A-HJKMNP-TV-Z]{26}')
        ->name('client-certificates.reissue');

    // Settings → Security → Audit log (docs/09 R34).
    Route::post('settings/instance-security/audit/verify', AuditVerificationController::class)
        ->middleware('throttle:6,1')
        ->name('audit-verification.store');
    Route::post('settings/instance-security/audit/sinks', [AuditSinkController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('audit-sinks.store');
    Route::patch('settings/instance-security/audit/sinks/{auditSink}', [AuditSinkController::class, 'update'])
        ->middleware('throttle:20,1')
        ->name('audit-sinks.update');
    Route::delete('settings/instance-security/audit/sinks/{auditSink}', [AuditSinkController::class, 'destroy'])
        ->middleware('throttle:10,1')
        ->name('audit-sinks.destroy');
    Route::post('settings/instance-security/audit/sinks/{auditSink}/test', [AuditSinkController::class, 'test'])
        ->middleware('throttle:10,1')
        ->name('audit-sinks.test');
    Route::post('settings/instance-security/audit/sinks/{auditSink}/retry', [AuditSinkController::class, 'retry'])
        ->middleware('throttle:10,1')
        ->name('audit-sinks.retry');

    // Settings → Registry (docs/14 §9.11): Zot's storage on S3.
    // Settings → Move launchroom (instance owners, docs/14 §13.4).
    Route::get('settings/move', [ControlPlaneMoveController::class, 'edit'])->name('move.edit');
    Route::post('settings/move', [ControlPlaneMoveController::class, 'start'])
        ->middleware('throttle:6,1')
        ->name('move.start');
    Route::post('settings/move/approve', [ControlPlaneMoveController::class, 'approve'])
        ->middleware('throttle:10,1')
        ->name('move.approve');
    Route::post('settings/move/workloads', [ControlPlaneMoveController::class, 'workloads'])
        ->middleware('throttle:6,1')
        ->name('move.workloads');
    Route::post('settings/move/items/{item}/retry', [ControlPlaneMoveController::class, 'retry'])
        ->where('item', PublicId::routePattern('smi'))
        ->middleware('throttle:20,1')
        ->name('move.retry');
    Route::post('settings/move/hand-over', [ControlPlaneMoveController::class, 'handover'])
        ->middleware('throttle:6,1')
        ->name('move.handover');
    Route::post('settings/move/abort', [ControlPlaneMoveController::class, 'abort'])
        ->middleware('throttle:6,1')
        ->name('move.abort');
    Route::post('settings/move/cancel', [ControlPlaneMoveController::class, 'cancel'])
        ->middleware('throttle:10,1')
        ->name('move.cancel');
    Route::post('settings/move/rebuild', [ControlPlaneMoveController::class, 'rebuild'])
        ->middleware('throttle:6,1')
        ->name('move.rebuild');
    Route::post('settings/move/finish', [ControlPlaneMoveController::class, 'finish'])->name('move.finish');

    Route::get('settings/registry', [RegistrySettingsController::class, 'edit'])->name('registry-settings.edit');
    Route::put('settings/registry/storage', [RegistrySettingsController::class, 'update'])
        ->middleware('throttle:10,1')
        ->name('registry-settings.update');
    Route::delete('settings/registry/storage', [RegistrySettingsController::class, 'destroy'])
        ->middleware('throttle:10,1')
        ->name('registry-settings.destroy');
});

Route::get('.well-known/passkey-endpoints', fn (): JsonResponse => response()->json([
    'enroll' => route('security.edit'),
    'manage' => route('security.edit'),
]))->name('well-known.passkeys');
