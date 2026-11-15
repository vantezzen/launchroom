<?php

declare(strict_types=1);

namespace App\Data\Moves;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

/**
 * The new machine gave up on the hand-over, optionally saying why.
 */
final class AbortHandoverData extends Data
{
    public function __construct(
        #[Max(500)]
        public ?string $reason = null,
    ) {}
}
