<?php

declare(strict_types=1);

namespace App\Services\Holds;

use App\Data\Holds\Hold;
use App\Enums\HoldKind;
use App\Enums\WorkloadPhase;
use App\Events\HoldChanged;
use App\Events\NodeStateInvalidated;
use App\Models\Connection;
use App\Models\Placement;
use App\Models\Service;
use App\Models\ServiceInstance;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Held services (docs/14 §5.1, §12.5, §13.2): while a restore, an import or
 * a server move holds a service, its workloads are left out of their
 * servers' NodeStates (App\Services\NodeStates\NodeStateContent), so
 * launchroomd stops them; when the hold ends the servers re-render and they
 * start again. Each workflow is a {@see HoldSource}; this module owns the
 * union, the reasons, the start/end sequence ({@see changed}) and whether
 * the held services let go ({@see released}, {@see HoldBlocker}).
 */
final class Holds
{
    /** A workload its server's reports leave out for this long doesn't run there. */
    public const int SILENT_SECONDS = 120;

    /** @var list<class-string<HoldSource>> imports first: their reason wins for a service held twice */
    private const array SOURCES = [ImportHolds::class, RestoreHolds::class, MoveHolds::class];

    /**
     * @return array<int, true> held service instance ids
     */
    public static function instanceIds(): array
    {
        $held = [];

        foreach (self::all() as $hold) {
            foreach ($hold->instanceIds as $id) {
                $held[$id] = true;
            }
        }

        return $held;
    }

    /**
     * The hold each held instance's deployments wait for (the oldest import,
     * then the oldest restore). Moves are left out: they redeploy what they
     * hold themselves ({@see HoldKind::blocksDeployments}).
     *
     * @return array<int, Hold> held instance id => hold
     */
    public static function blockingDeployments(): array
    {
        $held = [];

        foreach (self::all() as $hold) {
            if (! $hold->kind->blocksDeployments()) {
                continue;
            }

            foreach ($hold->instanceIds as $id) {
                $held[$id] ??= $hold;
            }
        }

        return $held;
    }

    public static function blocking(int $instanceId): ?Hold
    {
        return self::blockingDeployments()[$instanceId] ?? null;
    }

    /**
     * A hold of $kind on $instanceIds started or ended: their servers (and
     * $alsoServers) re-render, so the workloads stop or start again, and
     * their deployments move on (HoldChanged).
     *
     * @param  list<int>  $instanceIds
     * @param  list<int>  $alsoServers
     */
    public static function changed(HoldKind $kind, array $instanceIds, array $alsoServers = []): void
    {
        event(new HoldChanged($kind, $instanceIds));
        NodeStateInvalidated::servers([...self::serverIds($instanceIds), ...$alsoServers]);
    }

    /**
     * Current instances in the database's environment of the services that
     * are connected to it (canvas edges into the database), and of the
     * workers and crons of those services: they run the same code with the
     * same data, whether or not they have an edge of their own.
     *
     * @return list<int>
     */
    public static function dependentsOf(ServiceInstance $database): array
    {
        $consumers = Connection::query()->where('to_service_id', $database->service_id)->select('from_service_id');
        $processes = Service::query()->whereIn('parent_service_id', $consumers)->select('id');

        /** @var list<int> $ids */
        $ids = ServiceInstance::query()
            ->current()
            ->where('environment_id', $database->environment_id)
            ->where(static fn (Builder $query) => $query->whereIn('service_id', $consumers)->orWhereIn('service_id', $processes))
            ->whereKeyNot($database->id)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)
            ->all();

        return $ids;
    }

    /**
     * Servers that run (or ran) workloads of the given instances.
     *
     * @param  list<int>  $instanceIds
     * @return list<int>
     */
    public static function serverIds(array $instanceIds): array
    {
        if ($instanceIds === []) {
            return [];
        }

        /** @var list<int> $ids */
        $ids = Placement::query()
            ->whereIn('service_instance_id', $instanceIds)
            ->distinct()
            ->pluck('server_id')
            ->map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)
            ->sort()
            ->values()
            ->all();

        return $ids;
    }

    /**
     * Whether no workload of the given instances is still up (all stopped,
     * removed, or never started): the held services have let go. A service
     * whose deployment failed before it ran has no workload up and counts as
     * stopped.
     *
     * @param  list<int>  $instanceIds
     */
    public static function released(array $instanceIds): bool
    {
        return self::running($instanceIds) === [];
    }

    /**
     * @param  list<int>  $instanceIds
     * @return list<int>
     */
    public static function running(array $instanceIds): array
    {
        /** @var list<int> $ids */
        $ids = self::runningPlacements($instanceIds)
            ->pluck('service_instance_id')
            ->unique()
            ->sort()
            ->values()
            ->map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)
            ->all();

        return $ids;
    }

    /**
     * The workloads of the given instances that launchroomd still reports
     * up. Judged on what it reports, not on a stale status: launchroomd
     * reports every workload it runs (and removed ones for a while) in every
     * report, so a workload its server keeps reporting without it for
     * `SILENT_SECONDS` runs no container there, whatever it was last seen as.
     * A workload that never got a container (pulling, pending, failed) isn't
     * up either.
     *
     * @param  list<int>  $instanceIds
     * @return Collection<int, Placement>
     */
    public static function runningPlacements(array $instanceIds): Collection
    {
        if ($instanceIds === []) {
            return new Collection;
        }

        return Placement::query()
            ->whereIn('service_instance_id', $instanceIds)
            ->whereIn('phase', [...WorkloadPhase::running(), WorkloadPhase::Draining])
            ->with('server')
            ->orderBy('server_id')
            ->orderBy('id')
            ->get()
            ->filter(static fn (Placement $placement): bool => self::stillReported($placement))
            ->values();
    }

    /**
     * @return list<Hold>
     */
    private static function all(): array
    {
        $holds = [];

        foreach (self::SOURCES as $source) {
            $holds = [...$holds, ...$source::active()];
        }

        return $holds;
    }

    /**
     * Whether the latest reports of its server still mention $placement
     * (each report that does updates it). A server that stopped reporting
     * altogether can't tell: its workloads count as up.
     */
    private static function stillReported(Placement $placement): bool
    {
        $reportedAt = $placement->server->last_report_at;

        return ! $reportedAt instanceof CarbonInterface
            || ! $placement->updated_at instanceof CarbonInterface
            || $placement->updated_at->gte($reportedAt->copy()->subSeconds(self::SILENT_SECONDS));
    }
}
