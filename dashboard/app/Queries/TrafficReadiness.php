<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Analytics\TrafficBlockerData;
use App\Data\Analytics\TrafficReadinessData;
use App\Enums\NodeStatusReason;
use App\Enums\TrafficReadinessReason;
use App\Models\Environment;

/**
 * Why Analytics shows no requests (docs/15 §5.4, D109): a service can only
 * be visited when it has an address and serves. The statuses are the ones
 * every other surface shows ({@see ServiceStatusIndex}, D108), so the empty
 * page names the same cause as the canvas. Callers authorize.
 */
final readonly class TrafficReadiness
{
    /** How many services that don't serve the page lists. */
    public const int LISTED = 4;

    /**
     * Reasons a service with an active deployment still answers requests:
     * a failed or running deploy keeps the previous version serving.
     */
    private const array SERVING = [
        NodeStatusReason::Live,
        NodeStatusReason::Running,
        NodeStatusReason::Starting,
        NodeStatusReason::Degraded,
        NodeStatusReason::Unhealthy,
        NodeStatusReason::LastDeployFailed,
        NodeStatusReason::Deploying,
        NodeStatusReason::Queued,
    ];

    public function __construct(private ServiceStatusIndex $statuses) {}

    /**
     * @param  array<array-key, Environment>  $environments  with `project`
     */
    public function in(array $environments): TrafficReadinessData
    {
        $byId = [];

        foreach ($environments as $environment) {
            $byId[$environment->id] = $environment;
        }

        $public = [];
        $blockers = [];
        $address = null;

        foreach ($this->statuses->in(array_keys($byId)) as $environmentId => $services) {
            $environment = $byId[$environmentId];

            foreach ($services as $entry) {
                if ($entry['domain'] === null) {
                    continue;
                }

                $public[] = $entry['instance']->service->public_id;
                $serving = $entry['instance']->active_deployment_id !== null && in_array($entry['status']->reason, self::SERVING, true);

                if ($serving) {
                    $address ??= $entry['domain'];

                    continue;
                }

                $blockers[] = new TrafficBlockerData(
                    serviceId: $entry['instance']->service->public_id,
                    name: $entry['name'],
                    logo: $entry['logo'],
                    projectId: $environment->project->public_id,
                    projectName: $environment->project->name,
                    environmentId: $environment->public_id,
                    environmentName: $environment->name,
                    status: $entry['status'],
                );
            }
        }

        usort($blockers, static fn (TrafficBlockerData $a, TrafficBlockerData $b): int => [$a->projectName, $a->name] <=> [$b->projectName, $b->name]);

        return new TrafficReadinessData(
            reason: match (true) {
                $public === [] => TrafficReadinessReason::NoAddress,
                $address === null => TrafficReadinessReason::NotServing,
                default => TrafficReadinessReason::Waiting,
            },
            address: $address,
            publicServices: array_values(array_unique($public)),
            notServing: array_slice($blockers, 0, self::LISTED),
            moreNotServing: max(0, count($blockers) - self::LISTED),
        );
    }
}
