<?php

declare(strict_types=1);

namespace App\Data\Servers\Host;

use Spatie\LaravelData\Data;

/** Docker networks on the host, and how many launchroom manages. */
final class NetworkCountData extends Data
{
    public function __construct(
        public int $total,
        public int $managed,
    ) {}
}
