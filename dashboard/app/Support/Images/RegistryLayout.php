<?php

declare(strict_types=1);

namespace App\Support\Images;

use App\Support\Protocol\Grammar;

/**
 * Where things live in launchroom's registry (docs/04 §5 "Mirrors", docs/05 §6):
 *
 * - built images: `<registry>/apps/<service id lowercased>@sha256:…` ({@see AppImage});
 * - BuildKit caches: `<registry>/cache/<service id lowercased>` (launchroomd);
 * - upstream images through Zot's pull-through mirror:
 *   `<registry>/mirror/<upstream registry>/<repository>@sha256:…`, only for
 *   the registries in {@see MIRRORED}.
 *
 * Enrollment, Zot's config (sync destinations, retention), registry token
 * scopes and manifest lookups all derive from here, so they can't disagree.
 */
final class RegistryLayout
{
    public const string APPS = 'apps';

    public const string MIRROR = 'mirror';

    public const string CACHE = 'cache';

    /** Upstream registries Zot mirrors on demand, with their API URL. */
    public const array MIRRORED = [
        'docker.io' => 'https://registry-1.docker.io',
        'ghcr.io' => 'https://ghcr.io',
    ];

    /** What a manifest HEAD accepts: multi-arch indexes first, so digests are the multi-arch ones. */
    public const array MANIFEST_ACCEPT = [
        'application/vnd.oci.image.index.v1+json',
        'application/vnd.docker.distribution.manifest.list.v2+json',
        'application/vnd.oci.image.manifest.v1+json',
        'application/vnd.docker.distribution.manifest.v2+json',
    ];

    /** The registry host with a trailing slash (`127.0.0.1:5000/`), which both prefixes start with. */
    public static function registry(): string
    {
        return self::host().'/';
    }

    /**
     * The registry address Docker on every server pulls from: a protocol
     * constant (launchroomd's policy floor only runs images under it), sent
     * as the enrollment's registry address.
     */
    public static function host(): string
    {
        return Grammar::REGISTRY_ADDRESS;
    }

    /** Where built images are pushed, with a trailing slash (`127.0.0.1:5000/apps/`). */
    public static function appsPrefix(): string
    {
        return self::registry().self::APPS.'/';
    }

    public static function mirrorPrefix(): string
    {
        return self::registry().self::MIRROR;
    }

    /** The mirror repository an upstream image is pulled through (`mirror/docker.io/library/postgres`). */
    public static function mirrorRepository(ImageReference $reference): string
    {
        return strtolower(self::MIRROR.'/'.$reference->registry.'/'.$reference->repository);
    }

    /** "docker.io and ghcr.io", for messages. */
    public static function mirroredList(): string
    {
        return implode(' and ', array_keys(self::MIRRORED));
    }
}
