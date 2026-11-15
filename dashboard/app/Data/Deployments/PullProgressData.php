<?php

declare(strict_types=1);

namespace App\Data\Deployments;

use Spatie\LaravelData\Data;

/**
 * `placements.pull`: an image pull in flight while the workload is `pulling`
 * (Report.workloads[].pull, docs/04 §11 "Pulls").
 */
final class PullProgressData extends Data
{
    public function __construct(
        public string $stage,
        public int $downloaded,
        public int $total,
        public ?string $updatedAt = null,
    ) {}

    /** How far it got, 0..1; a mirror sync has no byte progress (-1). */
    public function share(): float
    {
        return match ($this->stage) {
            'mirroring' => -1.0,
            'extracting' => 1.0,
            default => $this->total > 0 ? min(1.0, $this->downloaded / $this->total) : 0.0,
        };
    }
}
