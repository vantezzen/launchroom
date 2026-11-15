<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The project Deployments page's status filter: a status, or every
 * deployment that is in progress.
 */
enum DeploymentFilter: string
{
    case InProgress = 'in_progress';
    case Queued = 'queued';
    case Ready = 'ready';
    case Active = 'active';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Superseded = 'superseded';

    /**
     * @return list<DeploymentStatus>
     */
    public function statuses(): array
    {
        return $this === self::InProgress ? DeploymentStatus::inFlight() : [DeploymentStatus::from($this->value)];
    }

    public function label(): string
    {
        return $this === self::InProgress ? 'Deploying' : DeploymentStatus::from($this->value)->label();
    }
}
