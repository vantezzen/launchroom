<?php

declare(strict_types=1);

namespace App\Data\Servers;

use Spatie\LaravelData\Data;

/**
 * The latest measured transfer rate in one direction of a private-network
 * link (`from` sends, `to` receives; docs/05 §2.1).
 */
final class LinkSpeedData extends Data
{
    public function __construct(
        public string $from,
        public string $to,
        public float $mbps,
    ) {}
}
