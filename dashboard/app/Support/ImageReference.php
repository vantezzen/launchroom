<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Digest-pinned image references, same grammar as the protocol's ImageRef
 * (daemon/internal/state/scalars.go): `registry/path@sha256:<64 hex>`, no tag.
 */
final class ImageReference
{
    public const string DIGEST_PINNED = '/^(?:localhost|[a-z0-9-]+(?:\.[a-z0-9-]+)+|[a-z0-9-]+(?:\.[a-z0-9-]+)*:[0-9]{1,5})(?:\/[a-z0-9]+(?:(?:\.|_|__|-+)[a-z0-9]+)*)+@sha256:[a-f0-9]{64}$/D';

    public static function isDigestPinned(string $reference): bool
    {
        return strlen($reference) <= 512 && preg_match(self::DIGEST_PINNED, $reference) === 1;
    }
}
