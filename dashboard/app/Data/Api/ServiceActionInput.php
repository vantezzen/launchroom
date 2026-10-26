<?php

declare(strict_types=1);

namespace App\Data\Api;

use Spatie\LaravelData\Data;

/**
 * Body of `POST …/services/{service}/stop|start|restart` (D68).
 * `processes`: an app's workers and crons stop, start or restart with it.
 */
final class ServiceActionInput extends Data
{
    public function __construct(
        public bool $processes = true,
    ) {}
}
