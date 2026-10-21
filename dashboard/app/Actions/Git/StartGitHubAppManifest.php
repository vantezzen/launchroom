<?php

declare(strict_types=1);

namespace App\Actions\Git;

use App\Data\Git\ManifestRedirectData;
use App\Data\Git\StartManifestData;
use App\Exceptions\DomainRuleViolation;
use App\Models\Team;
use App\Models\User;
use App\Services\Http\Exceptions\UnsafeUrl;
use App\Services\Http\UrlGuard;
use App\Support\Crypto\OpaqueToken;
use App\Support\Git\WebhookReachability;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Starts the GitHub App manifest flow (docs/09 R19): a single-use state bound
 * to the team and user (60 min), and the manifest with the minimal
 * permissions. The browser posts it to GitHub, which redirects back to
 * {@see CompleteGitHubAppManifest} with a one-time code.
 */
final readonly class StartGitHubAppManifest
{
    public const string STATE_CACHE_PREFIX = 'launchroom:github:manifest:';

    /** docs/09 R19. */
    public const array PERMISSIONS = [
        'contents' => 'read',
        'metadata' => 'read',
        'pull_requests' => 'write',
        'checks' => 'write',
        'deployments' => 'write',
        'statuses' => 'write',
    ];

    /**
     * `installation` and `installation_repositories` are delivered to every
     * app without subscribing (GitHub rejects them in `default_events`).
     */
    public const array EVENTS = ['push', 'pull_request'];

    public function __construct(
        private UrlGuard $guard,
    ) {}

    public function handle(Team $team, User $user, StartManifestData $data): ManifestRedirectData
    {
        [$webUrl, $apiUrl] = $this->hosts($data->githubUrl);

        $state = OpaqueToken::generate('lr_ghm_');

        Cache::put(self::STATE_CACHE_PREFIX.OpaqueToken::hash($state), [
            'team_id' => $team->id,
            'user_id' => $user->id,
            'web_url' => $webUrl,
            'api_url' => $apiUrl,
        ], now()->addMinutes(config()->integer('launchroom.github.manifest_state_ttl_minutes')));

        $appUrl = rtrim(config()->string('app.url'), '/');

        $manifest = [
            // App names are global on GitHub (≤ 34 chars); users can rename later.
            'name' => 'launchroom-'.Str::lower(Str::random(10)),
            'url' => $appUrl,
            'description' => 'Deploys from your repositories with launchroom.',
            'redirect_url' => route('git.github.manifest.callback'),
            'setup_url' => route('git.github.setup'),
            'setup_on_update' => true,
            'public' => false,
            'default_permissions' => self::PERMISSIONS,
        ];

        // github.com refuses the whole manifest when the hook URL isn't
        // public (localhost, a LAN name), even for an inactive hook. Such an
        // app gets no webhook and its pushes arrive by polling (docs/06 §2).
        // GitHub Enterprise Server often lives on the same private network.
        if ($webUrl !== $this->defaultWebUrl() || WebhookReachability::isPublic(route('git.github.webhook'))) {
            $manifest['hook_attributes'] = ['url' => route('git.github.webhook'), 'active' => true];
            $manifest['default_events'] = self::EVENTS;
        }

        $path = $data->organization === null
            ? '/settings/apps/new'
            : '/organizations/'.rawurlencode($data->organization).'/settings/apps/new';

        return new ManifestRedirectData(
            action: $webUrl.$path.'?state='.rawurlencode($state),
            manifest: json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }

    private function defaultWebUrl(): string
    {
        return rtrim(config()->string('launchroom.github.web_url'), '/');
    }

    /**
     * github.com, or a GitHub Enterprise Server validated before anything is
     * sent to it (Dokploy lesson: the callback is a GET users can be linked into).
     *
     * @return array{0: string, 1: string} web URL, API URL
     */
    private function hosts(?string $githubUrl): array
    {
        $default = $this->defaultWebUrl();

        if ($githubUrl === null || rtrim($githubUrl, '/') === $default) {
            return [$default, rtrim(config()->string('launchroom.github.api_url'), '/')];
        }

        $webUrl = rtrim($githubUrl, '/');

        try {
            $this->guard->check($webUrl.'/');
        } catch (UnsafeUrl $e) {
            throw DomainRuleViolation::on('githubUrl', 'This GitHub Enterprise URL can’t be used: '.$e->getMessage());
        }

        return [$webUrl, $webUrl.'/api/v3'];
    }
}
