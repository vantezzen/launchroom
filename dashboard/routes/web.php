<?php

declare(strict_types=1);

use App\Enums\ServerTab;
use App\Http\Controllers\Activity\TeamActivityController;
use App\Http\Controllers\ConfigSchemaController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Dev\DesignVisualsController;
use App\Http\Controllers\Instance\OnboardingController;
use App\Http\Controllers\Instance\RecoveryController;
use App\Http\Controllers\Logs\TeamLogsController;
use App\Http\Controllers\Registry\RegistryTokenController;
use App\Http\Controllers\Servers\IngressController;
use App\Http\Controllers\Servers\MeshController;
use App\Http\Controllers\Servers\RetiredVolumeController;
use App\Http\Controllers\Servers\ServerAppearanceController;
use App\Http\Controllers\Servers\ServerController;
use App\Http\Controllers\Servers\ServerEnrollmentController;
use App\Http\Controllers\Servers\ServerMetricsController;
use App\Http\Controllers\Servers\ServerMoveController;
use App\Http\Controllers\Servers\ServerReplacementController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\Teams\CurrentTeamController;
use App\Http\Controllers\Teams\InvitationAcceptanceController;
use App\Http\Controllers\Teams\InvitationController;
use App\Http\Controllers\Teams\MembershipController;
use App\Http\Controllers\Teams\TeamController;
use App\Http\Controllers\Teams\TeamSettingsController;
use App\Http\Middleware\RequireRecentPassword;
use App\Support\PublicId;
use Illuminate\Routing\RedirectController;
use Illuminate\Support\Facades\Route;
use Inertia\Controller as InertiaPage;

// Static Inertia pages use Inertia's controller directly instead of the
// `Route::inertia()` macro, whose closure is untyped (PHPStan sees `mixed`).

// GET only (Route::redirect would register every verb).
Route::get('/', RedirectController::class)
    ->defaults('destination', '/dashboard')
    ->defaults('status', 302)
    ->name('home');

// First run: only while no user exists, guarded by the setup token (docs/09 R10).
Route::middleware('throttle:10,1')->group(function (): void {
    Route::get('setup', [SetupController::class, 'show'])->name('setup.show');
    Route::post('setup', [SetupController::class, 'store'])->name('setup.store');
});

// Docker token-auth realm for the registry (docs/05 §6): public by necessity,
// no session; the Basic password must be a registry token this dashboard issued.
// The launchroom.json JSON Schema (docs/18 §2.2): public, no instance data.
Route::get('schema/launchroom.v1.json', ConfigSchemaController::class)
    ->middleware('throttle:60,1')
    ->name('config.schema');

Route::get('registry/token', RegistryTokenController::class)
    ->withoutMiddleware('web')
    ->middleware('throttle:120,1')
    ->name('registry.token');

// Invitations: the token is the credential (single use, expiring, bound to the e-mail).
Route::middleware('throttle:20,1')->group(function (): void {
    Route::get('invitations/{token}', [InvitationAcceptanceController::class, 'show'])->name('invitations.show');
    Route::post('invitations/{token}', [InvitationAcceptanceController::class, 'store'])->name('invitations.accept');
});

Route::middleware(['auth', 'verified'])->group(function (): void {
    // "/" lands on the first-run checklist while it is open, else on the projects (docs/08 §2).
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    // Recovery mode after `install.sh --restore` (docs/14 §5.2), instance owners.
    Route::get('recovery', [RecoveryController::class, 'show'])->name('recovery.show');
    Route::post('recovery/replace', [RecoveryController::class, 'replace'])
        ->middleware([RequireRecentPassword::class, 'throttle:6,1'])
        ->name('recovery.replace');
    Route::post('recovery/rebuild', [RecoveryController::class, 'rebuild'])
        ->middleware('throttle:6,1')
        ->name('recovery.rebuild');
    Route::post('recovery/finish', [RecoveryController::class, 'finish'])->name('recovery.finish');

    Route::get('onboarding', [OnboardingController::class, 'show'])->name('onboarding.show');
    Route::patch('onboarding', [OnboardingController::class, 'update'])->name('onboarding.update');
    Route::post('onboarding/two-factor/dismiss', [OnboardingController::class, 'dismissTwoFactor'])
        ->middleware('throttle:10,1')
        ->name('onboarding.two-factor.dismiss');
    Route::put('onboarding/domain', [OnboardingController::class, 'domain'])->name('onboarding.domain');
    Route::post('onboarding/domain/confirm', [OnboardingController::class, 'confirmDomain'])
        ->middleware('throttle:20,1')
        ->name('onboarding.domain.confirm');
    Route::post('onboarding/domain/check', [OnboardingController::class, 'check'])
        ->middleware('throttle:20,1')
        ->name('onboarding.domain.check');

    Route::put('teams/{team}/current', [CurrentTeamController::class, 'update'])->name('teams.current');
    Route::post('teams', [TeamController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('teams.store');

    // The team activity page in the sidebar.
    Route::get('activity', TeamActivityController::class)->name('activity.index');

    // Search the logs of every project (owners and admins).
    Route::get('logs', [TeamLogsController::class, 'show'])->name('logs.index');
    Route::get('logs/-/entries', [TeamLogsController::class, 'entries'])
        ->middleware('throttle:60,1')
        ->name('logs.entries');

    // Every design primitive in both themes, local development only.
    if (app()->isLocal()) {
        Route::get('dev/design', InertiaPage::class)
            ->defaults('component', 'dev/design')
            ->defaults('props', [])
            ->name('dev.design');
    }

    Route::get('servers', [ServerController::class, 'index'])->name('servers.index');
    Route::get('servers/new', [ServerEnrollmentController::class, 'create'])->name('servers.create');
    Route::post('servers/small-server-hint/dismiss', [ServerController::class, 'dismissSmallServerHint'])
        ->middleware('throttle:10,1')
        ->name('servers.small-server-hint.dismiss');
    Route::post('servers/enrollments', [ServerEnrollmentController::class, 'store'])
        ->middleware(RequireRecentPassword::class)
        ->name('servers.enrollments.store');
    Route::get('servers/enrollments/{enrollment}', [ServerEnrollmentController::class, 'show'])->name('servers.enrollments.show');
    Route::delete('servers/enrollments/{enrollment}', [ServerEnrollmentController::class, 'destroy'])->name('servers.enrollments.destroy');
    // Mesh settings (instance owners, docs/05 §2); before servers/{server}.
    Route::get('servers/mesh', [MeshController::class, 'show'])->name('servers.mesh.show');
    Route::post('servers/mesh/enable', [MeshController::class, 'enable'])
        ->middleware([RequireRecentPassword::class, 'throttle:10,1'])
        ->name('servers.mesh.enable');
    Route::put('servers/mesh/own-tailscale', [MeshController::class, 'ownTailscale'])
        ->middleware([RequireRecentPassword::class, 'throttle:10,1'])
        ->name('servers.mesh.own-tailscale');
    // Ingress & certificates (instance owners, docs/05 §4–5); before servers/{server}.
    Route::get('servers/ingress', [IngressController::class, 'show'])->name('servers.ingress.show');
    Route::put('servers/ingress/dns', [IngressController::class, 'update'])
        ->middleware([RequireRecentPassword::class, 'throttle:10,1'])
        ->name('servers.ingress.dns.update');
    Route::delete('servers/ingress/dns', [IngressController::class, 'destroy'])
        ->middleware(RequireRecentPassword::class)
        ->name('servers.ingress.dns.destroy');
    Route::post('servers/ingress/dns/verify', [IngressController::class, 'verify'])
        ->middleware('throttle:10,1')
        ->name('servers.ingress.dns.verify');
    Route::put('servers/ingress/edges/{server}', [IngressController::class, 'sharedEdge'])
        ->middleware([RequireRecentPassword::class, 'throttle:10,1'])
        ->name('servers.ingress.edges.update');
    // Usage metrics JSON and device/location overrides; before servers/{server}.
    Route::get('servers/-/metrics', [ServerMetricsController::class, 'index'])->name('servers.metrics.index');
    Route::get('servers/{server}/-/metrics', [ServerMetricsController::class, 'show'])->name('servers.metrics.show');
    Route::patch('servers/{server}/appearance', ServerAppearanceController::class)->name('servers.appearance.update');
    // One page with tabs (docs/08 §14): Overview at the bare URL, the others as a segment.
    Route::get('servers/{server}', [ServerController::class, 'show'])->name('servers.show');
    Route::get('servers/{server}/{tab}', [ServerController::class, 'show'])
        ->whereIn('tab', ServerTab::segments())
        ->name('servers.tab');
    Route::put('servers/{server}/settings', [ServerController::class, 'updateSettings'])->name('servers.settings.update');
    Route::put('servers/{server}/roles', [ServerController::class, 'updateRoles'])
        ->middleware([RequireRecentPassword::class, 'throttle:10,1'])
        ->name('servers.roles.update');
    Route::post('servers/{server}/approve', [ServerController::class, 'approve'])
        ->middleware(RequireRecentPassword::class)
        ->name('servers.approve');
    Route::post('servers/{server}/revoke', [ServerController::class, 'revoke'])
        ->middleware(RequireRecentPassword::class)
        ->name('servers.revoke');
    // Replace a dead server (docs/14 §5.3).
    Route::get('servers/{server}/replace', [ServerReplacementController::class, 'show'])->name('servers.replace');
    Route::post('servers/{server}/replace', [ServerReplacementController::class, 'store'])
        ->middleware([RequireRecentPassword::class, 'throttle:6,1'])
        ->name('servers.replace.store');
    // Move to another server (docs/14 §13.2).
    Route::get('servers/{server}/move', [ServerMoveController::class, 'show'])->name('servers.move');
    Route::post('servers/{server}/move', [ServerMoveController::class, 'store'])
        ->middleware([RequireRecentPassword::class, 'throttle:6,1'])
        ->name('servers.move.store');
    Route::post('servers/{server}/move/{move}/cancel', [ServerMoveController::class, 'cancel'])
        ->where('move', PublicId::routePattern('smv'))
        ->middleware('throttle:10,1')
        ->name('servers.move.cancel');
    Route::post('servers/{server}/move/{move}/items/{item}/retry', [ServerMoveController::class, 'retry'])
        ->where(['move' => PublicId::routePattern('smv'), 'item' => PublicId::routePattern('smi')])
        ->middleware('throttle:20,1')
        ->name('servers.move.retry');
    Route::post('servers/{server}/move/{move}/retire', [ServerMoveController::class, 'retire'])
        ->where('move', PublicId::routePattern('smv'))
        ->middleware([RequireRecentPassword::class, 'throttle:6,1'])
        ->name('servers.move.retire');
    Route::post('servers/{server}/retired-volumes/{volume}/confirm', [RetiredVolumeController::class, 'confirm'])
        ->where('volume', 'vol_[0-9A-HJKMNP-TV-Z]{26}')
        ->middleware(RequireRecentPassword::class)
        ->name('servers.retired-volumes.confirm');

    // Local-only showcase of the visual primitives; 404 elsewhere.
    Route::get('dev/design/visuals', DesignVisualsController::class)->name('dev.design.visuals');

    Route::get('settings/team', [TeamSettingsController::class, 'edit'])->name('team.edit');
    Route::post('settings/team/invitations', [InvitationController::class, 'store'])
        ->middleware(RequireRecentPassword::class)
        ->name('team.invitations.store');
    Route::delete('settings/team/invitations/{invitation}', [InvitationController::class, 'destroy'])->name('team.invitations.destroy');
    Route::patch('settings/team/members/{membership}', [MembershipController::class, 'update'])
        ->middleware(RequireRecentPassword::class)
        ->name('team.members.update');
    Route::delete('settings/team/members/{membership}', [MembershipController::class, 'destroy'])->name('team.members.destroy');
});

require __DIR__.'/settings.php';
