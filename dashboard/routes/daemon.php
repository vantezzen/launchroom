<?php

declare(strict_types=1);

use App\Http\Controllers\Daemon\BroadcastAuthController;
use App\Http\Controllers\Daemon\ClientCertificateController;
use App\Http\Controllers\Daemon\CompleteTaskController;
use App\Http\Controllers\Daemon\ConfigController;
use App\Http\Controllers\Daemon\EnrollController;
use App\Http\Controllers\Daemon\LeaseTasksController;
use App\Http\Controllers\Daemon\LogController;
use App\Http\Controllers\Daemon\RegistryTokenController;
use App\Http\Controllers\Daemon\ReportController;
use App\Http\Controllers\Daemon\StateController;
use App\Http\Controllers\Daemon\TaskHeartbeatController;
use App\Http\Controllers\Daemon\TransferChunkController;
use App\Http\Middleware\AuthenticateDaemon;
use App\Http\Middleware\NegotiateProtocolVersion;
use App\Http\Middleware\SignResponse;
use App\Support\PublicId;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Daemon API (docs/03 §4, protocol/SIGNING.md)
|--------------------------------------------------------------------------
|
| Registered under /daemon/v1 without the web group (no session, cookies or
| CSRF). Every route is authenticated by its request signature
| (AuthenticateDaemon) and every 2xx body is signed (SignResponse, outermost).
| Each endpoint only touches the authenticated server's own data. Requests
| must share a protocol version with the dashboard (NegotiateProtocolVersion, 426).
|
*/

Route::middleware([SignResponse::class, 'throttle:daemon'])->group(function (): void {
    Route::post('enroll', EnrollController::class)
        ->middleware([AuthenticateDaemon::class.':'.AuthenticateDaemon::MODE_ENROLL, NegotiateProtocolVersion::class])
        ->name('daemon.enroll');

    // Protocol negotiation after authentication (docs/03 §10).
    Route::middleware([AuthenticateDaemon::class, NegotiateProtocolVersion::class])->group(function (): void {
        Route::get('state', StateController::class)->name('daemon.state');
        Route::get('config', ConfigController::class)->name('daemon.config');
        Route::post('broadcasting/auth', BroadcastAuthController::class)->name('daemon.broadcasting.auth');
        Route::post('tasks/lease', LeaseTasksController::class)->name('daemon.tasks.lease');
        Route::post('tasks/{task}/heartbeat', TaskHeartbeatController::class)
            ->where('task', PublicId::routePattern('tsk'))
            ->name('daemon.tasks.heartbeat');
        Route::post('tasks/{task}/complete', CompleteTaskController::class)
            ->where('task', PublicId::routePattern('tsk'))
            ->name('daemon.tasks.complete');
        Route::post('reports', ReportController::class)->name('daemon.reports');
        Route::post('logs', LogController::class)->name('daemon.logs');
        Route::post('registry-token', RegistryTokenController::class)->name('daemon.registry-token');
        // Client certificates for mTLS (D50, docs/03 §4.3); exempt from the
        // edge's certificate requirement, IssueClientCertificate decides.
        Route::post('client-certificate', ClientCertificateController::class)->name('daemon.client-certificate');
    });
});

// Transfer chunks (docs/14 §12.2): their own per-server budget, so a large
// import or export doesn't use up the polling budget.
Route::middleware([SignResponse::class, AuthenticateDaemon::class, NegotiateProtocolVersion::class, 'throttle:daemon-transfers'])
    ->where(['transfer' => PublicId::routePattern('dtr'), 'index' => '[0-9]{1,7}'])
    ->group(function (): void {
        Route::get('transfers/{transfer}/chunks/{index}', [TransferChunkController::class, 'show'])->name('daemon.transfers.chunks.show');
        Route::put('transfers/{transfer}/chunks/{index}', [TransferChunkController::class, 'store'])->name('daemon.transfers.chunks.store');
    });
