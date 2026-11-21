<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Canvas\EnvironmentLiveStatusData;
use App\Data\Canvas\ServiceLiveStatusData;
use App\Models\Environment;
use App\Support\Canvas\ServiceProblems;

/**
 * An environment's live status (D108, docs/08 §23): the shell prop
 * `liveStatus` and `projects.live-status`, from the one status loader.
 */
final readonly class EnvironmentLiveStatus
{
    public function __construct(private ServiceStatusIndex $statuses) {}

    public function for(Environment $environment): EnvironmentLiveStatusData
    {
        // Before reading: whatever committed before this moment is in it.
        $version = (int) floor(microtime(true) * 1_000_000);
        $environment->loadMissing('project');
        $entries = $this->statuses->in([$environment->id])[$environment->id] ?? [];
        $services = [];

        foreach ($entries as $entry) {
            $service = $entry['instance']->service;
            $services[] = new ServiceLiveStatusData(
                serviceId: $service->public_id,
                name: $entry['name'],
                logo: $entry['logo'],
                status: $entry['status'],
                problem: ServiceProblems::for($environment->project->public_id, $environment->public_id, $service->public_id, $entry['name'], $entry['status']),
            );
        }

        return new EnvironmentLiveStatusData($environment->public_id, $version, EnvironmentStatuses::healthOf($entries), $services);
    }
}
