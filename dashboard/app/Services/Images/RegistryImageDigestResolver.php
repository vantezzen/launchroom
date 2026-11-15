<?php

declare(strict_types=1);

namespace App\Services\Images;

use App\Contracts\ImageDigestResolver;
use App\Data\Registry\RegistryScope;
use App\Exceptions\ImageResolutionFailed;
use App\Services\Registry\RegistryTokenIssuer;
use App\Support\Images\ImageReference;
use App\Support\Images\RegistryLayout;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as HttpFactory;
use Throwable;

/**
 * Resolves `image:tag` to its digest through the local registry's
 * pull-through mirror (docs/04 §11): `HEAD /v2/<mirror path>/<registry>/<repository>/manifests/<tag>`
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
        $repository = RegistryLayout::mirrorRepository($reference);
        $key = 'launchroom:image-digest:'.hash('sha256', $repository.':'.$tag);
        $cached = $this->cache->get($key);

        if (is_string($cached) && ImageReference::isDigest($cached)) {
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
                ->accept(implode(', ', RegistryLayout::MANIFEST_ACCEPT))
                ->head($url);
        } catch (Throwable) {
            throw new ImageResolutionFailed("Could not reach the image registry to resolve {$name}. Try again, or pin the image with @sha256:… .");
        }

        if ($response->status() === 404) {
            throw new ImageResolutionFailed("{$name} was not found (only public images on ".RegistryLayout::mirroredList().' can be mirrored). Check the name and tag, or pin it with @sha256:… .');
        }

        $digest = $response->header('Docker-Content-Digest');

        if (! $response->successful() || ! ImageReference::isDigest($digest)) {
            throw new ImageResolutionFailed("The registry could not resolve {$name} (HTTP {$response->status()}). Try again, or pin it with @sha256:… .");
        }

        return $digest;
    }
}
