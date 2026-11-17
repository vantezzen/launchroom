<?php

declare(strict_types=1);

namespace App\Data\Servers\Host;

use Spatie\LaravelData\Data;

/**
 * One problem of launchroomd's last reconcile pass: a code
 * (`docker_unreachable`, `guard_refused`, `disk_full`, `not_isolated` …),
 * the public id of what it was about, and launchroomd's message.
 */
final class ReconcileErrorData extends Data
{
    public function __construct(
        public string $code,
        public ?string $target = null,
        public ?string $message = null,
    ) {}
}
