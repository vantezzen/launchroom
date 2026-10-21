<?php

declare(strict_types=1);

namespace App\Http\Controllers\Git;

use App\Actions\Git\CompleteGitProviderConnection;
use App\Exceptions\DomainRuleViolation;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The OAuth redirect target for GitLab and Gitea/Forgejo applications
 * (`/settings/git/oauth/callback`, docs/19 §4.1). Authorization happens in
 * the action: the state is bound to the user who started the flow and their
 * permission on the connection's team is checked again.
 */
final class GitOAuthController extends Controller
{
    public function __invoke(Request $request, #[CurrentUser] User $user, CompleteGitProviderConnection $complete): RedirectResponse
    {
        $state = $request->query('state');
        $code = $request->query('code');

        if (! is_string($state) || preg_match('/^[a-f0-9]{64}$/D', $state) !== 1 || ! is_string($code) || $code === '' || strlen($code) > 1024) {
            $message = is_string($request->query('error_description')) ? mb_substr($request->query('error_description'), 0, 200) : 'The sign-in was cancelled.';

            return to_route('git.edit')->withErrors(['git' => $message]);
        }

        try {
            $connection = $complete->handle($user, $state, $code, $this->auditActor($request, $user));
        } catch (DomainRuleViolation $e) {
            return to_route('git.edit')->withErrors(['git' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "Connected {$connection->name}. Your repositories show up when you import a project."]);

        return to_route('git.edit');
    }
}
