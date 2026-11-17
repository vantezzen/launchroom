<?php

declare(strict_types=1);

namespace App\Data\Deployments;

use Spatie\LaravelData\Data;

/**
 * `deployments.runtime_finding` (G12): a known problem seen in a live
 * deployment's runtime logs, explained by the failure catalogue. The line
 * is the app's own output, masked when it was stored.
 */
final class RuntimeFindingData extends Data
{
    public function __construct(
        /** The catalogue entry (`django_disallowed_host`, `csrf_origin`, …). */
        public string $key,
        public string $title,
        public string $cause,
        public string $fix,
        /** The log line it was seen in (≤ 300 characters). */
        public string $line,
        /** When it was seen (ISO 8601). */
        public string $at,
    ) {}
}
