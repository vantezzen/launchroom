<?php

declare(strict_types=1);

namespace App\Http\Controllers\Git;

use App\Actions\Git\LinkGitHubInstallation;
use App\Exceptions\DomainRuleViolation;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Teams\TeamSettingsController;
use App\Jobs\Git\RefreshInstallationRepositoriesJob;
use App\Models\Git\GitHubInstallation;
use App\Models\User;
use App\Services\GitHub\Exceptions\GitHubRequestFailed;
use App\Support\Git\GitHubReturnTo;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

final class GitHubInstallationController extends Controller
{
    /**
     * GitHub's setup URL after installing the app. `installation_id` is
     * untrusted input; the action verifies it with GitHub (docs/09 R18).
     */
    public function setup(Request $request, #[CurrentUser] User $user, LinkGitHubInstallation $link): RedirectResponse
    {
        $team = TeamSettingsController::currentTeam($user);
        Gate::authorize('create', [GitHubInstallation::class, $team]);
        // GitHub passes the install link's `state` through: an allow-listed
        // key for where the flow started (onboarding or Settings → Git).
        $back = GitHubReturnTo::route($request->query('state'));
        if ($back === 'onboarding.show' && ! Gate::allows('manageInstance')) {
            $back = 'git.edit';
        }

        if ($request->query('setup_action') === 'request') {
            Inertia::flash('toast', ['type' => 'info', 'message' => 'Installation requested. An owner of the GitHub account has to approve it.']);

            return to_route($back);
        }

        $installationId = $request->query('installation_id');

        if (! is_string($installationId) || preg_match('/^[1-9][0-9]{0,18}$/D', $installationId) !== 1) {
            return to_route($back)->withErrors(['github' => 'GitHub didn’t send a valid installation.']);
        }

        try {
            $installation = $link->handle($team, (int) $installationId, $this->auditActor($request, $user));
        } catch (DomainRuleViolation $e) {
            return to_route($back)->withErrors([$e->field => $e->getMessage()]);
        } catch (GitHubRequestFailed) {
            return to_route($back)->withErrors(['github' => 'GitHub couldn’t be reached to confirm the installation. Try again from GitHub.']);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "Installed on {$installation->account_login}."]);

        return to_route($back);
    }

    public function refresh(GitHubInstallation $githubInstallation): RedirectResponse
    {
        Gate::authorize('refresh', $githubInstallation);

        dispatch(new RefreshInstallationRepositoriesJob($githubInstallation->id));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Refreshing repositories from GitHub.']);

        return back();
    }
}
