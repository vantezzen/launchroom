<?php

declare(strict_types=1);

namespace App\Http\Controllers\Git;

use App\Actions\Git\DisconnectGitConnection;
use App\Actions\Git\StartGitProviderConnection;
use App\Data\Git\ConnectGitProviderData;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Teams\TeamSettingsController;
use App\Models\Git\GitConnection;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Settings → Git: connect GitLab or Gitea/Forgejo (OAuth → the provider's
 * authorize page, or an access token), and disconnect any git connection
 * (docs/19 §4).
 */
final class GitConnectionController extends Controller
{
    public function store(Request $request, ConnectGitProviderData $data, #[CurrentUser] User $user, StartGitProviderConnection $start): Response
    {
        $team = TeamSettingsController::currentTeam($user);
        Gate::authorize('create', [GitConnection::class, $team]);

        $authorizeUrl = $start->handle($team, $user, $data, $this->auditActor($request, $user));

        if ($authorizeUrl !== null) {
            // Leave the SPA for the provider's sign-in page.
            return Inertia::location($authorizeUrl);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Connected. Your repositories show up when you import a project.']);

        return to_route('git.edit');
    }

    public function destroy(Request $request, GitConnection $gitConnection, #[CurrentUser] User $user, DisconnectGitConnection $disconnect): RedirectResponse
    {
        Gate::authorize('delete', $gitConnection);

        $disconnect->handle($gitConnection, $this->auditActor($request, $user));

        Inertia::flash('toast', ['type' => 'success', 'message' => "Disconnected {$gitConnection->name}."]);

        return to_route('git.edit');
    }
}
