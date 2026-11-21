<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use Spatie\LaravelData\Data;

/**
 * One service in an environment's live status (D108): its status, and the
 * problem sentence with its link when it needs the user.
 */
final class ServiceLiveStatusData extends Data
{
    public function __construct(
        public string $serviceId,
        public string $name,
        public string $logo,
        public NodeStatusData $status,
        public ?ServiceProblemData $problem = null,
    ) {}
}
