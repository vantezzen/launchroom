<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

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
        /** When the pull last moved (D68); a stuck pull keeps its time. */
        #[Regex(Report::TIME_PATTERN)]
        public string|null|Optional $updatedAt = new Optional,
    ) {}
}
