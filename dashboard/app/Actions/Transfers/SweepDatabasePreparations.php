<?php

declare(strict_types=1);

namespace App\Actions\Transfers;

use App\Enums\DeploymentPurpose;
use App\Enums\Transfers\ImportRunStatus;
use App\Models\Deployment;
use App\Models\Transfers\DataImportRun;
use App\Services\Transfers\DatabasePreparations;
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
            ->unfinished()
            ->withPurpose(DeploymentPurpose::PrepareImport)
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
            ->whereIn('status', ImportRunStatus::terminal())
            ->where('created_at', '>=', $deployment->created_at)
            ->exists();

        return $imported || $deployment->prepared_until === null || $deployment->prepared_until->isPast();
    }
}
