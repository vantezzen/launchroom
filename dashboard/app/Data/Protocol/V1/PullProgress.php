<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

/**
 * Report `workloads[].pull` (report.json PullProgress): an in-flight image
 * pull, compressed layer bytes downloaded of the total (0 while unknown).
 */
final class PullProgress extends Data
{
    public function __construct(
        #[In(['mirroring', 'downloading', 'extracting'])]
        public string $stage,
        #[Min(0)]
        public int $downloadedBytes,
        #[Min(0)]
        public int $totalBytes,
    ) {}
}
