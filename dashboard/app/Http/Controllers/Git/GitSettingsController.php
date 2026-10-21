<?php

declare(strict_types=1);

namespace App\Http\Controllers\Git;

use App\Data\Git\GitConnectionData;
use App\Data\Git\GitHubAppData;
use App\Data\Git\ManifestRedirectData;
use App\Enums\Git\GitProviderKind;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Teams\TeamSettingsController;
use App\Models\Git\GitConnection;
use App\Models\Git\GitHubApp;
use App\Models\User;
use App\Services\Git\Providers\GiteaProvider;
use App\Services\Git\Providers\GitLabProvider;
use App\Support\Git\WebhookReachability;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Git (docs/19 §4): a provider picker, the team's GitHub Apps and
 * installations, and its GitLab, Gitea/Forgejo and plain git connections,
 * each with one health line.
 */
final class GitSettingsController extends Controller
{
    public const string MANIFEST_FLASH_KEY = 'launchroom.github_manifest';

    public function edit(Request $request, #[CurrentUser] User $user, GitLabProvider $gitlab, GiteaProvider $gitea): Response
    {
        $team = TeamSettingsController::currentTeam($user);
        Gate::authorize('viewAny', [GitHubApp::class, $team]);

        $canManage = Gate::allows('create', [GitHubApp::class, $team]);

        $apps = GitHubApp::query()
            ->where('team_id', $team->id)
            ->with([
                'latestDelivery',
                'installations' => static fn (HasMany $installations): HasMany => $installations
                    ->whereNull('removed_at')
                    ->withCount('repositories')
                    ->orderBy('account_login'),
            ])
            ->orderBy('id')
            ->get()
            ->map(fn (GitHubApp $app): GitHubAppData => GitHubAppData::fromApp($app, Gate::allows('delete', $app)))
            ->all();

        $connections = GitConnection::query()
            ->where('team_id', $team->id)
            ->withCount('repositories')
            ->orderBy('id')
            ->get()
            ->map(static fn (GitConnection $connection): GitConnectionData => GitConnectionData::fromConnection(
                $connection,
                Gate::allows('delete', $connection),
                is_numeric($count = $connection->getAttribute('repositories_count')) ? (int) $count : 0,
                match ($connection->provider) {
                    GitProviderKind::GitLab => $gitlab->webhookUrl($connection),
                    GitProviderKind::Gitea => $gitea->webhookUrl($connection),
                    default => route('git.webhooks.git', ['connection' => $connection->public_id]),
                },
            ))
            ->all();

        $setup = $request->query('setup');

        $manifest = ManifestRedirectData::fromSession($request->session()->get(self::MANIFEST_FLASH_KEY));

        return Inertia::render('settings/git', [
            'apps' => array_values($apps),
            'connections' => array_values($connections),
            // A plain git server to show the guided setup for (after adding it).
            'setup' => is_string($setup) && $canManage ? $setup : null,
            'oauthCallbackUrl' => route('git.oauth.callback'),
            'gitlabUrl' => config()->string('launchroom.git.gitlab_url'),
            'canAllowPrivateNetwork' => $user->is_instance_owner,
            'canManage' => $canManage,
            'pendingManifest' => $canManage ? $manifest : null,
            'webhookUrl' => route('git.github.webhook'),
            // False for localhost/LAN addresses: github.com won't create an
            // app with that webhook, so the app gets none (polling instead).
            'webhookReachable' => WebhookReachability::isPublic(route('git.github.webhook')),
        ]);
    }
}
