<?php

declare(strict_types=1);

namespace App\Data\Api;

use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

/**
 * Deployment log lines after a cursor (the last log chunk seen).
 */
final class LogCursorQuery extends Data
{
    public function __construct(
        #[Min(0)]
        public int $after = 0,
    ) {}
}
