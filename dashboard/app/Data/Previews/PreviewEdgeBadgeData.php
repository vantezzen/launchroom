<?php

declare(strict_types=1);

namespace App\Data\Previews;

use Spatie\LaravelData\Data;

/**
 * A badge on canvas edges into a preview database that isn't the preview's
 * own empty instance: "shared with production" (danger: PR migrations run
 * against it) or "cloned from staging / postgres".
 */
final class PreviewEdgeBadgeData extends Data
{
    public function __construct(
        /** Target service public id. */
        public string $serviceId,
        public string $serviceName,
        public string $label,
        /** `danger` (shared) or `neutral` (cloned). */
        public string $tone,
    ) {}
}
