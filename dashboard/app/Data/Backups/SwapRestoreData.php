<?php

declare(strict_types=1);

namespace App\Data\Backups;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

/**
 * Swap a restored copy in for the original: the user types the original's
 * slug.
 */
final class SwapRestoreData extends Data
{
    public function __construct(
        #[Max(63)]
        public string $confirmation,
    ) {}
}
