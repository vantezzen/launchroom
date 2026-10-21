<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * External probe run by the daemon (docs/04 §8). `none`: "running without
 * exiting" counts as healthy (workers, crons).
 */
enum HealthcheckType: string
{
    case Http = 'http';
    case Tcp = 'tcp';
    case None = 'none';
}
