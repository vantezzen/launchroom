<?php

declare(strict_types=1);

namespace App\Services\Git;

use App\Enums\Git\GitAuthMethod;
use App\Enums\Git\GitConnectionStatus;
use App\Exceptions\Git\GitConnectionUnusable;
use App\Models\Git\GitConnection;
use App\Services\Crypto\SealedColumns;
use App\Services\Http\SafeHttpClient;
use App\Support\Json\JsonObject;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use SensitiveParameter;
use Throwable;

/**
 * The access token of a GitLab/Gitea connection (docs/19 §4.1): pasted
 * tokens as stored, OAuth tokens refreshed under a lock shortly before they
 * expire (both providers rotate the refresh token). A refused refresh marks
 * the connection `needs_reauth`.
 */
final readonly class GitConnectionTokens
{
    public function __construct(
        private SealedColumns $sealed,
        private SafeHttpClient $http,
        private GitProviders $providers,
    ) {}

    /**
     * @throws GitConnectionUnusable
     */
    public function accessToken(GitConnection $connection): string
    {
        if (! $connection->provider->hasApi() || ! in_array($connection->auth_method, [GitAuthMethod::OAuth, GitAuthMethod::Token], true)) {
            throw new GitConnectionUnusable('This connection has no API token.');
        }

        if ($connection->status !== GitConnectionStatus::Active) {
            throw new GitConnectionUnusable($connection->status === GitConnectionStatus::Pending
                ? "Finish connecting {$connection->name} in Settings → Git."
                : $this->reauthMessage($connection));
        }

        if ($connection->auth_method === GitAuthMethod::OAuth && $this->expiresSoon($connection)) {
            return $this->refresh($connection);
        }

        $token = $this->sealed->reveal($connection, 'access_token');

        if ($token === null) {
            throw new GitConnectionUnusable($this->reauthMessage($connection));
        }

        return $token;
    }

    public function store(GitConnection $connection, JsonObject $response): void
    {
        $this->sealed->seal($connection, 'access_token', $response->string('access_token'));
        $refresh = $response->stringOrNull('refresh_token');

        if ($refresh !== null) {
            $this->sealed->seal($connection, 'refresh_token', $refresh);
        }

        $expiresIn = $response->intOrNull('expires_in');
        $connection->token_expires_at = $expiresIn === null ? null : CarbonImmutable::now()->addSeconds(max(60, $expiresIn));
    }

    public function client(GitConnection $connection): SafeHttpClient
    {
        return $connection->allow_private_network ? $this->http->withPrivateNetwork() : $this->http;
    }

    private function expiresSoon(GitConnection $connection): bool
    {
        return $connection->token_expires_at !== null
            && $connection->token_expires_at->subSeconds(config()->integer('launchroom.git.token_refresh_margin_seconds'))->isPast();
    }

    /**
     * @throws GitConnectionUnusable
     */
    private function refresh(GitConnection $connection): string
    {
        $token = Cache::lock('launchroom:git:refresh:'.$connection->id, 30)->block(20, function () use ($connection): string {
            // Another worker may have refreshed while we waited.
            $connection->refresh();

            if (! $this->expiresSoon($connection) && ($current = $this->sealed->reveal($connection, 'access_token')) !== null) {
                return $current;
            }

            $refreshToken = $this->sealed->reveal($connection, 'refresh_token');
            $clientSecret = $this->sealed->reveal($connection, 'oauth_client_secret');

            if ($refreshToken === null || $clientSecret === null || $connection->oauth_client_id === null) {
                $this->needsReauth($connection, 'No refresh token.');

                throw new GitConnectionUnusable($this->reauthMessage($connection));
            }

            try {
                $response = $this->client($connection)->postForm($this->providers->api($connection->provider)->oauthTokenUrl($connection), $this->refreshForm($connection, $refreshToken, $clientSecret), ['Accept' => 'application/json']);
            } catch (Throwable $e) {
                Log::warning('Refreshing a git connection token failed', ['connection' => $connection->public_id, 'error' => $e->getMessage()]);

                throw new GitConnectionUnusable("{$connection->server_kind->label()} did not answer. Try again in a moment.", $e->getCode(), $e);
            }

            if ($response->status() === 400 || $response->status() === 401) {
                $this->needsReauth($connection, 'The refresh token was refused.');

                throw new GitConnectionUnusable($this->reauthMessage($connection));
            }

            if (! $response->successful()) {
                throw new GitConnectionUnusable("{$connection->server_kind->label()} answered {$response->status()} when launchroom refreshed its token. Try again in a moment.");
            }

            $this->store($connection, JsonObject::decode($response->body()));
            $connection->save();

            return (string) $this->sealed->reveal($connection, 'access_token');
        });

        return is_string($token) ? $token : throw new GitConnectionUnusable($this->reauthMessage($connection));
    }

    /**
     * @return array<string, string>
     */
    private function refreshForm(GitConnection $connection, #[SensitiveParameter] string $refreshToken, #[SensitiveParameter] string $clientSecret): array
    {
        return [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => (string) $connection->oauth_client_id,
            'client_secret' => $clientSecret,
            'redirect_uri' => route('git.oauth.callback'),
        ];
    }

    /**
     * Marks an active connection as needing a new sign-in (a refused
     * refresh, or the provider answering 401 to its token).
     */
    public function needsReauth(GitConnection $connection, string $reason): void
    {
        if ($connection->status !== GitConnectionStatus::Pending) {
            $connection->transitionTo(GitConnectionStatus::NeedsReauth, $reason);
        }
    }

    private function reauthMessage(GitConnection $connection): string
    {
        return "Sign in to {$connection->name} again in Settings → Git.";
    }
}
