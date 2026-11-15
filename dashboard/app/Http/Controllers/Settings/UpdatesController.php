<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Updates\StartControlPlaneUpdate;
use App\Actions\Updates\StartFleetUpdate;
use App\Actions\Updates\UpdateUpdatePreferences;
use App\Data\Updates\ReleaseData;
use App\Data\Updates\StartControlPlaneUpdateInput;
use App\Data\Updates\UpdatePreferencesInput;
use App\Http\Controllers\Controller;
use App\Http\Toast;
use App\Models\User;
use App\Queries\UpdatesPage;
use App\Services\Updates\ReleaseChecker;
use App\Support\Refusal;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Updates (docs/11 §4), instance owners only: the installed
 * version, a newer release with its changelog, the control-plane update
 * and the rolling launchroomd update of all servers.
 */
final class UpdatesController extends Controller
{
    public function edit(UpdatesPage $page): Response
    {
        Gate::authorize('manageInstance');

        return Inertia::render('settings/updates', $page->get());
    }

    public function check(ReleaseChecker $releases): RedirectResponse
    {
        Gate::authorize('manageInstance');

        $result = $releases->check(fresh: true);

        if ($result->error !== null) {
            Toast::error($result->error);
        } else {
            Toast::success($result->available instanceof ReleaseData ? "launchroom {$result->available->version} is available." : 'launchroom is up to date.');
        }

        return back();
    }

    public function updateControlPlane(Request $request, #[CurrentUser] User $user, StartControlPlaneUpdateInput $input, StartControlPlaneUpdate $start): RedirectResponse
    {
        Gate::authorize('manageInstance');

        $update = $start->handle($user, $input, $this->auditActor($request, $user));

        Toast::success("Updating the control plane to {$update->to_version}.");

        return back();
    }

    public function updatePreferences(Request $request, #[CurrentUser] User $user, UpdatePreferencesInput $input, UpdateUpdatePreferences $update): RedirectResponse
    {
        Gate::authorize('manageInstance');

        $settings = $update->handle($user, $input, $this->auditActor($request, $user));

        Toast::success($settings->auto_update ? 'Automatic updates are on.' : 'Update settings saved.');

        return back();
    }

    public function updateFleet(Request $request, #[CurrentUser] User $user, StartFleetUpdate $start): RedirectResponse
    {
        Gate::authorize('manageInstance');

        $update = Refusal::orThrow($start->handle($user, $this->auditActor($request, $user)));

        Toast::success("Updating your servers to {$update->to_version}, one at a time.");

        return back();
    }
}
