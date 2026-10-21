<?php

declare(strict_types=1);

namespace App\Data\Logs;

use Spatie\LaravelData\Data;

/**
 * One histogram bucket of the log explorer (epoch milliseconds): every line
 * of the search except its level filter, errors stacked.
 */
final class LogBucketData extends Data
{
    public function __construct(
        public int $start,
        public int $end,
        public int $total,
        public int $errors,
    ) {}
}
