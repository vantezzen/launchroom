<?php

declare(strict_types=1);

namespace App\Services\Registry;

use JsonException;

/**
 * A manifest or index exactly as the registry sent it (its bytes are what
 * the digest covers).
 */
final readonly class UpstreamManifest
{
    public const array INDEX_TYPES = [
        'application/vnd.oci.image.index.v1+json',
        'application/vnd.docker.distribution.manifest.list.v2+json',
    ];

    public function __construct(
        public string $bytes,
        public string $mediaType,
        public string $digest,
    ) {}

    public function isIndex(): bool
    {
        return in_array($this->mediaType, self::INDEX_TYPES, true);
    }

    /**
     * Digests this document points at: child manifests of an index, or the
     * config and layers of an image manifest (foreign layers with `urls`,
     * which no registry serves, are left out).
     *
     * @return list<string>
     *
     * @throws UpstreamRegistryError
     */
    public function references(): array
    {
        try {
            $document = json_decode($this->bytes, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new UpstreamRegistryError(UpstreamRegistryError::INVALID, 'The registry sent an image description launchroom can’t read.');
        }

        if (! is_array($document)) {
            throw new UpstreamRegistryError(UpstreamRegistryError::INVALID, 'The registry sent an image description launchroom can’t read.');
        }

        $descriptors = $this->isIndex()
            ? (is_array($document['manifests'] ?? null) ? $document['manifests'] : [])
            : [...(is_array($document['config'] ?? null) ? [$document['config']] : []), ...(is_array($document['layers'] ?? null) ? $document['layers'] : [])];

        $digests = [];

        foreach ($descriptors as $descriptor) {
            if (! is_array($descriptor) || isset($descriptor['urls']) || ! is_string($descriptor['digest'] ?? null)) {
                continue;
            }

            if (preg_match('/^sha256:[a-f0-9]{64}$/D', $descriptor['digest']) !== 1) {
                throw new UpstreamRegistryError(UpstreamRegistryError::INVALID, 'The image uses a digest launchroom doesn’t support (only sha256).');
            }

            $digests[] = $descriptor['digest'];
        }

        return array_values(array_unique($digests));
    }
}
