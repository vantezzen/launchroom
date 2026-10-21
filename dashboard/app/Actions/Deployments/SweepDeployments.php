<?php

declare(strict_types=1);

namespace App\Actions\Deployments;

use App\Enums\DeploymentStatus;
use App\Enums\WorkloadRole;
use App\Events\NodeStateInvalidated;
use App\Jobs\AdvanceDeploymentJob;
use App\Models\Deployment;
use App\Models\Placement;
use Illuminate\Support\Facades\DB;

/**
 * Periodic housekeeping (every minute): drops drained workloads from the
 * desired state once they stopped (or long after their drain deadline), and
 * re-advances unfinished deployments so timeouts fire without new reports.
 */
final class SweepDeployments
{
    /** Drained workloads that never reported stopping are dropped after this. */
    private const int FORGET_AFTER_SECONDS = 600;

    public function handle(): void
    {
        $servers = DB::transaction(static function (): array {
            $drained = Placement::query()
                ->where('role', WorkloadRole::Draining->value)
                ->where('drain_until', '<', now()->subSeconds(15))
                ->lockForUpdate()
                ->get()
                ->filter(static fn (Placement $placement): bool => $placement->phase->isStopped()
                    || ($placement->drain_until !== null && $placement->drain_until->lessThan(now()->subSeconds(self::FORGET_AFTER_SECONDS))));

            // Never drop the stopped predecessor a stateful rollout is still waiting on:
            // its rollout step completes as soon as it sees it stopped.
            $waiting = Deployment::query()
                ->where('status', DeploymentStatus::RollingOut->value)
                ->pluck('service_instance_id')
                ->all();

            $drop = $drained->reject(static fn (Placement $placement): bool => in_array($placement->service_instance_id, $waiting, true));
            Placement::query()->whereKey($drop->modelKeys())->delete();

            /** @var list<int> $ids */
            $ids = $drop->pluck('server_id')->unique()->values()->all();

            return $ids;
        });

        if ($servers !== []) {
            event(new NodeStateInvalidated($servers));
        }

        $unfinished = Deployment::query()
            ->whereIn('status', DeploymentStatus::values([DeploymentStatus::Queued, ...DeploymentStatus::inFlight()]))
            ->pluck('id');

        foreach ($unfinished as $id) {
            if (is_int($id)) {
                dispatch(new AdvanceDeploymentJob($id));
            }
        }
    }
}
