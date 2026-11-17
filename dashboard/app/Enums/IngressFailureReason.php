<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why an edge's web server isn't serving (report.json IngressStatus.reason,
 * Go `state.IngressFailureReason`). Read leniently.
 */
enum IngressFailureReason: string
{
    /** Another program holds port 80 or 443 (`port`, `process`). */
    case PortInUse = 'port_in_use';
    case ConfigRefused = 'config_refused';
    case Image = 'image';
    case NotRunning = 'not_running';
    case Other = 'other';
}
