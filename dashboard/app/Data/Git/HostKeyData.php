<?php

declare(strict_types=1);

namespace App\Data\Git;

use Spatie\LaravelData\Data;

/**
 * A server host key as the user checks it: type and `SHA256:` fingerprint.
 */
final class HostKeyData extends Data
{
    public function __construct(
        public string $type,
        public string $fingerprint,
    ) {}
}
