<?php

declare(strict_types=1);

namespace App\Data\Analytics;

use Spatie\LaravelData\Data;

/**
 * One project of the team analytics overview (docs/15 §5.4): requests of
 * all its environments over the range and per bucket, the previous
 * period's requests, error shares, and its production environment for
 * the drill-down link.
 */
final class AnalyticsProjectData extends Data
{
    /**
     * @param  list<int>  $requestsSeries
     */
    public function __construct(
        public string $id,
        public string $name,
        /** Slot 0–7 of the data palette (colour follows the project). */
        public int $colorSlot,
        public ?string $environmentId,
        public int $requests,
        public ?int $previousRequests,
        /** 5xx share in percent. */
        public ?float $errorRatePct,
        /** 4xx share in percent. */
        public ?float $clientErrorPct,
        public array $requestsSeries,
    ) {}
}
