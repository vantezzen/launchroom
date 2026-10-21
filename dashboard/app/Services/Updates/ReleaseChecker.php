<?php

declare(strict_types=1);

namespace App\Services\Updates;

use App\Data\Updates\ReleaseCheckData;
use App\Data\Updates\ReleaseData;
use App\Models\InstanceSettings;
use App\Services\Http\Exceptions\ResponseTooLarge;
use App\Services\Http\Exceptions\UnsafeUrl;
use App\Services\Http\SafeHttpClient;
use App\Support\ImageReference;
use App\Support\Semver;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Looks for newer releases on GitHub (docs/11 §4): the Releases API of the
 * configured repository, newest version above this dashboard's on the
 * configured channel (stable skips prereleases), plus that release's
 * `images.env` asset for the digest-pinned dashboard and daemon images.
 *
 * Nothing here is trusted for integrity: the control-plane daemon downloads
 * the signed bundle itself and verifies it against the identity pinned at
 * install. Results are cached; `check(fresh: true)` refreshes.
 */
final readonly class ReleaseChecker
{
    private const string CACHE_KEY = 'launchroom:updates:release-check';

    private const int MAX_NOTES = 20000;

    public function __construct(
        private SafeHttpClient $http,
        private Cache $cache,
    ) {}

    public function cached(): ReleaseCheckData
    {
        $cached = $this->cache->get($this->cacheKey());

        if (is_array($cached)) {
            try {
                return ReleaseCheckData::from($cached);
            } catch (Throwable) {
                // Shape changed between releases: check again below.
            }
        }

        return new ReleaseCheckData($this->currentVersion(), $this->channel(), null, null, null);
    }

    public function check(bool $fresh = false): ReleaseCheckData
    {
        if (! $fresh && $this->cache->has($this->cacheKey())) {
            return $this->cached();
        }

        try {
            $result = new ReleaseCheckData($this->currentVersion(), $this->channel(), $this->newest(), now()->toIso8601String(), null);
        } catch (ConnectionException|UnsafeUrl|ResponseTooLarge|ReleaseCheckFailed $e) {
            $result = new ReleaseCheckData($this->currentVersion(), $this->channel(), null, now()->toIso8601String(), $e instanceof ReleaseCheckFailed ? $e->getMessage() : 'GitHub could not be reached.');
        }

        // Failures are cached briefly so the page doesn't hammer GitHub.
        $this->cache->put($this->cacheKey(), $result->toArray(), $result->error === null ? config()->integer('launchroom.updates.check_ttl_seconds') : 300);

        return $result;
    }

    /**
     * A specific release (for starting an update): null when it does not exist
     * or is not a valid version.
     *
     * @throws ReleaseCheckFailed
     */
    public function release(string $version): ?ReleaseData
    {
        $semver = Semver::parse($version) ?? throw new ReleaseCheckFailed('Not a release version.');

        try {
            $response = $this->api('/releases/tags/v'.$semver->normalized());
        } catch (ConnectionException|UnsafeUrl|ResponseTooLarge) {
            throw new ReleaseCheckFailed('GitHub could not be reached.');
        }

        if ($response->status() === 404) {
            return null;
        }

        $json = $this->json($response);

        return $this->toRelease($json, withImages: true);
    }

    public function currentVersion(): string
    {
        return config()->string('launchroom.version');
    }

    /**
     * The channel picked in Settings → Updates, else the configured default
     * (`LAUNCHROOM_UPDATES_CHANNEL`): stable skips prereleases, beta
     * includes them.
     */
    public function channel(): string
    {
        $channel = InstanceSettings::current()->update_channel ?? config('launchroom.updates.channel');

        return $channel === 'beta' ? 'beta' : 'stable';
    }

    /**
     * Forgets the cached result (the channel changed).
     */
    public function forget(): void
    {
        $this->cache->forget(self::CACHE_KEY.':stable');
        $this->cache->forget(self::CACHE_KEY.':beta');
    }

    private function cacheKey(): string
    {
        return self::CACHE_KEY.':'.$this->channel();
    }

    /**
     * @throws ReleaseCheckFailed
     * @throws ConnectionException
     * @throws UnsafeUrl
     * @throws ResponseTooLarge
     */
    private function newest(): ?ReleaseData
    {
        $releases = $this->json($this->api('/releases', ['per_page' => '30']));
        $current = Semver::parse($this->currentVersion());
        $best = null;
        $bestVersion = null;

        foreach ($releases as $release) {
            if (! is_array($release) || ($release['draft'] ?? false) === true) {
                continue;
            }

            $tag = is_string($release['tag_name'] ?? null) ? $release['tag_name'] : '';
            $version = Semver::parse($tag);

            if (! $version instanceof Semver || ! str_starts_with($tag, 'v')) {
                continue;
            }

            if (($version->isPrerelease() || ($release['prerelease'] ?? false) === true) && $this->channel() === 'stable') {
                continue;
            }

            if ($current instanceof Semver && $version->compareTo($current) <= 0) {
                continue;
            }

            if (! $bestVersion instanceof Semver || $version->compareTo($bestVersion) > 0) {
                $best = $release;
                $bestVersion = $version;
            }
        }

        return $best === null ? null : $this->toRelease($best, withImages: true);
    }

    /**
     * @param  array<mixed>  $release
     *
     * @throws ReleaseCheckFailed
     */
    private function toRelease(array $release, bool $withImages): ?ReleaseData
    {
        $tag = is_string($release['tag_name'] ?? null) ? $release['tag_name'] : '';
        $version = Semver::parse($tag);

        if (! $version instanceof Semver) {
            return null;
        }

        $images = $withImages ? $this->images($release) : [];
        $url = is_string($release['html_url'] ?? null) && str_starts_with($release['html_url'], 'https://github.com/') ? $release['html_url'] : '';

        return new ReleaseData(
            version: $version->normalized(),
            tag: $tag,
            name: mb_substr(is_string($release['name'] ?? null) && $release['name'] !== '' ? $release['name'] : $tag, 0, 200),
            notes: mb_substr(is_string($release['body'] ?? null) ? $release['body'] : '', 0, self::MAX_NOTES),
            url: $url,
            publishedAt: is_string($release['published_at'] ?? null) ? $release['published_at'] : null,
            prerelease: $version->isPrerelease() || ($release['prerelease'] ?? false) === true,
            dashboardImage: $images['LR_DASHBOARD_IMAGE'] ?? null,
            daemonImage: $images['LR_DAEMON_IMAGE'] ?? null,
        );
    }

    /**
     * Digest-pinned LR_*_IMAGE values of the release's images.env asset.
     *
     * @param  array<mixed>  $release
     * @return array<string, string>
     */
    private function images(array $release): array
    {
        $assets = is_array($release['assets'] ?? null) ? $release['assets'] : [];
        $assetUrl = null;

        foreach ($assets as $asset) {
            if (is_array($asset) && ($asset['name'] ?? null) === 'images.env' && is_string($asset['url'] ?? null)) {
                $assetUrl = $asset['url'];
            }
        }

        $prefix = $this->apiBase().'/repos/'.$this->repository().'/releases/assets/';

        if ($assetUrl === null || ! str_starts_with($assetUrl, $prefix)) {
            return [];
        }

        try {
            $response = $this->http->get($assetUrl, [], ['Accept' => 'application/octet-stream']);

            // The API answers with a redirect to GitHub's storage host; follow
            // exactly one hop, and only to those hosts.
            if ($response->redirect()) {
                $location = $response->header('Location');
                $host = parse_url($location, PHP_URL_HOST);

                if (! str_starts_with($location, 'https://') || ! is_string($host) || ! in_array($host, $this->assetHosts(), true)) {
                    return [];
                }

                $response = $this->http->get($location);
            }
        } catch (ConnectionException|UnsafeUrl|ResponseTooLarge) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $images = [];

        foreach (preg_split('/\R/', $response->body()) ?: [] as $line) {
            if (preg_match('/^(LR_[A-Z_]+_IMAGE)=(\S+)$/D', trim($line), $m) === 1 && ImageReference::isDigestPinned($m[2])) {
                $images[$m[1]] = $m[2];
            }
        }

        return $images;
    }

    /**
     * @param  array<string, string>  $query
     *
     * @throws ConnectionException
     * @throws UnsafeUrl
     * @throws ResponseTooLarge
     */
    private function api(string $path, array $query = []): Response
    {
        return $this->http->get($this->apiBase().'/repos/'.$this->repository().$path, $query, [
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
        ]);
    }

    /**
     * @return array<mixed>
     *
     * @throws ReleaseCheckFailed
     */
    private function json(Response $response): array
    {
        if (! $response->successful()) {
            throw new ReleaseCheckFailed("GitHub answered {$response->status()}.");
        }

        $json = $response->json();

        return is_array($json) ? $json : throw new ReleaseCheckFailed('GitHub sent an unexpected answer.');
    }

    private function apiBase(): string
    {
        return rtrim(config()->string('launchroom.updates.api_base'), '/');
    }

    private function repository(): string
    {
        $repository = config()->string('launchroom.updates.repository');

        if (preg_match('#^[A-Za-z0-9_.-]{1,100}/[A-Za-z0-9_.-]{1,100}$#D', $repository) !== 1) {
            throw new ReleaseCheckFailed('The update repository is misconfigured.');
        }

        return $repository;
    }

    /**
     * @return list<string>
     */
    private function assetHosts(): array
    {
        $hosts = config('launchroom.updates.asset_hosts');

        return is_array($hosts) ? array_values(array_filter($hosts, is_string(...))) : [];
    }
}
