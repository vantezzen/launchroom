<?php

declare(strict_types=1);

namespace App\Services\Holds;

use App\Data\Holds\Hold;
use App\Enums\HoldKind;
use App\Enums\Transfers\DataSubject;
use App\Enums\Transfers\ImportRunStatus;
use App\Models\ServiceInstance;
use App\Models\Transfers\DataImportRun;
use App\Services\Volumes\MountedVolumes;

/**
 * Services held while an import runs (docs/14 §12.5), the way restores hold
 * them: a volume import holds the service whose volume is written (and the
 * services that share it, D73); a database import with "stop the apps"
 * holds the database's dependents.
 */
final class ImportHolds implements HoldSource
{
    public static function active(): array
    {
        $runs = DataImportRun::query()
            ->whereIn('status', array_map(static fn (ImportRunStatus $status): string => $status->value, array_values(array_filter(ImportRunStatus::cases(), static fn (ImportRunStatus $status): bool => $status->holds()))))
            ->with('serviceInstance.service')
            ->orderBy('id')
            ->get();

        $holds = [];

        foreach ($runs as $run) {
            $name = $run->serviceInstance->service->name;
            $reason = $run->subject === DataSubject::Volume ? "files are being imported into {$name}" : "{$name} is being imported";
            $holds[] = new Hold(HoldKind::Import, self::heldBy($run), $reason, 'the import');
        }

        return $holds;
    }

    /**
     * @return list<int>
     */
    public static function heldBy(DataImportRun $run): array
    {
        if ($run->subject === DataSubject::Volume) {
            return [$run->service_instance_id, ...($run->volume_id === null ? [] : MountedVolumes::borrowersOf([$run->volume_id]))];
        }

        if (! $run->stop_dependents) {
            return [];
        }

        $database = ServiceInstance::query()->whereKey($run->service_instance_id)->first(['id', 'service_id', 'environment_id']);

        return $database instanceof ServiceInstance ? Holds::dependentsOf($database) : [];
    }

    /**
     * The servers to re-render besides those of the held services when the
     * run starts or stops holding: an imported volume's server.
     *
     * @return list<int>
     */
    public static function alsoServers(DataImportRun $run): array
    {
        return $run->subject === DataSubject::Volume && $run->volume?->server_id !== null ? [$run->volume->server_id] : [];
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
     * Why an overdue run failed, naming the held services that still run
     * and, when it's their server, what holds them up ({@see HoldBlocker}).
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
        $head = "{$list} didn’t stop {$within}, so nothing was imported and {$target} is unchanged.";
        $blocker = HoldBlocker::describe($running);

        if ($blocker !== null) {
            return "{$head} {$blocker} Try again once that’s resolved, or import without stopping {$it}.";
        }

        return "{$head} Check that {$its} online and try again, or import without stopping {$it}.";
    }
}
