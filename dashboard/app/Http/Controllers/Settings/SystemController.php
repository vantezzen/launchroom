<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Queries\ControlPlaneHealth;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → System (G41), instance owners only: the control plane's
 * services as the main server's watchdog sees them, background jobs,
 * the scheduler, storage, the control-plane backup and versions, with the
 * same checks `./launchroom doctor` runs. Read-only; Horizon is linked for
 * the jobs' details.
 */
final class SystemController extends Controller
{
    public function show(#[CurrentUser] User $user, ControlPlaneHealth $health): Response
    {
        Gate::authorize('manageInstance');

        return Inertia::render('settings/system', [
            'system' => $health->page(Gate::forUser($user)->allows('viewHorizon')),
        ]);
    }
}
