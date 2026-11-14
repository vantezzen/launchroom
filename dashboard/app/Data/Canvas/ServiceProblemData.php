<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use Spatie\LaravelData\Data;

/**
 * A service of an environment that failed or needs attention (project cards
 * and "Needs you", docs/08 §13): what happened as a sentence and where to
 * see why.
 */
final class ServiceProblemData extends Data
{
    public function __construct(
        public string $serviceId,
        public string $serviceName,
        public NodeStatusData $status,
        /** "api failed to deploy", "web crashed". */
        public string $sentence,
        /** The failed deployment, else the service panel. */
        public string $href,
    ) {}
}
