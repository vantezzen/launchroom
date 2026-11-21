<?php

declare(strict_types=1);

namespace App\Data\AppUpdates;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * Update a service to one of its available releases (docs/04 §14.2). The
 * tag must be a candidate of the service's latest check; a major needs the
 * release notes read.
 */
final class StartAppUpdateData extends Data
{
    public function __construct(
        #[Max(128), Regex('/^[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}$/')]
        public string $tag,
        public bool $releaseNotesRead = false,
    ) {}
}
