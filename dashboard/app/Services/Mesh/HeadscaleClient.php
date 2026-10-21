<?php

declare(strict_types=1);

namespace App\Services\Mesh;

use App\Services\Mesh\Exceptions\HeadscaleUnavailable;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;

/**
 * Headscale's REST API (v0.29, `/api/v1`, bearer API key), docs/05 §2.
 *
 * The base URL is the control-plane-internal address of Headscale
 * (`launchroom.mesh.headscale_url`, e.g. http://headscale:8080 on the
 * lr-system network), never a user-supplied URL. Every call is bounded
 * (3 s connect, 10 s total); failures become HeadscaleUnavailable with a
 * short, key-free reason.
 */
final readonly class HeadscaleClient
{
    public function __construct(
        private string $baseUrl,
        #[SensitiveParameter]
        private string $apiKey,
    ) {}

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->baseUrl, 'apiKey' => '[redacted]'];
    }

    public function userId(string $name): ?string
    {
        foreach ($this->list('GET', '/api/v1/user', 'users', ['name' => $name]) as $user) {
            if (is_array($user) && ($user['name'] ?? null) === $name && is_scalar($user['id'] ?? null)) {
                return (string) $user['id'];
            }
        }

        return null;
    }

    /**
     * The user's id, creating the user if it does not exist.
     */
    public function ensureUser(string $name): string
    {
        $id = $this->userId($name);

        if ($id !== null) {
            return $id;
        }

        $user = $this->call('POST', '/api/v1/user', ['name' => $name], 'create user')->json('user.id');

        if (! is_scalar($user)) {
            throw HeadscaleUnavailable::because('create user', 'unexpected response');
        }

        return (string) $user;
    }

    /**
     * A single-use, non-ephemeral pre-auth key whose nodes get exactly $tags.
     *
     * @param  list<string>  $tags
     */
    public function createPreAuthKey(string $userId, array $tags, CarbonInterface $expiresAt): HeadscalePreAuthKey
    {
        $response = $this->call('POST', '/api/v1/preauthkey', [
            'user' => $userId,
            'reusable' => false,
            'ephemeral' => false,
            'expiration' => $expiresAt->toImmutable()->utc()->format('Y-m-d\TH:i:s\Z'),
            'aclTags' => $tags,
        ], 'create pre-auth key');

        $id = $response->json('preAuthKey.id');
        $key = $response->json('preAuthKey.key');
        $expiration = $response->json('preAuthKey.expiration');

        if (! is_scalar($id) || ! is_string($key) || $key === '') {
            throw HeadscaleUnavailable::because('create pre-auth key', 'unexpected response');
        }

        return new HeadscalePreAuthKey((string) $id, $key, is_string($expiration) ? CarbonImmutable::parse($expiration) : $expiresAt->toImmutable());
    }

    public function expirePreAuthKey(string $id): void
    {
        $this->call('POST', '/api/v1/preauthkey/expire', ['id' => $id], 'expire pre-auth key', allowNotFound: true);
    }

    /**
     * @return list<HeadscaleNode>
     */
    public function nodes(): array
    {
        $nodes = [];

        foreach ($this->list('GET', '/api/v1/node', 'nodes') as $node) {
            if (! is_array($node) || ! is_scalar($node['id'] ?? null)) {
                continue;
            }

            $nodes[] = new HeadscaleNode(
                id: (string) $node['id'],
                name: is_string($node['givenName'] ?? null) && $node['givenName'] !== '' ? $node['givenName'] : (is_string($node['name'] ?? null) ? $node['name'] : ''),
                ipAddresses: $this->strings($node['ipAddresses'] ?? null),
                tags: $this->strings($node['tags'] ?? null),
                online: ($node['online'] ?? false) === true,
                preAuthKeyId: is_array($node['preAuthKey'] ?? null) && is_scalar($node['preAuthKey']['id'] ?? null) ? (string) $node['preAuthKey']['id'] : null,
            );
        }

        return $nodes;
    }

    public function deleteNode(string $id): void
    {
        $this->call('DELETE', '/api/v1/node/'.rawurlencode($id), null, 'delete node', allowNotFound: true);
    }

    /**
     * @param  list<string>  $tags
     */
    public function setTags(string $nodeId, array $tags): void
    {
        $this->call('POST', '/api/v1/node/'.rawurlencode($nodeId).'/tags', ['tags' => $tags], 'set tags');
    }

    /**
     * The policy Headscale currently enforces ('' when none is set).
     */
    public function policy(): string
    {
        $policy = $this->call('GET', '/api/v1/policy', null, 'get policy', allowNotFound: true)->json('policy');

        return is_string($policy) ? $policy : '';
    }

    public function setPolicy(string $policy): void
    {
        $this->call('PUT', '/api/v1/policy', ['policy' => $policy], 'set policy');
    }

    /**
     * A new API key, valid until $expiresAt.
     */
    public function createApiKey(CarbonInterface $expiresAt): string
    {
        $key = $this->call('POST', '/api/v1/apikey', ['expiration' => $expiresAt->toImmutable()->utc()->format('Y-m-d\TH:i:s\Z')], 'create API key')->json('apiKey');

        if (! is_string($key) || $key === '') {
            throw HeadscaleUnavailable::because('create API key', 'unexpected response');
        }

        return $key;
    }

    public function expireApiKey(string $prefix): void
    {
        $this->call('POST', '/api/v1/apikey/expire', ['prefix' => $prefix], 'expire API key', allowNotFound: true);
    }

    /**
     * The prefix Headscale lists an API key under: `hskey-api-<prefix>-<secret>`
     * keys (0.28+) use the 12 characters after `hskey-api-`; legacy keys are
     * `<prefix>.<secret>`.
     */
    public static function apiKeyPrefix(#[SensitiveParameter] string $key): ?string
    {
        if (preg_match('/^hskey-api-([A-Za-z0-9_]{12})-/', $key, $m) === 1) {
            return $m[1];
        }

        if (preg_match('/^([A-Za-z0-9_-]{4,32})\./', $key, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * @param  array<string, string>  $query
     * @return list<mixed>
     */
    private function list(string $method, string $path, string $key, array $query = []): array
    {
        $items = $this->call($method, $path.($query === [] ? '' : '?'.http_build_query($query)), null, 'list '.$key)->json($key);

        return is_array($items) ? array_values($items) : [];
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private function call(string $method, string $path, ?array $body, string $operation, bool $allowNotFound = false): Response
    {
        try {
            $response = $this->request()->send($method, $path, $body === null ? [] : ['json' => $body]);
        } catch (ConnectionException) {
            throw HeadscaleUnavailable::because($operation, 'Headscale is not reachable');
        }

        if ($allowNotFound && $response->status() === 404) {
            return $response;
        }

        if (! $response->successful()) {
            $message = $response->json('message');
            $message = is_string($message) ? (string) preg_replace('/hskey-[A-Za-z0-9_-]+/', '[redacted]', mb_substr($message, 0, 200)) : '';
            $reason = 'HTTP '.$response->status().($message !== '' ? ': '.$message : '');

            throw HeadscaleUnavailable::because($operation, $reason);
        }

        return $response;
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl, '/'))
            ->withToken($this->apiKey)
            ->acceptJson()
            ->connectTimeout(3)
            ->timeout(10)
            ->withoutRedirecting();
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }
}
