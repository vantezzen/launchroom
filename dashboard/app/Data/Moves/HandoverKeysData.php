<?php

declare(strict_types=1);

namespace App\Data\Moves;

use Spatie\LaravelData\Data;

/**
 * The sealed key bundle of a hand-over: its size and SHA-256.
 */
final class HandoverKeysData extends Data
{
    public function __construct(
        public int $bytes,
        public string $sha256,
    ) {}
}
