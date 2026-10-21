<?php

declare(strict_types=1);

namespace App\Data\Servers;

use App\Enums\HealthCheckState;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * Server health derived from the daemon's latest host facts (docs/03 §1).
 */
final class ServerHealthData extends Data
{
    /**
     * @param  list<HealthCheckData>  $checks
     */
    public function __construct(
        public HealthCheckState $state,
        public ?CarbonInterface $collectedAt,
        public bool $stale,
        public array $checks,
    ) {}
}
