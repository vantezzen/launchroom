<?php

declare(strict_types=1);

namespace App\Data\Moves;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

/**
 * Retire a moved server: the user types its name.
 */
final class RetireMovedServerData extends Data
{
    public function __construct(
        #[Max(64)]
        public string $confirmation,
    ) {}
}
