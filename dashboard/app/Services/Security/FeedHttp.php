<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Services\Http\Exceptions\ResponseTooLarge;
use App\Services\Http\Exceptions\UnsafeUrl;
use App\Services\Http\SafeHttpClient;
use App\Services\Http\UrlGuard;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * The advisory feeds' HTTP (docs/22 §4.1, docs/09 R5): every request goes
 * through SafeHttpClient with the source's fixed https host (no URL ever
 * comes from data or users), 10 s to connect, 30 s in total, no redirects
 * and a response cap per endpoint. Errors become FeedUnavailable (retry
 * later) or FeedRejected (drop).
 */
final readonly class FeedHttp
{
    public function __construct(
        private Factory $http,
        private UrlGuard $guard,
    ) {}

    /**
     * @param  array<string, string>  $headers
     * @param  array<array-key, mixed>|null  $json
     *
     * @throws FeedUnavailable
     * @throws FeedRejected
     */
    public function send(string $method, string $url, int $maxBytes, ?array $json = null, array $headers = []): Response
    {
        $client = new SafeHttpClient($this->http, $this->guard, 10, 30, $maxBytes);

        try {
            $response = $method === 'POST'
                ? $client->postJson($url, $json ?? [], ['Accept' => 'application/json', ...$headers])
                : $client->get($url, [], ['Accept' => 'application/json', ...$headers]);
        } catch (ResponseTooLarge) {
            throw new FeedRejected('The answer was larger than launchroom accepts.');
        } catch (UnsafeUrl $e) {
            throw new FeedUnavailable($e->getMessage());
        } catch (Throwable $e) {
            throw new FeedUnavailable('Could not connect: '.mb_substr($e->getMessage(), 0, 200));
        }

        $status = $response->status();

        if ($status === 429 || $status >= 500) {
            $retry = $response->header('Retry-After');

            throw new FeedUnavailable("The source answered HTTP {$status}.", is_numeric($retry) ? max(1, (int) $retry) : null);
        }

        if ($status >= 400 || $status >= 300 && $status !== 304) {
            throw new FeedRejected("The source answered HTTP {$status}.");
        }

        return $response;
    }
}
