<?php

declare(strict_types=1);

namespace App\Services\Registry;

use App\Data\Registry\RegistryScope;
use App\Support\Protocol\Grammar;
use GuzzleHttp\Psr7\LazyOpenStream;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use LogicException;
use Throwable;

/**
 * Copies one private image, pinned by digest, from its registry into
 * launchroom's registry under the team's mirror path (D91, docs/05 §6):
 * `mirror/team_<id>/<host>/<repository>`. Runs in the dashboard, so the
 * team's password or token stays in this process: nothing reaches a server,
 * a task or Zot's config.
 *
 * An index is copied with every child manifest (the registry checks that an
 * index's children exist), then the index itself; an image manifest with its
 * config and layers. Blobs already in the target repository are skipped.
 * One blob is on disk at a time, in a temporary file removed afterwards.
 * Zot is reached through the dashboard's own view of it
 * (`launchroom.registry_lookup.url`) with short-lived push tokens issued
 * for exactly the target repository.
 */
final readonly class PrivateImageCopier
{
    /** The most one copy moves (all platforms together). */
    public const int MAX_TOTAL_BYTES = 20 * 1024 * 1024 * 1024;

    public function __construct(
        private UpstreamRegistry $upstream,
        private HttpFactory $http,
        private RegistryTokenIssuer $tokens,
    ) {}

    /**
     * @return array{digest: string, bytes: int}
     *
     * @throws UpstreamRegistryError
     */
    public function copy(UpstreamAuth $auth, string $repository, string $reference, string $target): array
    {
        if (preg_match(Grammar::TEAM_MIRROR_REPOSITORY, $target) !== 1) {
            throw new LogicException('Private images are only copied into a team mirror repository.');
        }

        $root = $this->upstream->manifest($auth, $repository, $reference);
        $bytes = 0;

        if ($root->isIndex()) {
            foreach ($root->references() as $child) {
                $manifest = $this->upstream->manifest($auth, $repository, $child);

                if ($manifest->isIndex()) {
                    throw new UpstreamRegistryError(UpstreamRegistryError::INVALID, "{$repository} nests image lists, which launchroom doesn’t copy.");
                }

                $bytes += $this->copyBlobs($auth, $repository, $manifest, $target, $bytes);
                $this->putManifest($target, $manifest);
            }
        } else {
            $bytes += $this->copyBlobs($auth, $repository, $root, $target, $bytes);
        }

        $this->putManifest($target, $root);

        return ['digest' => $root->digest, 'bytes' => $bytes];
    }

    /**
     * @throws UpstreamRegistryError
     */
    private function copyBlobs(UpstreamAuth $auth, string $repository, UpstreamManifest $manifest, string $target, int $copiedSoFar): int
    {
        $bytes = 0;

        foreach ($manifest->references() as $digest) {
            if ($this->hasBlob($target, $digest)) {
                continue;
            }

            // A random name in the temp directory (the blob stream creates it).
            $path = sys_get_temp_dir().'/lr-copy-'.bin2hex(random_bytes(16));

            try {
                $size = $this->upstream->downloadBlob($auth, $repository, $digest, $path, self::MAX_TOTAL_BYTES - $copiedSoFar - $bytes);
                $this->uploadBlob($target, $digest, $path, $size);
                $bytes += $size;
            } finally {
                @unlink($path);
            }
        }

        return $bytes;
    }

    private function hasBlob(string $target, string $digest): bool
    {
        try {
            return $this->zot($target)->head($this->url($target, '/blobs/'.$digest))->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @throws UpstreamRegistryError
     */
    private function uploadBlob(string $target, string $digest, string $path, int $size): void
    {
        $start = $this->attempt(fn (): Response => $this->zot($target)->post($this->url($target, '/blobs/uploads/')));
        $location = $start->header('Location');

        if ($start->status() !== 202 || $location === '') {
            throw $this->zotError($start->status());
        }

        $url = preg_match('#^https?://#i', $location) === 1 ? $location : rtrim(config()->string('launchroom.registry_lookup.url'), '/').'/'.ltrim($location, '/');
        $url .= (str_contains($url, '?') ? '&' : '?').'digest='.rawurlencode($digest);
        $stream = new LazyOpenStream($path, 'rb');

        try {
            $done = $this->attempt(fn (): Response => $this->zot($target)
                ->withHeaders(['Content-Length' => (string) $size])
                ->withBody($stream, 'application/octet-stream')
                ->put($url));
        } finally {
            $stream->close();
        }

        if ($done->status() !== 201) {
            throw $this->zotError($done->status());
        }
    }

    /**
     * @throws UpstreamRegistryError
     */
    private function putManifest(string $target, UpstreamManifest $manifest): void
    {
        $response = $this->attempt(fn (): Response => $this->zot($target)
            ->withBody($manifest->bytes, $manifest->mediaType)
            ->put($this->url($target, '/manifests/'.$manifest->digest)));

        if ($response->status() !== 201) {
            throw $this->zotError($response->status());
        }
    }

    private function zot(string $target): PendingRequest
    {
        $token = $this->tokens->issue('dashboard', [new RegistryScope($target, [RegistryScope::PULL, RegistryScope::PUSH])], RegistryTokenIssuer::MAX_TTL_SECONDS);

        return $this->http
            ->timeout(1800)
            ->withoutRedirecting()
            ->withToken($token->token);
    }

    private function url(string $target, string $path): string
    {
        return rtrim(config()->string('launchroom.registry_lookup.url'), '/').'/v2/'.$target.$path;
    }

    /**
     * @param  callable(): Response  $request
     *
     * @throws UpstreamRegistryError
     */
    private function attempt(callable $request): Response
    {
        try {
            return $request();
        } catch (Throwable) {
            throw new UpstreamRegistryError(UpstreamRegistryError::UNREACHABLE, 'launchroom couldn’t reach its own registry to store the image. Check that the registry is running on the main server.');
        }
    }

    private function zotError(int $status): UpstreamRegistryError
    {
        return new UpstreamRegistryError(UpstreamRegistryError::UNREACHABLE, "launchroom’s own registry refused the image (HTTP {$status}). Check its free space on the main server.");
    }
}
