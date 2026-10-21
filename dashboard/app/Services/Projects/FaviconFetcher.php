<?php

declare(strict_types=1);

namespace App\Services\Projects;

use App\Contracts\DnsResolver;
use App\Services\Http\SafeHttpClient;
use App\Services\Http\UrlGuard;
use App\Support\Html\IconLinks;
use App\Support\Html\Urls;
use App\Support\Images\IconImage;
use App\Support\Images\IconSanitizer;
use App\Support\Images\InvalidImage;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Finds a deployed app's icon (docs/08 §13 "Project icons", docs/09 R32):
 * the home page of its primary domain, its `<link rel=icon|apple-touch-icon>`
 * candidates best first, then `/favicon.ico`. Every request goes through
 * {@see SafeHttpClient} (http/https only, public IPs only, resolved once and
 * pinned, no proxy), redirects are followed by hand (at most 3, each target
 * vetted again), the page is capped at 1 MiB and each image at 256 KiB, and
 * the whole lookup has a 20 s budget. What is found goes through
 * {@see IconSanitizer}; anything else is ignored.
 */
final class FaviconFetcher
{
    private const int MAX_REDIRECTS = 3;

    private const int MAX_ICON_ATTEMPTS = 4;

    private const int BUDGET_SECONDS = 20;

    private const int PAGE_BYTES = 1024 * 1024;

    private float $deadline = 0.0;

    public function __construct(
        private readonly Factory $http,
        private readonly DnsResolver $resolver,
    ) {}

    /**
     * @return array{icon: IconImage, url: string}|null
     */
    public function fetch(string $host): ?array
    {
        $this->deadline = microtime(true) + self::BUDGET_SECONDS;
        $page = null;

        foreach (['https', 'http'] as $scheme) {
            $page = $this->follow($this->client(self::PAGE_BYTES), "{$scheme}://{$host}/", ['Accept' => 'text/html,application/xhtml+xml']);

            if ($page !== null) {
                break;
            }
        }

        $origin = "https://{$host}";
        $candidates = [];

        if ($page !== null) {
            [$response, $finalUrl] = $page;
            $origin = $this->origin($finalUrl) ?? $origin;

            if ($response->successful() && str_contains(strtolower($response->header('Content-Type')), 'html')) {
                $candidates = IconLinks::candidates($response->body(), $finalUrl);
            }
        }

        $candidates[] = $origin.'/favicon.ico';
        $iconClient = $this->client(IconSanitizer::MAX_BYTES);

        foreach (array_slice(array_values(array_unique($candidates)), 0, self::MAX_ICON_ATTEMPTS + 1) as $url) {
            if (microtime(true) > $this->deadline) {
                break;
            }

            $bytes = str_starts_with($url, 'data:') ? $this->dataUri($url) : $this->download($iconClient, $url);

            if ($bytes === null) {
                continue;
            }

            try {
                return ['icon' => IconSanitizer::sanitize($bytes), 'url' => str_starts_with($url, 'data:') ? $origin.'/' : $url];
            } catch (InvalidImage $e) {
                Log::info('Project icon candidate refused.', ['url' => mb_substr($url, 0, 200), 'reason' => $e->getMessage()]);
            }
        }

        return null;
    }

    private function client(int $maxBytes): SafeHttpClient
    {
        return new SafeHttpClient(
            http: $this->http,
            guard: new UrlGuard($this->resolver, ['https', 'http']),
            connectTimeoutSeconds: 3,
            timeoutSeconds: 5,
            maxResponseBytes: $maxBytes,
        );
    }

    private function download(SafeHttpClient $client, string $url): ?string
    {
        $result = $this->follow($client, $url, ['Accept' => 'image/png,image/svg+xml,image/webp,image/x-icon,image/*;q=0.5']);

        if ($result === null || ! $result[0]->successful()) {
            return null;
        }

        return $result[0]->body();
    }

    /**
     * GET with redirects followed by hand: each hop is a new vetted request.
     *
     * @param  array<string, string>  $headers
     * @return array{Response, string}|null the response and the URL it came from
     */
    private function follow(SafeHttpClient $client, string $url, array $headers): ?array
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            if (microtime(true) > $this->deadline) {
                return null;
            }

            try {
                $response = $client->get($url, [], $headers);
            } catch (Throwable $e) {
                // Unsafe target, too large, timeout, TLS or connection error.
                Log::info('Project icon request failed.', ['url' => mb_substr($url, 0, 200), 'reason' => class_basename($e)]);

                return null;
            }

            if (! $response->redirect()) {
                return [$response, $url];
            }

            $next = Urls::resolve($url, $response->header('Location'));

            if ($next === null || str_starts_with($next, 'data:')) {
                return null;
            }

            $url = $next;
        }

        return null;
    }

    private function dataUri(string $uri): ?string
    {
        if (strlen($uri) > IconSanitizer::MAX_BYTES * 2 || preg_match('/^data:image\/[a-z0-9.+-]+((?:;[a-z0-9=-]+)*?)(;base64)?,(.*)$/is', $uri, $match) !== 1) {
            return null;
        }

        $bytes = $match[2] !== '' ? base64_decode($match[3], true) : rawurldecode($match[3]);

        return is_string($bytes) && $bytes !== '' ? $bytes : null;
    }

    private function origin(string $url): ?string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return strtolower($parts['scheme']).'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
