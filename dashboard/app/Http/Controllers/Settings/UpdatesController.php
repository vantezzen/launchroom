<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Updates\CancelControlPlaneUpdate;
use App\Actions\Updates\CancelFleetUpdate;
use App\Actions\Updates\StartControlPlaneUpdate;
use App\Actions\Updates\StartFleetUpdate;
use App\Actions\Updates\UpdateUpdatePreferences;
use App\Data\Updates\ReleaseData;
use App\Data\Updates\StartControlPlaneUpdateInput;
use App\Data\Updates\UpdatePreferencesInput;
use App\Enums\Updates\FleetUpdateStatus;
use App\Http\Controllers\Controller;
use App\Http\Toast;
use App\Models\Updates\ControlPlaneUpdate;
use App\Models\Updates\FleetUpdate;
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

    /** "Cancel update": before launchroom started installing, nothing has changed (docs/11 §4.3). */
    public function cancelControlPlane(Request $request, #[CurrentUser] User $user, ControlPlaneUpdate $update, CancelControlPlaneUpdate $cancel): RedirectResponse
    {
        Gate::authorize('manageInstance');

        Refusal::orThrow($cancel->handle($update, $this->auditActor($request, $user)));

        Toast::success("The update to {$update->to_version} was cancelled. Nothing was changed.");

        return back();
    }

    /** "Stop updating servers": the servers not yet updated are skipped. */
    public function cancelFleet(Request $request, #[CurrentUser] User $user, FleetUpdate $fleet, CancelFleetUpdate $cancel): RedirectResponse
    {
        Gate::authorize('manageInstance');

        $result = Refusal::orThrow($cancel->handle($fleet, $this->auditActor($request, $user)));

        Toast::success($result->status === FleetUpdateStatus::Cancelled
            ? 'Stopped. Servers that weren’t updated keep their version.'
            : 'Stopping. The server that is updating finishes first, the others keep their version.');

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
