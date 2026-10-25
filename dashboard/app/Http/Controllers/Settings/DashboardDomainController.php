<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Instance\DashboardDomain\CancelDashboardDomainChange;
use App\Actions\Instance\DashboardDomain\CheckDashboardDomain;
use App\Actions\Instance\DashboardDomain\StartDashboardDomainChange;
use App\Actions\Instance\DashboardDomain\SwitchBackDashboardDomain;
use App\Actions\Instance\DashboardDomain\SwitchDashboardDomain;
use App\Data\Instance\StartDashboardDomainData;
use App\Http\Controllers\Controller;
use App\Models\DashboardDomainChange;
use App\Models\User;
use App\Queries\DashboardDomainSettings;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Settings → Dashboard domain (docs/05 §4.1, D43), instance owners only:
 * enter a domain, point DNS at the main server, let Caddy get a certificate,
 * test HTTPS, then switch (and switch back). Switching sends the browser to
 * the new origin, where the owner signs in again (cookies are per host).
 */
final class DashboardDomainController extends Controller
{
    public function edit(#[CurrentUser] User $user, DashboardDomainSettings $settings): Response
    {
        Gate::authorize('manageInstance');

        return Inertia::render('settings/dashboard-domain', ['settings' => $settings->get($user)]);
    }

    public function store(Request $request, StartDashboardDomainData $data, #[CurrentUser] User $user, StartDashboardDomainChange $start): RedirectResponse
    {
        Gate::authorize('manageInstance');

        $start->handle($data->hostname, $user, $this->auditActor($request, $user));

        return back();
    }

    public function check(CheckDashboardDomain $check): RedirectResponse
    {
        Gate::authorize('manageInstance');

        $change = DashboardDomainChange::open();

        if ($change instanceof DashboardDomainChange) {
            $check->handle($change);
        }

        return back();
    }

    public function destroy(Request $request, #[CurrentUser] User $user, CancelDashboardDomainChange $cancel): RedirectResponse
    {
        Gate::authorize('manageInstance');

        $cancel->handle($this->auditActor($request, $user));

        return back()->with('success', 'The new domain was dropped.');
    }

    public function activate(Request $request, #[CurrentUser] User $user, SwitchDashboardDomain $switch): HttpResponse
    {
        Gate::authorize('manageInstance');

        $target = $switch->handle($user, $this->auditActor($request, $user));

        return Inertia::location($target.route('dashboard-domain.edit', absolute: false));
    }

    public function revert(Request $request, #[CurrentUser] User $user, SwitchBackDashboardDomain $switchBack): HttpResponse
    {
        Gate::authorize('manageInstance');

        $target = $switchBack->handle($this->auditActor($request, $user));

        return Inertia::location($target.route('dashboard-domain.edit', absolute: false));
    }
}
