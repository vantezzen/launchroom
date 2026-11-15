<?php

declare(strict_types=1);

namespace App\Data\Moves;

use Spatie\LaravelData\Data;

/**
 * The final database dump of a hand-over: encrypted chunks and their
 * SHA-256 digests (App\Services\Moves\HandoverStore).
 */
final class HandoverDatabaseData extends Data
{
    /**
     * @param  list<string>  $digests
     */
    public function __construct(
        public int $chunks,
        public int $chunkBytes,
        public int $sizeBytes,
        public array $digests,
        public string $digest,
    ) {}
}
