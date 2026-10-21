<?php

declare(strict_types=1);

namespace App\Services\Images;

use App\Contracts\ImageDigestResolver;
use App\Data\Registry\RegistryScope;
use App\Exceptions\ImageResolutionFailed;
use App\Services\Registry\RegistryTokenIssuer;
use App\Support\Images\ImageReference;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as HttpFactory;
use Throwable;

/**
 * Resolves `image:tag` to its digest through the local registry's
 * pull-through mirror (docs/04 §11): `HEAD /v2/mirror/<registry>/<repository>/manifests/<tag>`
 * with a dashboard-issued pull token for exactly that mirror repository,
 * accepting OCI indexes and Docker manifest lists (so the digest is the
 * multi-arch one). Asking the mirror also syncs the image into it, so the
 * daemons' later pull by digest is served locally. Results are cached briefly.
 *
 * The registry URL is the dashboard's own view of Zot
 * (`launchroom.registry_lookup.url`), never user input.
 */
final readonly class RegistryImageDigestResolver implements ImageDigestResolver
{
    private const array ACCEPT = [
        'application/vnd.oci.image.index.v1+json',
        'application/vnd.docker.distribution.manifest.list.v2+json',
        'application/vnd.oci.image.manifest.v1+json',
        'application/vnd.docker.distribution.manifest.v2+json',
    ];

    public function __construct(
        private HttpFactory $http,
        private RegistryTokenIssuer $tokens,
        private Cache $cache,
    ) {}

    public function resolve(ImageReference $reference): ImageReference
    {
        if ($reference->isPinned()) {
            return $reference;
        }

        $tag = $reference->tag ?? 'latest';
        $repository = strtolower('mirror/'.$reference->registry.'/'.$reference->repository);
        $key = 'launchroom:image-digest:'.hash('sha256', $repository.':'.$tag);
        $cached = $this->cache->get($key);

        if (is_string($cached) && preg_match('/^sha256:[a-f0-9]{64}$/D', $cached) === 1) {
            return $reference->withDigest($cached);
        }

        $digest = $this->lookup($reference, $repository, $tag);
        $this->cache->put($key, $digest, config()->integer('launchroom.registry_lookup.cache_seconds'));

        return $reference->withDigest($digest);
    }

    private function lookup(ImageReference $reference, string $repository, string $tag): string
    {
        $name = $reference->canonical();
        $url = rtrim(config()->string('launchroom.registry_lookup.url'), '/')."/v2/{$repository}/manifests/".rawurlencode($tag);

        try {
            $token = $this->tokens->issue('dashboard', [new RegistryScope($repository, [RegistryScope::PULL])], 120);
            $response = $this->http
                ->timeout(config()->integer('launchroom.registry_lookup.timeout_seconds'))
                ->withoutRedirecting()
                ->withToken($token->token)
                ->accept(implode(', ', self::ACCEPT))
                ->head($url);
        } catch (Throwable) {
            throw new ImageResolutionFailed("Could not reach the image registry to resolve {$name}. Try again, or pin the image with @sha256:… .");
        }

        if ($response->status() === 404) {
            throw new ImageResolutionFailed("{$name} was not found (only public images on docker.io and ghcr.io can be mirrored). Check the name and tag, or pin it with @sha256:… .");
        }

        $digest = $response->header('Docker-Content-Digest');

        if (! $response->successful() || preg_match('/^sha256:[a-f0-9]{64}$/D', $digest) !== 1) {
            throw new ImageResolutionFailed("The registry could not resolve {$name} (HTTP {$response->status()}). Try again, or pin it with @sha256:… .");
        }

        return $digest;
    }
}
