<?php

declare(strict_types=1);

namespace App\Support\Canvas;

use App\Data\Canvas\NodeStatusData;
use App\Data\Canvas\ServiceProblemData;
use App\Enums\NodeStatusReason;

/**
 * Turns a failed or warning node status into a sentence and the place that
 * explains it (project cards, "Needs you", docs/08 §13). Healthy, deploying
 * and idle services are not problems.
 */
final class ServiceProblems
{
    public static function for(string $projectId, string $environmentId, string $serviceId, string $serviceName, NodeStatusData $status): ?ServiceProblemData
    {
        if (! $status->state->isProblem()) {
            return null;
        }

        return new ServiceProblemData(
            serviceId: $serviceId,
            serviceName: $serviceName,
            status: $status,
            sentence: self::sentence($serviceName, $status),
            href: self::href($projectId, $environmentId, $serviceId, $status),
            link: self::link($status),
        );
    }

    /**
     * Most urgent first (stable within a state).
     *
     * @param  list<ServiceProblemData>  $problems
     * @return list<ServiceProblemData>
     */
    public static function sorted(array $problems): array
    {
        usort($problems, static fn (ServiceProblemData $a, ServiceProblemData $b): int => $b->status->state->rank() <=> $a->status->state->rank());

        return $problems;
    }

    public static function sentence(string $service, NodeStatusData $status): string
    {
        return match ($status->reason) {
            NodeStatusReason::Paused => "{$service} is paused: {$status->detail}",
            NodeStatusReason::DeployFailed => "{$service} failed to deploy",
            NodeStatusReason::LastDeployFailed => "The last deploy of {$service} failed",
            NodeStatusReason::Crashed => "{$service} crashed",
            NodeStatusReason::Unhealthy => "{$service} is unhealthy",
            NodeStatusReason::NotRunning => "{$service} is not running",
            NodeStatusReason::ServerDown => "{$service} is down because its server is offline",
            NodeStatusReason::ServerUnreachable => "{$service}'s server isn't reporting",
            NodeStatusReason::Degraded => "{$service} runs with fewer replicas than it should",
            default => "{$service}: {$status->label}",
        };
    }

    /**
     * A failed deployment explains itself on its page; a crash, an exit or
     * an unhealthy container in the service's runtime logs; a server problem
     * on the servers page; the rest on the service's deployments.
     */
    private static function href(string $projectId, string $environmentId, string $serviceId, NodeStatusData $status): string
    {
        if ($status->reason->isFailedDeploy() && $status->deploymentId !== null) {
            return route('deployments.show', [$projectId, $environmentId, $status->deploymentId], absolute: false);
        }

        if (self::isServerProblem($status)) {
            return route('servers.index', absolute: false);
        }

        $tab = self::showsLogs($status) ? 'logs' : 'deployments';

        return route('projects.services.show', [$projectId, $environmentId, $serviceId, $tab], absolute: false);
    }

    private static function link(NodeStatusData $status): string
    {
        return match (true) {
            $status->reason->isFailedDeploy() && $status->deploymentId !== null => 'See why',
            self::isServerProblem($status) => 'Open servers',
            self::showsLogs($status) => 'View logs',
            default => 'Open',
        };
    }

    /** What the container printed says why: a crash, a failing health check, an exit. */
    private static function showsLogs(NodeStatusData $status): bool
    {
        return in_array($status->reason, [NodeStatusReason::Crashed, NodeStatusReason::Unhealthy, NodeStatusReason::NotRunning], true);
    }

    private static function isServerProblem(NodeStatusData $status): bool
    {
        return in_array($status->reason, [NodeStatusReason::ServerDown, NodeStatusReason::ServerUnreachable, NodeStatusReason::Degraded], true);
    }
}
