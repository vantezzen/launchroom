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
     * @return list<self>
     */
    public static function starting(): array
    {
        return [self::Pending, self::Pulling, self::Starting];
    }

    /**
     * A container runs, healthy or not (a console or log tail can reach it).
     *
     * @return list<self>
     */
    public static function running(): array
    {
        return [self::Starting, self::Running, self::Healthy, self::Unhealthy];
    }

    /**
     * @return list<self>
     */
    public static function serving(): array
    {
        return [self::Running, self::Healthy];
    }

    /**
     * The workload is gone or stopped: no container serves anymore.
     */
    public function isStopped(): bool
    {
        return in_array($this, [self::Exited, self::Removed, self::Failed], true);
    }
}
