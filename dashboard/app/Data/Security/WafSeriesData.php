<?php

declare(strict_types=1);

namespace App\Data\Security;

use Spatie\LaravelData\Data;

/**
 * One series of the blocked-requests chart.
 */
final class WafSeriesData extends Data
{
    /**
     * @param  list<int>  $values
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $values,
    ) {}
}
