<?php

declare(strict_types=1);

namespace App\Data\Services;

use Spatie\LaravelData\Data;

/**
 * Stop, Start or Restart of a service in an environment (D68).
 * `processes`: also its workers and crons (the processes of an app that run
 * its image); the dialog offers it when the service has some.
 */
final class ServiceLifecycleData extends Data
{
    public function __construct(
        public bool $processes = true,
    ) {}
}
