<?php

declare(strict_types=1);

namespace App\Services\Transfers;

use App\Enums\Transfers\DataSubject;
use App\Enums\Transfers\ImportRunStatus;
use App\Events\NodeStateInvalidated;
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

    /**
     * Re-renders the servers of the held services (and of an imported
     * volume) once a run no longer holds them: they start again.
     *
     * @param  list<int>  $held
     */
    public static function release(DataImportRun $run, array $held): void
    {
        $servers = RestoreHolds::serverIds($held);

        if ($run->subject === DataSubject::Volume && $run->volume?->server_id !== null) {
            $servers[] = $run->volume->server_id;
        }

        if ($servers !== []) {
            event(new NodeStateInvalidated(array_values(array_unique($servers))));
        }
    }

    /**
     * Whether a run has held its services for longer than
     * `transfers.hold_timeout_seconds` without its task starting.
     */
    public static function overdue(DataImportRun $run): bool
    {
        return $run->status === ImportRunStatus::Holding
            && $run->holding_since !== null
            && $run->holding_since->lt(now()->subSeconds(config()->integer('launchroom.transfers.hold_timeout_seconds')));
    }

    /**
     * Why an overdue run failed, naming the held services that still run.
     *
     * @param  list<int>  $running  held instances that still have a workload up
     */
    public static function overdueReason(DataImportRun $run, array $running): string
    {
        $minutes = max(1, intdiv(config()->integer('launchroom.transfers.hold_timeout_seconds'), 60));
        $target = $run->serviceInstance->service->name;
        $within = $minutes === 1 ? 'within a minute' : "within {$minutes} minutes";

        /** @var list<string> $names */
        $names = $running === [] ? [] : ServiceInstance::query()->whereKey($running)->with('service')->get()
            ->map(static fn (ServiceInstance $instance): string => $instance->service->name)
            ->unique()->sort()->values()->all();

        if ($names === []) {
            return "The import couldn’t start {$within}, so nothing was imported and {$target} is unchanged. Try again.";
        }

        $last = array_pop($names);
        $list = $names === [] ? $last : implode(', ', $names).' and '.$last;
        [$it, $its] = $names === [] ? ['it', 'its server is'] : ['them', 'their servers are'];

        return "{$list} didn’t stop {$within}, so nothing was imported and {$target} is unchanged. Check that {$its} online and try again, or import without stopping {$it}.";
    }
}
