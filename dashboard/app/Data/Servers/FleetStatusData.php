<?php

declare(strict_types=1);

namespace App\Data\Servers;

use App\Enums\StatusTone;
use Spatie\LaravelData\Data;

/**
 * The team's servers in one line under the team name (design-system
 * §13.2): "2 servers online", "1 server offline", "Disk almost full on
 * hetzner-1". `state` is the icon, `label` the words.
 */
final class FleetStatusData extends Data
{
    public function __construct(
        public int $total,
        public int $online,
        public StatusTone $state,
        public string $label,
    ) {}
}
