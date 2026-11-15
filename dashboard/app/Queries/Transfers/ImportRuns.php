<?php

declare(strict_types=1);

namespace App\Queries\Transfers;

use App\Data\Transfers\ImportRunData;
use App\Enums\Transfers\ImportRunStatus;
use App\Models\ServiceInstance;
use App\Models\Transfers\DataImportRun;
use App\Services\Holds\HoldBlocker;
use App\Services\Holds\Holds;
use App\Services\Holds\ImportHolds;

/**
 * An import as the UI, the API and the CLI see it (docs/14 §12.7), with
 * what a holding import still waits for.
 */
final class ImportRuns
{
    public static function view(DataImportRun $run): ImportRunData
    {
        $running = $run->status === ImportRunStatus::Holding ? Holds::running(ImportHolds::heldBy($run)) : [];

        /** @var list<string> $names */
        $names = $running === [] ? [] : ServiceInstance::query()->whereKey($running)->with('service')->get()
            ->map(static fn (ServiceInstance $instance): string => $instance->service->name)
            ->unique()->sort()->values()->all();

        return ImportRunData::fromRun($run, $names, HoldBlocker::describe($running));
    }
}
