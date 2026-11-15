<?php

declare(strict_types=1);

namespace App\Data\Moves;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

/**
 * An instance owner confirms the comparison code the new machine shows.
 */
final class ApproveMoveData extends Data
{
    public function __construct(
        #[Max(16)]
        public string $code,
    ) {}
}
