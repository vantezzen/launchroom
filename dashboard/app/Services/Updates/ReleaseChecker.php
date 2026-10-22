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
 * install. Results are cached per channel and per running version, so a
 * version change (an update, or the installer run by hand) never shows the
 * previous check; `cached()` also drops any release that is not newer than
 * the running version. `check(fresh: true)` refreshes.
 */
final readonly class ReleaseChecker
{
    private const string CACHE_KEY = 'launchroom:updates:release-check:v2';

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
                return $this->forRunningVersion(ReleaseCheckData::from($cached));
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

        // Failures (and a release whose image list could not be read) are
        // cached briefly so the page doesn't hammer GitHub but recovers soon.
        $brief = $result->error !== null || $result->available?->cannotInstallReason !== null;
        $this->cache->put($this->cacheKey(), $result->toArray(), $brief ? 300 : config()->integer('launchroom.updates.check_ttl_seconds'));

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
     * (`LAUNCHROOM_UPDATES_CHANNEL`, which `install.sh --unstable` sets to
     * beta), else inferred from the running version: a pre-release (such as
     * 0.1.0-rc.3) follows beta, anything else (releases, -dev builds) stable. Stable skips
     * prereleases, beta includes them.
     */
    public function channel(): string
    {
        $channel = InstanceSettings::current()->update_channel ?? config('launchroom.updates.channel');

        if ($channel === 'beta' || $channel === 'stable') {
            return $channel;
        }

        // Development builds (0.0.0-dev) are not releases: stable.
        $current = $this->currentVersion();

        return Semver::parse($current)?->isPrerelease() === true && ! str_ends_with($current, '-dev') ? 'beta' : 'stable';
    }

    /**
     * Forgets the cached result (the channel changed).
     */
    public function forget(): void
    {
        $this->cache->forget($this->cacheKey('stable'));
        $this->cache->forget($this->cacheKey('beta'));
    }

    /**
     * The installer command that installs $version by hand (docs/11 §2.1),
     * run on the main server in the folder that contains `launchroom/`.
     */
    public static function installerCommand(string $version): string
    {
        $semver = Semver::parse($version);

        return 'curl -fsSL https://launchroom.vantezzen.io/install | sh'.($semver instanceof Semver ? ' -s -- --version '.$semver->normalized() : '');
    }

    /**
     * Per channel and per running version: after the version changes the
     * old result is never read again (it expires on its own).
     */
    private function cacheKey(?string $channel = null): string
    {
        return self::CACHE_KEY.':'.($channel ?? $this->channel()).':'.hash('xxh128', $this->currentVersion());
    }

    /**
     * The cached result as of the running version: a release that is not
     * newer than what runs now is never offered.
     */
    private function forRunningVersion(ReleaseCheckData $check): ReleaseCheckData
    {
        $current = $this->currentVersion();
        $available = $check->available;

        if ($available instanceof ReleaseData && Semver::compare($available->version, $current) !== null && ! Semver::newer($available->version, $current)) {
            $available = null;
        }

        return new ReleaseCheckData($current, $this->channel(), $available, $check->checkedAt, $check->error);
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

        [$images, $problem] = $withImages ? $this->images($release) : [[], null];

        if ($problem === null && ! isset($images['LR_DAEMON_IMAGE'])) {
            $problem = 'Its image list (images.env) doesn’t pin the server image (LR_DAEMON_IMAGE) by digest.';
        }

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
            cannotInstallReason: $problem,
            installerCommand: self::installerCommand($version->normalized()),
        );
    }

    /**
     * Digest-pinned LR_*_IMAGE values of the release's images.env asset, and
     * why it could not be read (plain words, shown on Settings → Updates).
     *
     * @param  array<mixed>  $release
     * @return array{array<string, string>, ?string}
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

        if ($assetUrl === null) {
            return [[], 'The release has no image list (images.env), so the dashboard doesn’t know which images to install.'];
        }

        $prefix = $this->apiBase().'/repos/'.$this->repository().'/releases/assets/';

        if (! str_starts_with($assetUrl, $prefix)) {
            return [[], 'The release’s image list (images.env) isn’t stored with the release on GitHub.'];
        }

        try {
            $response = $this->http->get($assetUrl, [], ['Accept' => 'application/octet-stream']);

            // The API answers with a redirect to GitHub's storage host; follow
            // exactly one hop, and only to those hosts. The signed query
            // string of that URL must be sent as it is.
            if ($response->redirect()) {
                $location = $response->header('Location');
                $host = parse_url($location, PHP_URL_HOST);

                if (! str_starts_with($location, 'https://') || ! is_string($host) || ! in_array($host, $this->assetHosts(), true)) {
                    return [[], 'GitHub sent the release’s image list (images.env) from an unexpected address, so the dashboard didn’t use it.'];
                }

                $response = $this->http->get($location);
            }
        } catch (ConnectionException|UnsafeUrl|ResponseTooLarge) {
            return [[], 'GitHub couldn’t be reached to download the release’s image list (images.env).'];
        }

        if (! $response->successful()) {
            return [[], "GitHub answered {$response->status()} when the dashboard downloaded the release’s image list (images.env)."];
        }

        $images = [];

        foreach (preg_split('/\R/', $response->body()) ?: [] as $line) {
            if (preg_match('/^(LR_[A-Z_]+_IMAGE)=(\S+)$/D', trim($line), $m) === 1 && ImageReference::isDigestPinned($m[2])) {
                $images[$m[1]] = $m[2];
            }
        }

        return [$images, null];
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
