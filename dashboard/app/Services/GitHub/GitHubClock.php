<?php

declare(strict_types=1);

namespace App\Services\GitHub;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Clock-skew diagnostics from the `Date` header of responses we receive
 * anyway (no extra request per token, such as a `/zen` probe). The
 * last observed skew also shifts App JWT timestamps so a slightly wrong
 * clock doesn't break GitHub access.
 */
final readonly class GitHubClock
{
    private const string CACHE_KEY = 'launchroom:github:clock-skew';

    /** Never trust a header to move our timestamps further than this. */
    private const int MAX_CORRECTION_SECONDS = 300;

    public function __construct(
        private Cache $cache,
    ) {}

    /**
     * Records GitHub's time from a `Date` header (RFC 7231).
     */
    public function observe(string $dateHeader): void
    {
        if ($dateHeader === '') {
            return;
        }

        try {
            $remote = CarbonImmutable::createFromFormat('D, d M Y H:i:s \G\M\T', $dateHeader, 'UTC');
        } catch (Throwable) {
            return;
        }

        if (! $remote instanceof CarbonImmutable) {
            return;
        }

        $skew = $remote->getTimestamp() - CarbonImmutable::now()->getTimestamp();
        $this->cache->put(self::CACHE_KEY, $skew, 3600);

        if (abs($skew) > config()->integer('launchroom.github.max_clock_skew_seconds')
            && $this->cache->add(self::CACHE_KEY.':warned', true, 600)) {
            Log::warning('Clock skew against GitHub detected', ['skew_seconds' => $skew]);
        }
    }

    /**
     * GitHub time minus local time in seconds, if known.
     */
    public function skew(): ?int
    {
        $skew = $this->cache->get(self::CACHE_KEY);

        return is_int($skew) ? $skew : null;
    }

    public function isSkewed(): bool
    {
        return abs($this->skew() ?? 0) > config()->integer('launchroom.github.max_clock_skew_seconds');
    }

    /**
     * "Now" as GitHub sees it (bounded correction).
     */
    public function now(): CarbonImmutable
    {
        $correction = max(-self::MAX_CORRECTION_SECONDS, min(self::MAX_CORRECTION_SECONDS, $this->skew() ?? 0));

        return CarbonImmutable::now()->addSeconds($correction);
    }
}
