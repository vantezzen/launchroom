<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

/**
 * Counts of the user-facing outputs of a Next.js build (protocol NextRouteCounts).
 */
final class NextRouteCounts extends Data
{
    public function __construct(
        #[Min(0), Max(1_000_000)]
        public int $appPages = 0,
        #[Min(0), Max(1_000_000)]
        public int $appRoutes = 0,
        #[Min(0), Max(1_000_000)]
        public int $pages = 0,
        #[Min(0), Max(1_000_000)]
        public int $pagesApi = 0,
        #[Min(0), Max(1_000_000)]
        public int $staticFiles = 0,
        /** Fully static prerenders. */
        #[Min(0), Max(1_000_000)]
        public int $prerendered = 0,
        /** Prerenders with time-based revalidation. */
        #[Min(0), Max(1_000_000)]
        public int $isr = 0,
        /** Routes built for the edge runtime (they run in the Node.js server here). */
        #[Min(0), Max(1_000_000)]
        public int $edge = 0,
    ) {}
}
