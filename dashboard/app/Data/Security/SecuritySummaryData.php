<?php

declare(strict_types=1);

namespace App\Data\Security;

use Spatie\LaravelData\Data;

/**
 * The summary tiles (docs/22 §9.2): the protections at work in numbers.
 */
final class SecuritySummaryData extends Data
{
    /**
     * @param  list<int>  $blockedSeries  per bucket of the range
     */
    public function __construct(
        public int $actionNeeded,
        public int $actionNeededServices,
        public int $packages,
        public int $servicesChecked,
        public int $pendingInventories,
        public int $blocked,
        public ?int $previousBlocked,
        public array $blockedSeries,
        public int $protectedServices,
        public int $watchingServices,
        public int $routedServices,
    ) {}
}
