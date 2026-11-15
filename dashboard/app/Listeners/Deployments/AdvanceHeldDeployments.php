<?php

declare(strict_types=1);

namespace App\Listeners\Deployments;

use App\Events\HoldChanged;
use App\Jobs\AdvanceDeploymentJob;
use App\Models\Deployment;

/**
 * Unfinished deployments of instances whose deployment-blocking hold changed
 * move on right away instead of at the next sweep: a started one is
 * cancelled, a queued one starts once nothing holds it.
 */
final class AdvanceHeldDeployments
{
    public function handle(HoldChanged $event): void
    {
        if (! $event->kind->blocksDeployments() || $event->instanceIds === []) {
            return;
        }

        foreach (Deployment::query()->whereIn('service_instance_id', $event->instanceIds)->unfinished()->pluck('id') as $id) {
            if (is_int($id)) {
                dispatch(new AdvanceDeploymentJob($id));
            }
        }
    }
}
