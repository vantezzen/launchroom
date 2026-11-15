<?php

declare(strict_types=1);

namespace App\Data\Moves;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

/**
 * The new machine finished the hand-over and reports the dashboard's URL.
 */
final class CompleteHandoverData extends Data
{
    public function __construct(
        #[Max(255)]
        public string $url,
    ) {}
}
