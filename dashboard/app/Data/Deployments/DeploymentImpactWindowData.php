<?php

declare(strict_types=1);

namespace App\Data\Deployments;

use Spatie\LaravelData\Data;

/**
 * Request numbers of a service over one window around a deploy (docs/08
 * §12 "Since this deploy"): before it went live, or since.
 */
final class DeploymentImpactWindowData extends Data
{
    public function __construct(
        public int $requests,
        public float $requestsPerSecond,
        /** 5xx share in percent; null without requests. */
        public ?float $errorRatePct,
        public ?float $p95Ms,
    ) {}
}
