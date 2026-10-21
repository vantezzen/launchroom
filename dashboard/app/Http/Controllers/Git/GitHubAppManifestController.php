<?php

declare(strict_types=1);

namespace App\Http\Controllers\Git;

use App\Actions\Git\ConsumeManifestState;
use App\Actions\Git\RegisterGitHubApp;
use App\Actions\Git\StartGitHubAppManifest;
use App\Data\Git\StartManifestData;
use App\Exceptions\DomainRuleViolation;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Teams\TeamSettingsController;
use App\Models\Git\GitHubApp;
use App\Models\User;
use App\Support\Git\GitHubReturnTo;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * "Create GitHub App" through the manifest flow (docs/09 R19).
 */
final class GitHubAppManifestController extends Controller
{
    public function store(Request $request, StartManifestData $data, #[CurrentUser] User $user, StartGitHubAppManifest $start): RedirectResponse
    {
        $team = TeamSettingsController::currentTeam($user);
        Gate::authorize('create', [GitHubApp::class, $team]);

        // Where the round trip returns (onboarding's inline "Connect
        // GitHub"); an allow-listed key, onboarding only for instance owners.
        $returnTo = GitHubReturnTo::key($request->input('returnTo'));
        if ($returnTo === 'onboarding' && ! Gate::allows('manageInstance')) {
            $returnTo = null;
        }
        $returnTo ??= GitHubReturnTo::DEFAULT;
        $request->session()->put(GitHubReturnTo::SESSION_KEY, $returnTo);

        // The page we return to posts this form to GitHub on the next view.
        // Flashed as an array: the session is JSON (ManifestRedirectData).
        $request->session()->flash(GitSettingsController::MANIFEST_FLASH_KEY, $start->handle($team, $user, $data)->toSession());

        return to_route(GitHubReturnTo::route($returnTo));
    }

    /**
     * GitHub redirects here with a one-time `code` and our `state`.
     */
    public function callback(Request $request, #[CurrentUser] User $user, ConsumeManifestState $consume, RegisterGitHubApp $register): RedirectResponse
    {
        $code = $request->query('code');
        $state = $request->query('state');
        $returnRoute = GitHubReturnTo::route($request->session()->pull(GitHubReturnTo::SESSION_KEY));

        try {
            $manifestState = $consume->handle($user, is_string($state) ? $state : '');
            Gate::authorize('create', [GitHubApp::class, $manifestState->team]);

            $app = $register->handle($manifestState, is_string($code) ? $code : '', $user, $this->auditActor($request, $user));
        } catch (DomainRuleViolation $e) {
            // A GET from GitHub: "back" would leave the dashboard.
            return to_route($returnRoute)->withErrors([$e->field => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "GitHub App {$app->name} created. Install it on an account to add repositories."]);

        return to_route($returnRoute);
    }
}
