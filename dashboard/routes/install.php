<?php

declare(strict_types=1);

use App\Http\Controllers\Install\ServerInstallScriptController;
use App\Http\Controllers\Instance\DashboardProbeController;
use Illuminate\Support\Facades\Route;

/*
| Installer downloads (docs/11 §3). Registered from bootstrap/app.php without
| the `web` group: no session, cookies or CSRF. Public by design: the script
| contains no secrets (the join token is passed by the user via LR_JOIN_TOKEN).
*/

Route::get('install/server.sh', ServerInstallScriptController::class)
    ->middleware('throttle:30,1')
    ->name('install.server');

// The HTTPS test request of Settings → Dashboard domain (docs/05 §4.1):
// answered on every host, including a new domain still being verified.
Route::get('.well-known/launchroom/dashboard', DashboardProbeController::class)
    ->middleware('throttle:60,1')
    ->name('instance.dashboard-probe');
