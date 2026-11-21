<?php

declare(strict_types=1);

namespace App\Enums;

use App\Queries\TrafficReadiness;

/**
 * Why Analytics has no requests to show (docs/15 §5.4, D109), from the
 * services' real state ({@see TrafficReadiness}).
 */
enum TrafficReadinessReason: string
{
    /** No service has an address, so nothing can be visited. */
    case NoAddress = 'no_address';

    /** Services have addresses, but none of them serves. */
    case NotServing = 'not_serving';

    /** Something with an address serves: requests just haven't come yet. */
    case Waiting = 'waiting';
}
