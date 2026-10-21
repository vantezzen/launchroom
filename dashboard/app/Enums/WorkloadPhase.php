<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Observed workload phases reported by daemons (protocol/schema/v1/report.json).
 */
enum WorkloadPhase: string
{
    case Pending = 'pending';
    case Pulling = 'pulling';
    case Starting = 'starting';
    case Running = 'running';
    case Healthy = 'healthy';
    case Unhealthy = 'unhealthy';
    case Draining = 'draining';
    case Exited = 'exited';
    case Failed = 'failed';
    case Removed = 'removed';

    /**
     * The daemon created (or tried to create) the container.
     */
    public function isObserved(): bool
    {
        return ! in_array($this, [self::Pending, self::Pulling], true);
    }

    /**
     * The workload is gone or stopped: no container serves anymore.
     */
    public function isStopped(): bool
    {
        return in_array($this, [self::Exited, self::Removed, self::Failed], true);
    }
}
