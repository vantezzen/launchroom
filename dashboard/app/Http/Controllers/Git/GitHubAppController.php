<?php

declare(strict_types=1);

namespace App\Http\Controllers\Git;

use App\Actions\Git\DisconnectGitHubApp;
use App\Http\Controllers\Controller;
use App\Http\Toast;
use App\Models\Git\GitHubApp;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class GitHubAppController extends Controller
{
    public function destroy(Request $request, GitHubApp $githubApp, #[CurrentUser] User $user, DisconnectGitHubApp $disconnect): RedirectResponse
    {
        Gate::authorize('delete', $githubApp);

        $disconnect->handle($githubApp, $this->auditActor($request, $user));

        Toast::success("Disconnected {$githubApp->name}. You can delete the app on GitHub.");

        return to_route('git.edit');
    }
}
