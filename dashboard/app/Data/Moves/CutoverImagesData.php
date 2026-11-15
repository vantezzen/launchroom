<?php

declare(strict_types=1);

namespace App\Data\Moves;

use Spatie\LaravelData\Data;

/**
 * Live images the new registry doesn't have (they rebuild from their
 * recorded commits).
 */
final class CutoverImagesData extends Data
{
    /**
     * @param  list<CutoverMissingImageData>  $missing
     */
    public function __construct(
        public int $checked,
        public int $unknown,
        public array $missing,
    ) {}
}
