<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why launchroomd stopped or removed a container (report.json WorkloadEvent.reason, D68).
 */
enum WorkloadEventReason: string
{
    case Drained = 'drained';
    case NotDesired = 'not_desired';
    case SpecChanged = 'spec_changed';
    case Duplicate = 'duplicate';
    case PortNotBound = 'port_not_bound';
}
