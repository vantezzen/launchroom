<?php

declare(strict_types=1);

namespace App\Support\Canvas;

use App\Data\Canvas\NodeStatusData;
use App\Data\Canvas\ServiceProblemData;
use App\Enums\CanvasNodeState;

/**
 * Turns a failed or warning node status into a sentence and the place that
 * explains it (project cards, "Needs you", docs/08 §13). Healthy, deploying
 * and idle services are not problems.
 */
final class ServiceProblems
{
    public static function for(string $projectId, string $environmentId, string $serviceId, string $serviceName, NodeStatusData $status): ?ServiceProblemData
    {
        if (! in_array($status->state, [CanvasNodeState::Failed, CanvasNodeState::Warning], true)) {
            return null;
        }

        return new ServiceProblemData(
            serviceId: $serviceId,
            serviceName: $serviceName,
            status: $status,
            sentence: self::sentence($serviceName, $status->label),
            href: self::href($projectId, $environmentId, $serviceId, $status),
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

    public static function sentence(string $service, string $label): string
    {
        if (str_starts_with($label, 'Paused: ')) {
            return "{$service} is paused: ".substr($label, 8);
        }

        return match ($label) {
            'Failed' => "{$service} failed to deploy",
            'Last deploy failed' => "The last deploy of {$service} failed",
            'Crashed' => "{$service} crashed",
            'Unhealthy' => "{$service} is unhealthy",
            'Not running' => "{$service} is not running",
            'Server down' => "{$service} is down because its server is unreachable",
            'Degraded' => "{$service} runs with fewer replicas than it should",
            default => "{$service}: {$label}",
        };
    }

    /**
     * A failed deployment explains itself on its page; a crash or an
     * unhealthy container in the service's runtime logs; the rest on the
     * service's deployments.
     */
    private static function href(string $projectId, string $environmentId, string $serviceId, NodeStatusData $status): string
    {
        if (in_array($status->label, ['Failed', 'Last deploy failed'], true) && $status->deploymentId !== null) {
            return route('deployments.show', [$projectId, $environmentId, $status->deploymentId], absolute: false);
        }

        $tab = in_array($status->label, ['Crashed', 'Unhealthy'], true) ? 'logs' : 'deployments';

        return route('projects.services.show', [$projectId, $environmentId, $serviceId, $tab], absolute: false);
    }
}
