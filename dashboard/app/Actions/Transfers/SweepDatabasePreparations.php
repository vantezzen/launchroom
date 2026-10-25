<?php

declare(strict_types=1);

namespace App\Actions\Transfers;

use App\Enums\DeploymentStatus;
use App\Enums\Transfers\ImportRunStatus;
use App\Models\Deployment;
use App\Models\Transfers\DataImportRun;
use App\Services\Transfers\DatabasePreparations;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Stops databases that ran alone for an import (docs/14 §12.9) once their
 * import finished, or when nobody kept them for an import (the sheet was
 * closed; {@see DatabasePreparations::LEASE_SECONDS}). From the transfers tick.
 */
final readonly class SweepDatabasePreparations
{
    public function __construct(
        private EndDatabasePreparation $end,
    ) {}

    public function handle(): int
    {
        $ended = 0;
        $preparations = Deployment::query()
            ->whereIn('status', DeploymentStatus::values([DeploymentStatus::Queued, ...DeploymentStatus::inFlight()]))
            ->whereNotNull('plan->'.DatabasePreparations::KEY)
            ->with('serviceInstance')
            ->get();

        foreach ($preparations as $deployment) {
            if (! $this->due($deployment)) {
                continue;
            }

            try {
                $ended += $this->end->handle($deployment->serviceInstance) ? 1 : 0;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $ended;
    }

    private function due(Deployment $deployment): bool
    {
        $instance = $deployment->serviceInstance;

        if (DatabasePreparations::importing($instance->id)) {
            return false;
        }

        $imported = DataImportRun::query()
            ->where('service_instance_id', $instance->id)
            ->whereIn('status', [ImportRunStatus::Succeeded->value, ImportRunStatus::Failed->value, ImportRunStatus::Cancelled->value])
            ->where('created_at', '>=', $deployment->created_at)
            ->exists();

        $lease = $deployment->plan[DatabasePreparations::KEY] ?? null;
        $until = is_array($lease) ? ($lease['until'] ?? null) : null;

        return $imported || ! is_string($until) || CarbonImmutable::parse($until)->isPast();
    }
}
