<?php

declare(strict_types=1);

namespace App\Services\Transfers;

use App\Enums\Transfers\DataSubject;
use App\Enums\Transfers\ImportRunStatus;
use App\Models\ServiceInstance;
use App\Models\Transfers\DataImportRun;
use App\Services\Backups\RestoreHolds;

/**
 * Services held while an import runs (docs/14 §12.5), the same way restores
 * hold them ({@see RestoreHolds}): a volume import holds the service whose
 * volume is written; a database import with "stop the apps" holds the
 * services connected to the database in that environment.
 */
final class ImportHolds
{
    /**
     * @return array<int, true>
     */
    public static function instanceIds(): array
    {
        $held = [];
        $runs = DataImportRun::query()
            ->whereIn('status', [ImportRunStatus::Holding->value, ImportRunStatus::Importing->value])
            ->get(['id', 'subject', 'stop_dependents', 'service_instance_id']);

        foreach ($runs as $run) {
            foreach (self::heldBy($run) as $id) {
                $held[$id] = true;
            }
        }

        return $held;
    }

    /**
     * @return list<int>
     */
    public static function heldBy(DataImportRun $run): array
    {
        if ($run->subject === DataSubject::Volume) {
            return [$run->service_instance_id];
        }

        if (! $run->stop_dependents) {
            return [];
        }

        $database = ServiceInstance::query()->whereKey($run->service_instance_id)->first(['id', 'service_id', 'environment_id']);

        return $database instanceof ServiceInstance ? RestoreHolds::dependentIds($database) : [];
    }
}
