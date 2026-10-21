<?php

declare(strict_types=1);

namespace App\Services\GitHub;

use App\Models\Git\GitHubApp;
use App\Services\GitHub\Dto\InstallationInfo;
use App\Services\GitHub\Dto\RepositoryInfo;
use App\Services\GitHub\Exceptions\GitHubClockSkew;
use App\Services\GitHub\Exceptions\GitHubRequestFailed;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache as CacheFacade;
use LogicException;
use SensitiveParameter;
use Throwable;

/**
 * GitHub API access as one team's GitHub App (docs/10 §2.1 `GitHub\AppClient`).
 *
 * - App JWT (RS256, iat − 60 s, exp ≤ 9 min) for app-level endpoints.
 * - Installation tokens narrowed to one repository and the minimal
 *   permissions ({@see TokenScope}); cached until shortly before expiry and
 *   refreshed under a cache lock so concurrent jobs mint one token
 *   (docs/10 §2.2 rule 5). Cached tokens are encrypted with APP_KEY.
 */
final readonly class GitHubAppClient
{
    public function __construct(
        public GitHubApp $app,
        private GitHubApi $api,
        private GitHubAppSecrets $secrets,
        private GitHubClock $clock,
        private Cache $cache,
        private Encrypter $encrypter,
    ) {}

    public function jwt(): string
    {
        return AppJwt::issue(
            $this->app->app_id,
            $this->secrets->privateKey($this->app),
            $this->clock->now(),
            config()->integer('launchroom.github.jwt_ttl_seconds'),
        );
    }

    /**
     * `GET /app/installations/{id}` as this app; null if the installation
     * doesn't exist or belongs to another app (GitHub answers 404).
     *
     * @throws GitHubRequestFailed
     */
    public function installation(int $installationId): ?InstallationInfo
    {
        $response = $this->asApp('GET', "/app/installations/{$installationId}", accept: [404]);

        if ($response->status() === 404) {
            return null;
        }

        $info = InstallationInfo::fromJson(JsonObject::decode($response->body()));

        // Defence in depth: GitHub only returns its own installations.
        return $info->appId === $this->app->app_id && $info->id === $installationId ? $info : null;
    }

    /**
     * Every repository the installation can access (paginated).
     *
     * @return list<RepositoryInfo>
     *
     * @throws GitHubRequestFailed
     */
    public function installationRepositories(int $installationId): array
    {
        $repositories = [];

        for ($page = 1; $page <= 100; $page++) {
            $response = $this->asInstallation($installationId, TokenScope::listRepositories(), 'GET', '/installation/repositories', query: ['per_page' => 100, 'page' => $page]);
            $json = JsonObject::decode($response->body());

            foreach ($json->objects('repositories') as $repository) {
                $repositories[] = RepositoryInfo::fromJson($repository);
            }

            if (count($repositories) >= ($json->intOrNull('total_count') ?? 0) || $json->objects('repositories') === []) {
                break;
            }
        }

        return $repositories;
    }

    /**
     * A token for one installation and scope, from cache when still fresh.
     * $fresh mints a new token that is neither read from nor written to the
     * cache: clone tokens handed to a build daemon must not be reused by
     * later builds (docs/09 R19).
     *
     * @throws GitHubRequestFailed
     */
    public function installationToken(int $installationId, TokenScope $scope, bool $fresh = false): InstallationToken
    {
        if ($fresh) {
            return $this->mintToken($installationId, $scope);
        }

        $key = "launchroom:github:token:{$this->app->id}:{$installationId}:{$scope->cacheKey()}";

        $cached = $this->cachedToken($key, $scope);

        if ($cached instanceof InstallationToken) {
            return $cached;
        }

        $token = CacheFacade::lock($key.':lock', 30)->block(15, function () use ($key, $installationId, $scope): InstallationToken {
            // Another worker may have refreshed it while we waited.
            $cached = $this->cachedToken($key, $scope);

            if ($cached instanceof InstallationToken) {
                return $cached;
            }

            $token = $this->mintToken($installationId, $scope);

            $ttl = (int) CarbonImmutable::now()->diffInSeconds(
                $token->expiresAt->subSeconds(config()->integer('launchroom.github.token_refresh_margin_seconds')),
                false,
            );

            if ($ttl > 0) {
                $this->cache->put($key, $this->encrypter->encryptString(json_encode([
                    'token' => $token->reveal(),
                    'expires_at' => $token->expiresAt->toIso8601String(),
                ], JSON_THROW_ON_ERROR)), $ttl);
            }

            return $token;
        });

        if (! $token instanceof InstallationToken) {
            throw new LogicException('The token refresh returned no token.');
        }

        return $token;
    }

    /**
     * @throws GitHubRequestFailed
     */
    private function mintToken(int $installationId, TokenScope $scope): InstallationToken
    {
        $response = $this->asApp('POST', "/app/installations/{$installationId}/access_tokens", $scope->requestBody());
        $json = JsonObject::decode($response->body());

        return new InstallationToken(
            $json->string('token'),
            CarbonImmutable::parse($json->string('expires_at')),
            $scope->repositoryIds,
            $scope->permissions,
        );
    }

    /**
     * An app-level call authenticated with the App JWT.
     *
     * @param  'GET'|'POST'|'PATCH'|'PUT'|'DELETE'  $method
     * @param  array<array-key, mixed>|null  $json
     * @param  list<int>  $accept
     *
     * @throws GitHubRequestFailed
     * @throws GitHubClockSkew when GitHub rejects the JWT and our clock is off
     */
    public function asApp(string $method, string $path, ?array $json = null, array $accept = []): Response
    {
        try {
            return $this->api->send($this->app->api_url, $method, $path, $this->jwt(), $json, accept: $accept);
        } catch (GitHubRequestFailed $e) {
            if ($e->status === 401 && $this->clock->isSkewed()) {
                throw GitHubClockSkew::of($this->clock->skew() ?? 0);
            }

            throw $e;
        }
    }

    /**
     * A repository-level call with an installation token of the given scope.
     *
     * @param  'GET'|'POST'|'PATCH'|'PUT'|'DELETE'  $method
     * @param  array<array-key, mixed>|null  $json
     * @param  array<string, string|int>  $query
     * @param  array<string, string>  $headers
     * @param  list<int>  $accept
     *
     * @throws GitHubRequestFailed
     */
    public function asInstallation(
        int $installationId,
        TokenScope $scope,
        string $method,
        string $path,
        ?array $json = null,
        array $query = [],
        array $headers = [],
        array $accept = [],
    ): Response {
        $token = $this->installationToken($installationId, $scope);

        return $this->api->send($this->app->api_url, $method, $path, $token->reveal(), $json, $query, $headers, $accept);
    }

    private function cachedToken(string $key, TokenScope $scope): ?InstallationToken
    {
        $sealed = $this->cache->get($key);

        if (! is_string($sealed)) {
            return null;
        }

        try {
            $json = JsonObject::decode($this->encrypter->decryptString($sealed));

            return $this->token($json->string('token'), $json->string('expires_at'), $scope);
        } catch (Throwable) {
            $this->cache->forget($key);

            return null;
        }
    }

    private function token(#[SensitiveParameter] string $token, string $expiresAt, TokenScope $scope): ?InstallationToken
    {
        $expires = CarbonImmutable::parse($expiresAt);

        if ($expires->subSeconds(config()->integer('launchroom.github.token_refresh_margin_seconds'))->isPast()) {
            return null;
        }

        return new InstallationToken($token, $expires, $scope->repositoryIds, $scope->permissions);
    }
}
