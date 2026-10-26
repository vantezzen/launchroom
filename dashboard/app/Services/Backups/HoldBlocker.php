<?php

declare(strict_types=1);

namespace App\Services\Backups;

use App\Enums\ServerStatus;
use App\Enums\WorkloadPhase;
use App\Models\NodeStateVersion;
use App\Models\Placement;
use App\Models\Server;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Why held services still run (docs/14 §12.5, §12.6): a hold takes their
 * workloads out of their servers' desired state, and launchroomd stops them
 * once it applied that state. When they keep running, the blocker is
 * usually the server, not the services: it is offline, its reports stopped
 * arriving, launchroom couldn't publish its new desired state, or
 * launchroomd hasn't applied it yet because it is still busy with an
 * earlier change (such as downloading an image for a deployment). Null
 * when the server applied the change and still reports them up.
 */
final class HoldBlocker
{
    /**
     * @param  list<int>  $runningInstanceIds  held instances that still have a workload up
     */
    public static function describe(array $runningInstanceIds): ?string
    {
        if ($runningInstanceIds === []) {
            return null;
        }

        foreach (RestoreHolds::runningPlacements($runningInstanceIds)->groupBy('server_id') as $onServer) {
            $first = $onServer->first();

            if (! $first instanceof Placement) {
                continue;
            }

            $sentence = self::forServer($first->server, $onServer);

            if ($sentence !== null) {
                return $sentence;
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, Placement>  $placements  up placements of held instances on $server
     */
    private static function forServer(Server $server, Collection $placements): ?string
    {
        $them = $placements->pluck('service_instance_id')->unique()->count() > 1 ? 'them' : 'it';

        if ($server->status !== ServerStatus::Online) {
            return $server->status === ServerStatus::Unreachable
                ? "{$server->name} isn’t reachable, so it can’t stop {$them}."
                : "{$server->name} is offline, so it can’t stop {$them}.";
        }

        $reported = $server->last_report_at;

        if (! $reported instanceof CarbonInterface || $reported->lt(now()->subSeconds(RestoreHolds::SILENT_SECONDS))) {
            $since = $reported instanceof CarbonInterface ? ' for '.$reported->diffForHumans(now(), CarbonInterface::DIFF_ABSOLUTE) : ' yet';

            return "{$server->name} hasn’t reported its status{$since}, so launchroom can’t tell whether {$them} stopped.";
        }

        /** @var list<string> $workloads */
        $workloads = $placements->pluck('workload_id')->values()->all();

        if (! self::declares($server, $server->observed_version, $workloads)) {
            // launchroomd applied the state without them: it reports them up anyway.
            return null;
        }

        if ($server->render_error !== null && self::declares($server, $server->desired_version, $workloads)) {
            return "launchroom couldn’t update {$server->name}: {$server->render_error['message']}";
        }

        if ($server->observed_version >= $server->desired_version) {
            // The new desired state isn't published yet (a moment, normally).
            return null;
        }

        $pulling = Placement::query()
            ->where('server_id', $server->id)
            ->where('phase', WorkloadPhase::Pulling->value)
            ->with('serviceInstance.service')
            ->orderBy('id')
            ->get()
            ->map(static fn (Placement $placement): string => $placement->serviceInstance->service->name)
            ->unique()
            ->values()
            ->all();

        if ($pulling !== []) {
            $for = count($pulling) === 1 ? $pulling[0] : implode(', ', array_slice($pulling, 0, -1)).' and '.$pulling[count($pulling) - 1];

            return "{$server->name} is still downloading an image for {$for} and hasn’t stopped {$them} yet.";
        }

        return "{$server->name} hasn’t applied the change that stops {$them} yet. It may still be busy with an earlier change.";
    }

    /**
     * Whether the NodeState $version of $server still runs any of $workloads.
     *
     * @param  list<string>  $workloads
     */
    private static function declares(Server $server, int $version, array $workloads): bool
    {
        $document = NodeStateVersion::query()
            ->where('server_id', $server->id)
            ->where('version', $version)
            ->value('document');

        if (! is_string($document)) {
            // Unknown (none applied yet, or pruned): assume it still runs them.
            return true;
        }

        return array_any($workloads, static fn (string $workload): bool => str_contains($document, '"'.$workload.'"'));
    }
}
