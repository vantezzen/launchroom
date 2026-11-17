<?php

declare(strict_types=1);

namespace App\Http\Controllers\Registry;

use App\Actions\Registry\CheckRegistryCredential;
use App\Actions\Registry\DeleteRegistryCredential;
use App\Actions\Registry\SaveRegistryCredential;
use App\Data\Registry\RegistryCredentialInput;
use App\Http\Controllers\Controller;
use App\Http\Routing\CurrentTeam;
use App\Http\Toast;
use App\Models\Registry\RegistryCredential;
use App\Models\Team;
use App\Models\User;
use App\Queries\RegistryCredentials;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Private registries (D91, docs/05 §6): a team's sign-in details
 * for registries that hold its private images. Credentials of other teams
 * are never reachable here: the bound row must belong to the current team.
 */
final class RegistryCredentialController extends Controller
{
    public function index(#[CurrentTeam] Team $team, #[CurrentUser] User $user, RegistryCredentials $query): Response
    {
        Gate::authorize('viewAny', [RegistryCredential::class, $team]);

        return Inertia::render('settings/registries', ['registries' => $query->forTeam($team, $user)]);
    }

    public function store(Request $request, RegistryCredentialInput $input, #[CurrentTeam] Team $team, #[CurrentUser] User $user, SaveRegistryCredential $save, CheckRegistryCredential $check): RedirectResponse
    {
        Gate::authorize('create', [RegistryCredential::class, $team]);

        $credential = $save->handle($team, $input, $user, $this->auditActor($request, $user));
        $error = $check->handle($credential);

        if ($error === null) {
            Toast::success("Signed in to {$credential->host}. Images there now deploy from your own copy.");
        } else {
            Toast::error('Saved, but the sign-in didn’t work: '.$error);
        }

        return back();
    }

    public function update(Request $request, RegistryCredential $registryCredential, RegistryCredentialInput $input, #[CurrentTeam] Team $team, #[CurrentUser] User $user, SaveRegistryCredential $save, CheckRegistryCredential $check): RedirectResponse
    {
        $this->ownedByTeam($registryCredential, $team);
        Gate::authorize('update', $registryCredential);

        $save->handle($team, $input, $user, $this->auditActor($request, $user), $registryCredential);
        $error = $check->handle($registryCredential);

        if ($error === null) {
            Toast::success('Sign-in details saved.');
        } else {
            Toast::error('Saved, but the sign-in didn’t work: '.$error);
        }

        return back();
    }

    public function check(RegistryCredential $registryCredential, #[CurrentTeam] Team $team, CheckRegistryCredential $check): RedirectResponse
    {
        $this->ownedByTeam($registryCredential, $team);
        Gate::authorize('update', $registryCredential);

        $error = $check->handle($registryCredential);

        if ($error === null) {
            Toast::success("Signed in to {$registryCredential->host}.");
        } else {
            Toast::error($error);
        }

        return back();
    }

    public function destroy(Request $request, RegistryCredential $registryCredential, #[CurrentTeam] Team $team, #[CurrentUser] User $user, DeleteRegistryCredential $delete): RedirectResponse
    {
        $this->ownedByTeam($registryCredential, $team);
        Gate::authorize('delete', $registryCredential);

        $delete->handle($registryCredential, $this->auditActor($request, $user));

        Toast::success('Removed. Images already copied keep running.');

        return back();
    }

    private function ownedByTeam(RegistryCredential $credential, Team $team): void
    {
        abort_unless($credential->team_id === $team->id, 404);
    }
}
