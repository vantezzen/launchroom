<?php

declare(strict_types=1);

namespace App\Data\Servers\Host;

use Spatie\LaravelData\Data;

/** Size and free space of one filesystem (Report.host.health), with inodes when reported. */
final class DiskUsageData extends Data
{
    public function __construct(
        public int $totalMb,
        public int $freeMb,
        public ?int $inodesTotal = null,
        public ?int $inodesFree = null,
    ) {}

    /** Free inodes in percent; null when not reported. */
    public function inodesFreePercent(): ?float
    {
        return $this->inodesTotal !== null && $this->inodesTotal > 0 && $this->inodesFree !== null
            ? $this->inodesFree * 100 / $this->inodesTotal
            : null;
    }
}
