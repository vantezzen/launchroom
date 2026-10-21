<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use Spatie\LaravelData\Data;

final class CanvasThumbnailEdgeData extends Data
{
    public function __construct(
        public string $source,
        public string $target,
        /** "connection" (service → service) or "process" (app → worker/cron). */
        public string $type,
    ) {}
}
