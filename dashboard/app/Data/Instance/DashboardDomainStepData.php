<?php

declare(strict_types=1);

namespace App\Data\Instance;

use Spatie\LaravelData\Data;

/**
 * One step of setting up a dashboard domain: `dns`, `certificate` or
 * `https`; state `done`, `waiting` (in progress), `failed` (needs a fix) or
 * `blocked` (an earlier step isn't done).
 */
final class DashboardDomainStepData extends Data
{
    public function __construct(
        public string $key,
        public string $state,
        public ?string $detail,
    ) {}
}
