<?php

declare(strict_types=1);

namespace App\Actions\Transfers;

use App\Enums\DeploymentStatus;
use App\Enums\WorkloadRole;
use App\Events\NodeStateInvalidated;
use App\Jobs\AdvanceDeploymentJob;
use App\Models\Deployment;
use App\Models\Placement;
use App\Models\ServiceInstance;
use App\Services\Transfers\DatabasePreparations;
use Illuminate\Support\Facades\DB;

/**
 * Stops a database that ran alone for an import (docs/14 §12.9): its
 * workload drains at once, so launchroomd stops it cleanly (the typed stop
 * of databases) and the sweep removes it once stopped; the volume and its
 * data stay for the first deploy, which waits for the stop like any
 * stateful rollout. The preparation ends as cancelled. While an import of
 * the database waits or runs nothing happens. The next queued deployment
 * of the instance (the first deploy) starts.
 */
final readonly class EndDatabasePreparation
{
    /**
     * @return bool whether a preparation was ended
     */
    public function handle(ServiceInstance $instance): bool
    {
        if (! DatabasePreparations::current($instance) instanceof Deployment) {
            return false;
        }

        $servers = [];

        $next = DB::transaction(function () use ($instance, &$servers): int|false|null {
            ServiceInstance::query()->whereKey($instance->id)->lockForUpdate()->first();
            $deployment = DatabasePreparations::current($instance);

            if (! $deployment instanceof Deployment || DatabasePreparations::importing($instance->id)) {
                return false;
            }

            $deployment = Deployment::query()->whereKey($deployment->id)->lockForUpdate()->firstOrFail();
            $placements = Placement::query()->where('deployment_id', $deployment->id)->get();

            Placement::query()->whereKey($placements->modelKeys())->update([
                'role' => WorkloadRole::Draining->value,
                'drain_until' => now(),
            ]);
            /** @var list<int> $ids */
            $ids = $placements->pluck('server_id')->unique()->values()->all();
            $servers = $ids;

            // Building can't happen (database images are known); every other unfinished status may be cancelled here.
            if ($deployment->status->canTransitionTo(DeploymentStatus::Cancelled)) {
                $deployment->transitionTo(DeploymentStatus::Cancelled);
            } else {
                $deployment->transitionTo(DeploymentStatus::Failed, message: 'Stopped before it finished starting.');
            }

            $next = Deployment::query()
                ->where('service_instance_id', $instance->id)
                ->where('status', DeploymentStatus::Queued->value)
                ->orderBy('id')
                ->value('id');

            return is_int($next) ? $next : null;
        });

        if ($next === false) {
            return false;
        }

        if ($servers !== []) {
            event(new NodeStateInvalidated($servers));
        }

        if ($next !== null) {
            dispatch(new AdvanceDeploymentJob($next))->afterCommit();
        }

        return true;
    }
}
