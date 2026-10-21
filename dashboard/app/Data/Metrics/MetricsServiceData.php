<?php

declare(strict_types=1);

namespace App\Data\Metrics;

use Spatie\LaravelData\Data;

/**
 * A service row of the project Metrics page (docs/15 §2.3): name, slug
 * (panel links) and its logo key (canvas glyph vocabulary).
 */
final class MetricsServiceData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public string $logo,
    ) {}
}
