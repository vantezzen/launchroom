<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Registry\ConfigureRegistryStorage;
use App\Actions\Registry\RemoveRegistryStorage;
use App\Data\Registry\RegistryStorageInput;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Queries\RegistrySettings;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Registry (docs/14 §9.11), instance owners only: keep the
 * registry's images on an S3-compatible bucket instead of the control-plane
 * disk. Saving stores the settings; `./launchroom registry apply` on the
 * control-plane server writes Zot's config and restarts it.
 */
final class RegistrySettingsController extends Controller
{
    public function edit(RegistrySettings $settings): Response
    {
        Gate::authorize('manageInstance');

        return Inertia::render('settings/registry', ['settings' => $settings->get()]);
    }

    public function update(Request $request, RegistryStorageInput $input, #[CurrentUser] User $user, ConfigureRegistryStorage $configure): RedirectResponse
    {
        Gate::authorize('manageInstance');

        $configure->handle($input, $user, $this->auditActor($request, $user));

        return back()->with('success', 'Registry storage saved. Run '.RegistrySettings::APPLY_COMMAND.' on the control-plane server to use it.');
    }

    public function destroy(Request $request, #[CurrentUser] User $user, RemoveRegistryStorage $remove): RedirectResponse
    {
        Gate::authorize('manageInstance');

        $remove->handle($user, $this->auditActor($request, $user));

        return back()->with('success', 'The registry goes back to local storage after '.RegistrySettings::APPLY_COMMAND.'.');
    }
}
