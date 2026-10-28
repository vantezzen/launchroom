<?php

declare(strict_types=1);

namespace App\Services\Git;

use App\Services\Git\Exceptions\GitConnectionUnusable;
use App\Services\Http\SafeHttpClient;
use App\Support\Git\RefAdvertisement;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Reads the refs of an HTTPS git remote through git's smart HTTP protocol
 * (docs/19 §4.3), never a provider API and never a clone:
 *
 * - protocol v2 `ls-refs` (`POST …/git-upload-pack`, `Git-Protocol: version=2`)
 *   with a ref prefix, so a poll of one branch moves a few hundred bytes
 *   even on repositories with thousands of pull request refs;
 * - else the v0 advertisement (`GET …/info/refs?service=git-upload-pack`).
 *
 * Every request goes through {@see SafeHttpClient} (docs/09 R5). A host that
 * answers 429 or 503 is left alone for its `Retry-After` (60 s to 15 min),
 * so polling backs off instead of hammering it.
 */
final readonly class PublicGitRemote
{
    private const string USER_AGENT = 'git/2.47.0 (launchroom)';

    public function __construct(
        private SafeHttpClient $http,
        private Cache $cache,
    ) {}

    /**
     * `ref => sha` (and `HEAD` when advertised), plus the branch HEAD points at.
     *
     * @param  string|null  $prefix  e.g. `refs/heads/` or `refs/heads/main`
     * @return array{refs: array<string, string>, head: string|null}
     *
     * @throws GitConnectionUnusable with a sentence for the user
     */
    public function refs(string $url, ?string $prefix = null, bool $privateNetwork = false): array
    {
        $url = rtrim($url, '/');
        $prefix ??= 'refs/heads/';
        $host = (string) parse_url($url, PHP_URL_HOST);
        $pause = 'git-remote-pause:'.$host;

        if ($this->cache->has($pause)) {
            throw new GitConnectionUnusable("{$host} asked launchroom to slow down. It checks again in a few minutes.");
        }

        $client = $privateNetwork ? $this->http->withPrivateNetwork() : $this->http;

        try {
            $v2 = $client->postRaw($url.'/git-upload-pack', $this->lsRefsRequest($prefix), 'application/x-git-upload-pack-request', [
                'Git-Protocol' => 'version=2',
                'Accept' => 'application/x-git-upload-pack-result',
                'User-Agent' => self::USER_AGENT,
            ]);

            $this->backOff($v2, $pause);

            if ($v2->successful() && str_contains($v2->header('Content-Type'), 'git-upload-pack-result')) {
                $parsed = RefAdvertisement::parseLsRefs($v2->body());

                if ($parsed['refs'] !== [] || $v2->body() === '0000') {
                    return $parsed;
                }
            }
        } catch (GitConnectionUnusable $e) {
            throw $e;
        } catch (Throwable) {
            // Not every server speaks v2 over HTTP: fall back to v0 below.
        }

        try {
            $response = $client->get($url.'/info/refs', ['service' => 'git-upload-pack'], ['User-Agent' => self::USER_AGENT]);
        } catch (Throwable $e) {
            throw new GitConnectionUnusable("{$host} is not reachable: ".mb_substr($e->getMessage(), 0, 160), 0, $e);
        }

        $this->backOff($response, $pause);

        if (in_array($response->status(), [401, 403, 404], true)) {
            throw new GitConnectionUnusable("{$host} doesn’t show this repository publicly. Check the URL, or connect your account for private repositories.");
        }

        if (! $response->successful()) {
            throw new GitConnectionUnusable("{$host} answered {$response->status()}. Is this a public git repository?");
        }

        if (! str_contains($response->header('Content-Type'), 'git-upload-pack-advertisement')) {
            throw new GitConnectionUnusable("{$host} answered, but not like a git server. Check the URL.");
        }

        $refs = RefAdvertisement::parse($response->body());

        $refs = array_filter($refs, static fn (string $ref): bool => str_starts_with($ref, $prefix) || $ref === 'HEAD', ARRAY_FILTER_USE_KEY);

        return ['refs' => $refs, 'head' => RefAdvertisement::headSymref($response->body())];
    }

    /**
     * @throws GitConnectionUnusable
     */
    private function backOff(Response $response, string $key): void
    {
        if ($response->status() !== 429 && $response->status() !== 503) {
            return;
        }

        $retry = (int) $response->header('Retry-After');
        $this->cache->put($key, true, max(60, min(900, $retry > 0 ? $retry : 300)));

        throw new GitConnectionUnusable('The git server asked launchroom to slow down. It checks again in a few minutes.');
    }

    private function lsRefsRequest(string $prefix): string
    {
        $lines = [$this->pkt("command=ls-refs\n"), '0001', $this->pkt("symrefs\n")];
        $lines[] = $this->pkt("ref-prefix HEAD\n");
        $lines[] = $this->pkt('ref-prefix '.$prefix."\n");

        return implode('', $lines).'0000';
    }

    private function pkt(string $payload): string
    {
        return sprintf('%04x', strlen($payload) + 4).$payload;
    }
}
