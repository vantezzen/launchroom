<?php

declare(strict_types=1);

namespace App\Data\Daemon;

use Carbon\CarbonImmutable;

/**
 * A freshly signed server client certificate (docs/03 §4.3). Public data:
 * the matching key never leaves the server.
 */
final readonly class IssuedClientCertificate
{
    public function __construct(
        public string $der,
        /** Lowercase hex SHA-256 of the DER. */
        public string $fingerprint,
        /** Lowercase hex. */
        public string $serial,
        public CarbonImmutable $notBefore,
        public CarbonImmutable $notAfter,
        public int $caId,
    ) {}
}
