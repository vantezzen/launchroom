<?php

declare(strict_types=1);

namespace App\Support\Traces;

/**
 * A `Launchroom-Trace` value split into its parts (docs/23 §2): the key id
 * says which edge key opens it; version and key id are the additional data.
 */
final readonly class TraceEnvelope
{
    public function __construct(
        public int $keyId,
        public string $additionalData,
        public string $nonce,
        public string $ciphertext,
        public string $tag,
    ) {}
}
