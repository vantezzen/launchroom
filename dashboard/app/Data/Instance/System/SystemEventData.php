<?php

declare(strict_types=1);

namespace App\Data\Instance\System;

use Spatie\LaravelData\Data;

/**
 * Something the main server did on its own while watching launchroom: a
 * restart ("launchroom restarted Background jobs: its health check failed 3
 * times") or a fallback notification (sent, or failed with the reason).
 */
final class SystemEventData extends Data
{
    public function __construct(
        public string $at,
        public string $text,
        public bool $failed = false,
    ) {}
}
