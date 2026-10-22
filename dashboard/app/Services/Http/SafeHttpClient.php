<?php

declare(strict_types=1);

namespace App\Services\Http;

use App\Services\Http\Exceptions\ResponseTooLarge;
use App\Services\Http\Exceptions\UnsafeUrl;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * The only way to fetch a user-influenced URL (git remotes, webhooks, S3,
 * registries, health URLs) — docs/09-security.md R5:
 *
 * - scheme allow-list (https only by default), no credentials in URLs;
 * - resolve once, reject private/reserved/CGNAT/link-local/ULA/multicast;
 * - pin the vetted IP with CURLOPT_RESOLVE and disable proxies, so curl
 *   can't resolve the name a second time (DNS rebinding);
 * - no redirects (a redirect could point anywhere);
 * - connect/total timeouts and a response size cap.
 */
final readonly class SafeHttpClient
{
    public function __construct(
        private Factory $http,
        private UrlGuard $guard,
        private int $connectTimeoutSeconds = 5,
        private int $timeoutSeconds = 15,
        private int $maxResponseBytes = 10 * 1024 * 1024,
    ) {}

    /**
     * The same client with the instance-owner private-network override
     * (docs/09 R5, {@see UrlGuard::withPrivateNetwork()}), for destinations an
     * owner explicitly marked as being on a private network.
     */
    public function withPrivateNetwork(): self
    {
        return new self($this->http, $this->guard->withPrivateNetwork(), $this->connectTimeoutSeconds, $this->timeoutSeconds, $this->maxResponseBytes);
    }

    /**
     * Checks a URL without fetching it (and returns where it would connect).
     *
     * @throws UnsafeUrl
     */
    public function check(string $url): ResolvedTarget
    {
        return $this->guard->check($url);
    }

    /**
     * @param  array<string, string>  $query
     * @param  array<string, string>  $headers
     *
     * @throws UnsafeUrl
     * @throws ResponseTooLarge
     */
    public function get(string $url, array $query = [], array $headers = []): Response
    {
        // An empty `query` option would make Guzzle replace the URL's own
        // query string with nothing (signed storage URLs carry theirs).
        return $this->send('GET', $url, $headers, $query === [] ? [] : ['query' => $query]);
    }

    /**
     * @param  array<array-key, mixed>  $json
     * @param  array<string, string>  $headers
     *
     * @throws UnsafeUrl
     * @throws ResponseTooLarge
     */
    public function postJson(string $url, array $json, array $headers = []): Response
    {
        return $this->send('POST', $url, $headers, ['json' => $json]);
    }

    /**
     * POST `application/x-www-form-urlencoded` (OAuth token endpoints).
     *
     * @param  array<string, string>  $form
     * @param  array<string, string>  $headers
     *
     * @throws UnsafeUrl
     * @throws ResponseTooLarge
     */
    public function postForm(string $url, #[\SensitiveParameter] array $form, array $headers = []): Response
    {
        return $this->send('POST', $url, $headers, ['form_params' => $form]);
    }

    /**
     * POST exact bytes (signed webhooks: the signature covers precisely
     * what is sent, docs/15 §3.5).
     *
     * @param  array<string, string>  $headers
     *
     * @throws UnsafeUrl
     * @throws ResponseTooLarge
     */
    public function postRaw(string $url, string $body, string $contentType, array $headers = []): Response
    {
        return $this->send('POST', $url, [...$headers, 'Content-Type' => $contentType], ['body' => $body]);
    }

    /**
     * Any method with exact body bytes (S3 uploads signed over their
     * payload hash, docs/09 R34).
     *
     * @param  'GET'|'PUT'|'POST'  $method
     * @param  array<string, string>  $headers
     *
     * @throws UnsafeUrl
     * @throws ResponseTooLarge
     */
    public function sendRaw(string $method, string $url, string $body, array $headers = []): Response
    {
        return $this->send($method, $url, $headers, $body === '' ? [] : ['body' => $body]);
    }

    /**
     * @param  array<string, string>  $headers
     *
     * @throws UnsafeUrl
     * @throws ResponseTooLarge
     */
    public function head(string $url, array $headers = []): Response
    {
        return $this->send('HEAD', $url, $headers, []);
    }

    /**
     * Any method, with an optional JSON body (API clients that need PATCH,
     * DELETE or body-less POSTs). Transport options can't be overridden.
     *
     * @param  'GET'|'POST'|'PATCH'|'PUT'|'DELETE'  $method
     * @param  array<array-key, mixed>|null  $json
     * @param  array<string, string>  $headers
     * @param  array<string, string|int>  $query
     *
     * @throws UnsafeUrl
     * @throws ResponseTooLarge
     */
    public function request(string $method, string $url, ?array $json = null, array $headers = [], array $query = []): Response
    {
        $options = $query === [] ? [] : ['query' => $query];

        if ($json !== null) {
            // An empty array is sent as `{}`: JSON APIs expect objects.
            $options['json'] = $json === [] ? (object) [] : $json;
        }

        return $this->send($method, $url, $headers, $options);
    }

    /**
     * Guzzle/curl options that enforce the policy for a vetted target.
     *
     * @return array<string, mixed>
     */
    public function transportOptions(ResolvedTarget $target): array
    {
        $maxBytes = $this->maxResponseBytes;

        return [
            'allow_redirects' => false,
            'connect_timeout' => $this->connectTimeoutSeconds,
            'timeout' => $this->timeoutSeconds,
            // Proxies resolve the host themselves, bypassing the pin.
            'proxy' => '',
            'curl' => [
                CURLOPT_RESOLVE => [$target->curlResolveEntry()],
                CURLOPT_PROTOCOLS => $target->scheme === 'https' ? CURLPROTO_HTTPS : CURLPROTO_HTTP,
                CURLOPT_REDIR_PROTOCOLS => 0,
                CURLOPT_FOLLOWLOCATION => false,
            ],
            'on_headers' => static function (ResponseInterface $response) use ($maxBytes): void {
                $length = $response->getHeaderLine('Content-Length');

                if ($length !== '' && (int) $length > $maxBytes) {
                    throw ResponseTooLarge::exceeds($maxBytes);
                }
            },
            'progress' => static function (int|float $downloadTotal, int|float $downloaded) use ($maxBytes): void {
                if ($downloaded > $maxBytes) {
                    throw ResponseTooLarge::exceeds($maxBytes);
                }
            },
        ];
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $options
     */
    private function send(string $method, string $url, array $headers, array $options): Response
    {
        $target = $this->guard->check($url);

        try {
            $response = $this->pendingRequest($target)
                ->withHeaders($headers)
                ->send($method, $target->url, $options);
        } catch (Throwable $e) {
            // Guzzle wraps exceptions thrown from on_headers/progress callbacks.
            for ($previous = $e; $previous instanceof Throwable; $previous = $previous->getPrevious()) {
                if ($previous instanceof ResponseTooLarge) {
                    throw $previous;
                }
            }

            throw $e;
        }

        // Defence in depth: streaming callbacks above abort early, this
        // catches anything that slipped through (e.g. missing Content-Length
        // with a transport that doesn't report progress).
        if (strlen($response->body()) > $this->maxResponseBytes) {
            throw ResponseTooLarge::exceeds($this->maxResponseBytes);
        }

        return $response;
    }

    private function pendingRequest(ResolvedTarget $target): PendingRequest
    {
        return $this->http
            ->withOptions($this->transportOptions($target))
            ->withUserAgent('launchroom');
    }
}
