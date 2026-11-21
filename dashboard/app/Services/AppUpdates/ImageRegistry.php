<?php

declare(strict_types=1);

namespace App\Services\AppUpdates;

use App\Models\Registry\RegistryCredential;
use App\Services\Crypto\SealedColumns;
use App\Services\Http\SafeHttpClient;
use App\Services\Registry\UpstreamAuth;
use App\Services\Registry\UpstreamRegistry;
use App\Services\Registry\UpstreamRegistryError;
use App\Support\Images\ImageReference;
use App\Support\Images\RegistryLayout;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Throwable;

/**
 * What the update check asks a registry (docs/04 §14.1), cached so many
 * services of one image cost one lookup: tag lists (6 hours), the digest a
 * tag names (1 hour) and facts about one image (30 days per digest: when
 * it was published, where its source lives).
 *
 * A registry that limits requests (429) is put on hold for an hour: every
 * check for it waits instead of asking again. The team's sign-in details
 * (D91) are used when it has some for the host; they stay inside
 * UpstreamAuth and are never logged or cached.
 */
final readonly class ImageRegistry
{
    public const int TAGS_SECONDS = 6 * 3600;

    public const int DIGEST_SECONDS = 3600;

    public const int FACTS_SECONDS = 30 * 86400;

    public const int HOLD_SECONDS = 3600;

    private const string HUB_API = 'https://hub.docker.com/v2/namespaces/';

    public function __construct(
        private UpstreamRegistry $upstream,
        private SealedColumns $sealed,
        private SafeHttpClient $http,
        private Cache $cache,
    ) {}

    /**
     * How to sign in for $reference: the team's details for its host,
     * anonymous for the public registries the mirror serves, else null (a
     * host launchroom can't read).
     */
    public function auth(int $teamId, ImageReference $reference): ?UpstreamAuth
    {
        $credential = RegistryCredential::forImage($teamId, $reference);

        if ($credential instanceof RegistryCredential) {
            return new UpstreamAuth($credential->host, $credential->username, (string) $this->sealed->reveal($credential, 'secret'));
        }

        return isset(RegistryLayout::MIRRORED[$reference->registry]) ? UpstreamAuth::anonymous($reference->registry) : null;
    }

    /**
     * Until when the registry host is on hold after it limited requests.
     */
    public function heldUntil(string $host): ?CarbonImmutable
    {
        $until = $this->cache->get($this->holdKey($host));

        return is_int($until) && $until > time() ? CarbonImmutable::createFromTimestamp($until) : null;
    }

    /**
     * @return list<string>
     *
     * @throws UpstreamRegistryError
     */
    public function tags(UpstreamAuth $auth, ImageReference $reference): array
    {
        /** @var list<string> */
        return $this->remember($auth, 'tags:'.$this->scope($auth, $reference), self::TAGS_SECONDS, fn (): array => $this->upstream->tags($auth, $reference->repository));
    }

    /**
     * @throws UpstreamRegistryError
     */
    public function digest(UpstreamAuth $auth, ImageReference $reference, string $tag): ?string
    {
        $digest = $this->remember($auth, 'digest:'.$this->scope($auth, $reference).':'.$tag, self::DIGEST_SECONDS, fn (): string => $this->upstream->digest($auth, $reference->repository, $tag) ?? '');

        return $digest !== '' ? $digest : null;
    }

    /**
     * When the image behind $tag (at $digest) was published and its source
     * repository, best effort: unknown facts are null, never an error. Docker
     * Hub's tag API answers the date without a pull; other registries' image
     * configs carry `created` and the OCI source label. $withSource reads the
     * config on Docker Hub too (a pull there; only for the running image).
     */
    public function facts(UpstreamAuth $auth, ImageReference $reference, string $tag, string $digest, bool $withSource = false): ReleaseFacts
    {
        $key = 'facts:'.$reference->registry.'/'.$reference->repository.'@'.$digest.($withSource ? ':src' : '');
        $cached = $this->cache->get('app-updates:'.$key);

        if (is_array($cached)) {
            return ReleaseFacts::fromArray($cached);
        }

        $facts = new ReleaseFacts(null, null);

        try {
            if ($reference->registry === 'docker.io') {
                $facts = new ReleaseFacts($this->hubPushedAt($reference, $tag), null);
            }

            if ($reference->registry !== 'docker.io' || $withSource) {
                $config = $this->config($auth, $reference, $digest);
                $facts = new ReleaseFacts($facts->publishedAt ?? $config->publishedAt, $config->source);
            }
        } catch (Throwable) {
            // Facts are decoration: a check without them is still a check.
        }

        $this->cache->put('app-updates:'.$key, $facts->toArray(), self::FACTS_SECONDS);

        return $facts;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $read
     * @return T
     *
     * @throws UpstreamRegistryError
     */
    private function remember(UpstreamAuth $auth, string $key, int $seconds, callable $read): mixed
    {
        $key = 'app-updates:'.$key;

        if ($this->cache->has($key)) {
            return $this->cache->get($key);
        }

        if ($this->heldUntil($auth->host) instanceof CarbonImmutable) {
            throw new UpstreamRegistryError(UpstreamRegistryError::RATE_LIMITED, "{$auth->host} is limiting requests right now. launchroom checks again later.");
        }

        try {
            $value = $read();
        } catch (UpstreamRegistryError $e) {
            if ($e->kind === UpstreamRegistryError::RATE_LIMITED) {
                $this->cache->put($this->holdKey($auth->host), time() + self::HOLD_SECONDS, self::HOLD_SECONDS);
            }

            throw $e;
        }

        $this->cache->put($key, $value, $seconds);

        return $value;
    }

    private function scope(UpstreamAuth $auth, ImageReference $reference): string
    {
        // Another team's sign-in may see other tags (private repositories).
        return $reference->registry.'/'.$reference->repository.($auth->isAnonymous() ? '' : ':'.hash('sha256', $auth->host.'|'.$auth->username));
    }

    private function holdKey(string $host): string
    {
        return 'app-updates:hold:'.$host;
    }

    private function hubPushedAt(ImageReference $reference, string $tag): ?CarbonImmutable
    {
        [$namespace, $name] = str_contains($reference->repository, '/') ? explode('/', $reference->repository, 2) : ['library', $reference->repository];
        $response = $this->http->get(self::HUB_API.rawurlencode($namespace).'/repositories/'.rawurlencode($name).'/tags/'.rawurlencode($tag));

        if (! $response->successful()) {
            return null;
        }

        $at = $response->json('tag_last_pushed') ?? $response->json('last_updated');

        return is_string($at) ? $this->date($at) : null;
    }

    /**
     * The image config's `created` and source label, through the platform
     * manifest launchroom runs most (linux/amd64, else the first image).
     *
     * @throws UpstreamRegistryError
     */
    private function config(UpstreamAuth $auth, ImageReference $reference, string $digest): ReleaseFacts
    {
        $manifest = $this->upstream->manifest($auth, $reference->repository, $digest);
        $document = json_decode($manifest->bytes, true);

        if ($manifest->isIndex()) {
            $children = is_array($document) && is_array($document['manifests'] ?? null) ? $document['manifests'] : [];
            $child = $this->platformChild($children);

            if ($child === null) {
                return new ReleaseFacts(null, null);
            }

            $document = json_decode($this->upstream->manifest($auth, $reference->repository, $child)->bytes, true);
        }

        $configDigest = is_array($document) && is_array($document['config'] ?? null) ? ($document['config']['digest'] ?? null) : null;

        if (! is_string($configDigest) || ! ImageReference::isDigest($configDigest)) {
            return new ReleaseFacts(null, null);
        }

        $config = $this->upstream->jsonBlob($auth, $reference->repository, $configDigest);
        $labels = is_array($config['config'] ?? null) && is_array($config['config']['Labels'] ?? null) ? $config['config']['Labels'] : [];
        $created = $labels['org.opencontainers.image.created'] ?? ($config['created'] ?? null);
        $source = $labels['org.opencontainers.image.source'] ?? null;

        return new ReleaseFacts(is_string($created) ? $this->date($created) : null, is_string($source) ? $source : null);
    }

    /**
     * @param  array<array-key, mixed>  $children
     */
    private function platformChild(array $children): ?string
    {
        $first = null;

        foreach ($children as $child) {
            if (! is_array($child) || ! is_string($child['digest'] ?? null) || ! ImageReference::isDigest($child['digest'])) {
                continue;
            }

            $platform = is_array($child['platform'] ?? null) ? $child['platform'] : [];

            if (($platform['os'] ?? null) !== 'linux') {
                continue;
            }

            if (($platform['architecture'] ?? null) === 'amd64') {
                return $child['digest'];
            }

            $first ??= $child['digest'];
        }

        return $first;
    }

    private function date(string $value): ?CarbonImmutable
    {
        try {
            $date = CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }

        // Reproducible builds stamp 1970 (SOURCE_DATE_EPOCH = 0): unknown.
        return $date->year < 2000 ? null : $date;
    }
}
