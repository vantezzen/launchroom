<?php

declare(strict_types=1);

namespace App\Data\Api;

use Spatie\LaravelData\Data;

/**
 * A known failure in plain words (FailureCatalogue): `key` names it
 * (stable, e.g. `port_mismatch`, `pnpm_outdated_lockfile`).
 */
final class ApiFailureExplanationData extends Data
{
    public function __construct(
        public string $key,
        public string $title,
        /** Why it happened. */
        public string $cause,
        /** How to fix it. */
        public string $fix,
        /** The start of the app's answer to the health check, masked, when it says what is wrong. */
        public ?string $answer,
    ) {}
}
