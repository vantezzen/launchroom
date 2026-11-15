<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Canvas\NodeStatus;

/**
 * Why a node (or an environment summary) shows its status line. Set by
 * {@see NodeStatus}; code compares reasons, never labels.
 */
enum NodeStatusReason: string
{
    case RemovalStaged = 'removal_staged';
    case Disabled = 'disabled';
    case Stopped = 'stopped';
    /** An import or restore holds it; `NodeStatusData::$detail` says which. */
    case Paused = 'paused';
    case Deploying = 'deploying';
    case Queued = 'queued';
    /** The latest deploy failed and nothing serves. */
    case DeployFailed = 'deploy_failed';
    /** The latest deploy failed and the previous version still serves. */
    case LastDeployFailed = 'last_deploy_failed';
    case NotDeployed = 'not_deployed';
    case ServerDown = 'server_down';
    case Degraded = 'degraded';
    case Crashed = 'crashed';
    case Unhealthy = 'unhealthy';
    case NotRunning = 'not_running';
    case Starting = 'starting';
    case Live = 'live';
    case Running = 'running';
    /** Environment summaries (NodeStatus::aggregate). */
    case NoServices = 'no_services';
    case ServicesFailed = 'services_failed';
    case ServicesNeedAttention = 'services_need_attention';
    case PartlyRunning = 'partly_running';

    public function label(): string
    {
        return match ($this) {
            self::RemovalStaged => 'Removal staged',
            self::Disabled => 'Disabled',
            self::Stopped => 'Stopped',
            self::Paused => 'Paused',
            self::Deploying => 'Deploying',
            self::Queued => 'Queued',
            self::DeployFailed => 'Failed',
            self::LastDeployFailed => 'Last deploy failed',
            self::NotDeployed => 'Not deployed',
            self::ServerDown => 'Server down',
            self::Degraded => 'Degraded',
            self::Crashed => 'Crashed',
            self::Unhealthy => 'Unhealthy',
            self::NotRunning => 'Not running',
            self::Starting => 'Starting',
            self::Live => 'Live',
            self::Running => 'Running',
            self::NoServices => 'No services',
            self::ServicesFailed => 'Services failed',
            self::ServicesNeedAttention => 'Services need attention',
            self::PartlyRunning => 'Partly running',
        };
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::RemovalStaged, self::Disabled, self::Stopped, self::Queued, self::NotDeployed, self::NoServices => StatusTone::Idle,
            self::Paused, self::LastDeployFailed, self::Degraded, self::Unhealthy, self::NotRunning, self::ServicesNeedAttention => StatusTone::Warning,
            self::Deploying, self::Starting => StatusTone::Progress,
            self::DeployFailed, self::ServerDown, self::Crashed, self::ServicesFailed => StatusTone::Failed,
            self::Live, self::Running, self::PartlyRunning => StatusTone::Active,
        };
    }

    public function isFailedDeploy(): bool
    {
        return $this === self::DeployFailed || $this === self::LastDeployFailed;
    }
}
