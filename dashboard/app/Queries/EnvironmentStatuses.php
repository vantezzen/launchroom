<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Canvas\EnvironmentHealthData;
use App\Data\Canvas\EnvironmentStatusData;
use App\Enums\NodeStatusReason;
use App\Models\Environment;

/**
 * Environments' rolled-up statuses for the project top bar (docs/08 §1):
 * the same NodeStatus aggregate the project switcher shows, from the
 * services' statuses (ServiceStatusIndex).
 */
final readonly class EnvironmentStatuses
{
    public function __construct(private ServiceStatusIndex $statuses) {}

    public function health(Environment $environment): EnvironmentHealthData
    {
        $entries = $this->statuses->in([$environment->id])[$environment->id] ?? [];
        $deploying = false;
        $failed = false;

        foreach ($entries as $entry) {
            $reason = $entry['status']->reason;
            $deploying = $deploying || $reason === NodeStatusReason::Deploying || $reason === NodeStatusReason::Queued;
            $failed = $failed || $reason->isFailedDeploy();
        }

        return new EnvironmentHealthData(ProjectStatuses::aggregate($entries), $deploying, $failed);
    }

    /**
     * @param  iterable<Environment>  $environments
     * @return list<EnvironmentStatusData>
     */
    public function of(iterable $environments): array
    {
        $byId = [];

        foreach ($environments as $environment) {
            $byId[$environment->id] = $environment;
        }

        $index = $this->statuses->in(array_keys($byId));
        $out = [];

        foreach ($byId as $id => $environment) {
            $out[] = new EnvironmentStatusData($environment->public_id, ProjectStatuses::aggregate($index[$id] ?? []));
        }

        return $out;
    }
}
