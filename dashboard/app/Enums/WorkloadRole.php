<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * NodeState workload role: serving, or draining until `drainUntil`.
 */
enum WorkloadRole: string
{
    case Serving = 'serving';
    case Draining = 'draining';
}
