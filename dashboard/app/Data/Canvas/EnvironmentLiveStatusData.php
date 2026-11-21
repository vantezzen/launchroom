<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use Spatie\LaravelData\Data;

/**
 * Every service status of one environment at one moment (D108, docs/08
 * §23): what the client's live-status store holds and every status surface
 * reads. `version` is taken before reading, in microseconds, so a snapshot
 * with a higher version saw everything an older one did.
 */
final class EnvironmentLiveStatusData extends Data
{
    /**
     * @param  list<ServiceLiveStatusData>  $services
     */
    public function __construct(
        public string $environmentId,
        public int $version,
        public EnvironmentHealthData $health,
        public array $services,
    ) {}
}
