<?php

declare(strict_types=1);

namespace App\Data\Moves;

use Spatie\LaravelData\Data;

final class CutoverMissingImageData extends Data
{
    public function __construct(
        public string $service,
        public string $deployment,
        /** Short commit sha. */
        public string $commit,
    ) {}
}
