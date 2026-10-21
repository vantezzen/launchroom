<?php

declare(strict_types=1);

namespace App\Services\GitHub;

use App\Services\GitHub\Exceptions\GitHubRequestFailed;
use App\Services\Http\SafeHttpClient;
use Illuminate\Http\Client\Response;
use SensitiveParameter;

/**
 * Low-level GitHub REST calls. Everything goes through SafeHttpClient
 * (docs/09 R5): GitHub Enterprise base URLs are user input, and github.com
 * passes the same checks. Credentials only ever travel in the Authorization
 * header, never in URLs (docs/09 R19).
 */
final readonly class GitHubApi
{
    private const string API_VERSION = '2022-11-28';

    public function __construct(
        private SafeHttpClient $http,
        private GitHubClock $clock,
    ) {}

    /**
     * @param  'GET'|'POST'|'PATCH'|'PUT'|'DELETE'  $method
     * @param  string  $path  absolute API path, e.g. `/app/installations/1`
     * @param  array<array-key, mixed>|null  $json
     * @param  array<string, string|int>  $query
     * @param  array<string, string>  $headers
     * @param  list<int>  $accept  non-2xx statuses the caller handles itself (e.g. 304, 404)
     *
     * @throws GitHubRequestFailed
     */
    public function send(
        string $baseUrl,
        string $method,
        string $path,
        #[SensitiveParameter] ?string $bearer = null,
        ?array $json = null,
        array $query = [],
        array $headers = [],
        array $accept = [],
    ): Response {
        $headers = [
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => self::API_VERSION,
            ...$headers,
        ];

        if ($bearer !== null) {
            $headers['Authorization'] = 'Bearer '.$bearer;
        }

        $response = $this->http->request($method, rtrim($baseUrl, '/').$path, $json, $headers, $query);

        $this->clock->observe($response->header('Date'));

        if (! $response->successful() && ! in_array($response->status(), $accept, true)) {
            throw GitHubRequestFailed::fromResponse($method, $path, $response);
        }

        return $response;
    }
}
