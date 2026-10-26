<?php

declare(strict_types=1);

namespace App\Data\Deployments;

use App\Enums\ProbeState;
use App\Enums\WorkloadPhase;
use App\Enums\WorkloadRole;
use App\Models\Placement;
use Spatie\LaravelData\Data;

/**
 * One replica of a deployment on the deployment page's stage (docs/08 §12):
 * `state` is the picture's vocabulary (starting, running, failed,
 * stopping), `label` the word beside it. No container ids or messages.
 */
final class DeploymentReplicaData extends Data
{
    public function __construct(
        /** 1-based, as the log explorer counts replicas. */
        public int $number,
        /** `starting`, `running`, `failed` or `stopping`. */
        public string $state,
        public string $label,
        public int $restarts,
    ) {}

    public static function of(Placement $placement): self
    {
        [$state, $label] = match (true) {
            $placement->role === WorkloadRole::Draining, $placement->phase === WorkloadPhase::Draining => ['stopping', 'Draining'],
            in_array($placement->phase, [WorkloadPhase::Pending, WorkloadPhase::Pulling], true) => ['starting', 'Pulling the image'],
            $placement->phase === WorkloadPhase::Starting,
            $placement->phase === WorkloadPhase::Running && $placement->health === ProbeState::Starting => ['starting', 'Starting'],
            $placement->phase === WorkloadPhase::Unhealthy, $placement->health === ProbeState::Failing => ['failed', 'Health check failing'],
            in_array($placement->phase, [WorkloadPhase::Failed, WorkloadPhase::Exited], true) => ['failed', 'Exited'],
            $placement->phase === WorkloadPhase::Removed => ['stopping', 'Removed'],
            default => ['running', $placement->health === ProbeState::Passing || $placement->phase === WorkloadPhase::Healthy ? 'Healthy' : 'Running'],
        };

        return new self($placement->slot + 1, $state, $label, $placement->restart_count);
    }
}
