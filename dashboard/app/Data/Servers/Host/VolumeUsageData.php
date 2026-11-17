<?php

declare(strict_types=1);

namespace App\Data\Servers\Host;

use Spatie\LaravelData\Data;

/** One managed volume's size on the server; `retired`: no NodeState declares it anymore. */
final class VolumeUsageData extends Data
{
    public function __construct(
        public string $id,
        public int $sizeMb,
        public bool $retired = false,
    ) {}
}
