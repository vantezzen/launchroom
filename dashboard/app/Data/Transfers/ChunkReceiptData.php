<?php

declare(strict_types=1);

namespace App\Data\Transfers;

use Spatie\LaravelData\Data;

/**
 * The answer to an uploaded chunk.
 */
final class ChunkReceiptData extends Data
{
    public function __construct(
        public int $index,
        public int $received,
        public int $chunks,
    ) {}
}
