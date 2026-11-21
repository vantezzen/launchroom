<?php

declare(strict_types=1);

namespace App\Data\Analytics;

use App\Enums\TrafficReadinessReason;
use Spatie\LaravelData\Data;

/**
 * What an empty Analytics page says (docs/15 §5.4, D109): why no requests
 * came, from the services of one environment (a project's tab) or of the
 * team's production environments (team Analytics).
 */
final class TrafficReadinessData extends Data
{
    /**
     * @param  list<string>  $publicServices  public ids of the services with an address
     * @param  list<TrafficBlockerData>  $notServing  services with an address that don't serve, at most a few
     */
    public function __construct(
        public TrafficReadinessReason $reason,
        /** The address of a service that serves, to visit ("waiting"). */
        public ?string $address,
        public array $publicServices,
        public array $notServing,
        /** More services don't serve than `notServing` lists. */
        public int $moreNotServing = 0,
    ) {}
}
