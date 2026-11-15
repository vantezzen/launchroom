<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use Spatie\LaravelData\Data;

/**
 * The temporary access whose password a task gets (`seal.accessPassword`, docs/03 §12.1).
 */
final class AccessPasswordSeal extends Data
{
    public function __construct(
        public string $access,
    ) {}
}
