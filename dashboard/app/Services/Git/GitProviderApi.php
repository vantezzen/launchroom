<?php

declare(strict_types=1);

namespace App\Services\Git;

use App\Models\Git\GitConnection;
use App\Services\Git\Exceptions\GitConnectionUnusable;
use App\Services\Git\Exceptions\GitProviderRequestFailed;
use Illuminate\Http\Client\Response;
use SensitiveParameter;
use Throwable;

/**
 * Low-level GitLab (`/api/v4`) and Gitea (`/api/v1`) REST calls for one
 * connection. Everything goes through SafeHttpClient (docs/09 R5) with the
 * connection's private-network override when an instance owner set it; the
 * token only ever travels in the Authorization header (Bearer works for
 * OAuth and access tokens on both providers).
 */
final readonly class GitProviderApi
{
    public function __construct(
        private GitConnectionTokens $tokens,
    ) {}

    /**
     * @param  'GET'|'POST'|'PATCH'|'PUT'|'DELETE'  $method
     * @param  string  $path  API path below the API root, e.g. `/projects/1`
     * @param  array<array-key, mixed>|null  $json
     * @param  array<string, string|int>  $query
     * @param  list<int>  $accept  non-2xx statuses the caller handles itself
     * @param  string|null  $token  an explicit token (while connecting); default: the connection's
     * @param  array<string, string>  $headers
     *
     * @throws GitProviderRequestFailed
     * @throws GitConnectionUnusable
     */
    public function send(
        GitConnection $connection,
        string $method,
        string $path,
        ?array $json = null,
        array $query = [],
        array $accept = [],
        #[SensitiveParameter] ?string $token = null,
        array $headers = [],
    ): Response {
        $token ??= $this->tokens->accessToken($connection);
        $label = $connection->server_kind->label();

        try {
            $response = $this->tokens->client($connection)->request($method, $connection->apiUrl().$path, $json, [
                'Accept' => 'application/json',
                ...$headers,
                'Authorization' => 'Bearer '.$token,
            ], $query);
        } catch (Throwable $e) {
            throw new GitProviderRequestFailed("{$label} {$method} {$path} failed: ".mb_substr($e->getMessage(), 0, 200), 0);
        }

        if ($response->status() === 401 && ! in_array(401, $accept, true)) {
            $this->tokens->needsReauth($connection, "{$label} refused the token.");
        }

        if (! $response->successful() && ! in_array($response->status(), $accept, true)) {
            throw GitProviderRequestFailed::fromResponse($label, $method, $path, $response);
        }

        return $response;
    }
}
