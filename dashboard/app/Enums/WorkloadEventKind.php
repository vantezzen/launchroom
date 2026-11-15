<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A container lifecycle event (report.json WorkloadEvent.kind, D68).
 */
enum WorkloadEventKind: string
{
    case Created = 'created';
    case CreateFailed = 'create_failed';
    case Started = 'started';
    case Exited = 'exited';
    case Restarted = 'restarted';
    case HealthPassing = 'health_passing';
    case HealthFailing = 'health_failing';
    case Stopped = 'stopped';
    case Removed = 'removed';
}
