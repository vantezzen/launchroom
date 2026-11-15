<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Enums\ServerStatus;
use App\Models\Server;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Re-places the stateless replicas of servers unseen for
 * `launchroom.scheduling.node_dead_after_seconds` (docs/02 §6). Runs with the
 * liveness check (every 15 s).
 *
 * Status thresholds stay as they are (unreachable after 75 s, offline after
 * 300 s, docs/03 §9): "dead for scheduling" is its own threshold, measured
 * from `last_seen_at`, so replicas move after 90–105 s while the server is
 * still "unreachable".
 *
 * Guard against a control plane that was itself down: replicas only move once
 * the liveness check has run continuously for at least the threshold (a gap
 * of more than two minutes between runs restarts the watch), so after a
 * dashboard outage every server first gets the chance to check in again.
 */
final readonly class RescheduleDeadServers
{
    private const string LAST_RUN = 'launchroom:liveness:last-run';

    private const string WATCHING_SINCE = 'launchroom:liveness:watching-since';

    public function __construct(
        private EvacuateDeadServer $evacuate,
        private ClearResolvedPlacementIssues $clearIssues,
    ) {}

    public function handle(): int
    {
        $threshold = config()->integer('launchroom.scheduling.node_dead_after_seconds');

        if (! $this->watchingFor($threshold)) {
            return 0;
        }

        $moved = 0;

        $ids = Server::query()
            ->whereIn('status', ServerStatus::down())
            ->where(static fn (Builder $query) => $query->whereNull('last_seen_at')->orWhere('last_seen_at', '<', now()->subSeconds($threshold)))
            ->orderBy('id')
            ->pluck('id');

        foreach ($ids as $id) {
            $moved += DB::transaction(function () use ($id, $threshold): int {
                $server = Server::query()->whereKey($id)->lockForUpdate()->first();

                // Re-checked under the lock: it may have checked in meanwhile.
                if ($server === null || ! $server->status->isDown()
                    || ($server->last_seen_at !== null && $server->last_seen_at->greaterThanOrEqualTo(now()->subSeconds($threshold)))) {
                    return 0;
                }

                return $this->evacuate->handle($server);
            });
        }

        DB::transaction(fn (): int => $this->clearIssues->handle());

        return $moved;
    }

    private function watchingFor(int $seconds): bool
    {
        $now = now()->getTimestamp();
        // Some cache stores hand integers back as strings.
        $last = is_numeric($stored = Cache::get(self::LAST_RUN)) ? (int) $stored : null;
        $since = is_numeric($stored = Cache::get(self::WATCHING_SINCE)) ? (int) $stored : null;

        if ($last === null || $since === null || $now - $last > 120) {
            $since = $now;
            Cache::forever(self::WATCHING_SINCE, $since);
        }

        Cache::forever(self::LAST_RUN, $now);

        return $now - $since >= $seconds;
    }
}
