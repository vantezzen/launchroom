<?php

declare(strict_types=1);

namespace App\Data\Moves;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

/**
 * Start the hand-over: the user types “hand over”.
 */
final class StartHandoverData extends Data
{
    public function __construct(
        #[Max(32)]
        public string $confirmation,
    ) {}
}
