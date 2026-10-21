<?php

declare(strict_types=1);

namespace App\Services\Daemon;

/**
 * A syntactically valid request signature whose key hasn't been checked yet.
 */
final readonly class ParsedSignature
{
    public function __construct(
        public string $keyId,
        public string $nonce,
        public string $signature,
        public string $base,
    ) {}
}
