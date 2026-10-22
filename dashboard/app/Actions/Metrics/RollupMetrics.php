<?php

declare(strict_types=1);

namespace App\Actions\Metrics;

use App\Support\Metrics\RequestStats;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Downsampling and retention of usage metrics (docs/design/direction.md §8.2,
 * maintainer decision 6: 48 h / 30 days / 1 year):
 *
 * - 1-minute rows → 10-minute rows (averages; totals take the maximum),
 *   recomputed for the last 3 hours on every run, so late minutes (servers
 *   buffer about an hour while the dashboard is unreachable) are folded in;
 * - 10-minute rows → daily rows, recomputed for the last 3 days;
 * - 1-minute rows older than 48 h, 10-minute rows older than 30 days and
 *   daily rows older than 365 days are deleted in chunks.
 *
 * Request metrics (docs/15 §1.3) are summed instead of averaged.
 *
 * Idempotent (upserts over fixed UTC buckets); safe to run at any time and
 * as often as wanted. Scheduled every ten minutes (routes/console.php).
 */
final readonly class RollupMetrics
{
    public const int MINUTE = 1;

    public const int TEN_MINUTES = 10;

    public const int DAY = 1440;

    /** @var array<int, string> resolution => retention */
    public const array RETENTION = [
        self::MINUTE => '48 hours',
        self::TEN_MINUTES => '30 days',
        self::DAY => '365 days',
    ];

    private const int DELETE_CHUNK = 10_000;

    /**
     * @return array{rolledUp: int, pruned: int}
     */
    public function handle(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $rolledUp = 0;

        foreach ([[self::MINUTE, self::TEN_MINUTES, $now->subHours(3)], [self::TEN_MINUTES, self::DAY, $now->subDays(3)->startOfDay()]] as [$from, $to, $since]) {
            $rolledUp += $this->rollupHosts($from, $to, $since);
            $rolledUp += $this->rollupWorkloads($from, $to, $since);
            $rolledUp += $this->rollupRequests($from, $to, $since);
        }

        $pruned = 0;

        foreach (self::RETENTION as $resolution => $keep) {
            $cutoff = $now->sub($keep)->toIso8601String();

            foreach (['server_metric_samples', 'workload_metric_samples', 'request_metric_samples'] as $table) {
                $pruned += $this->prune($table, $resolution, $cutoff);
            }
        }

        return ['rolledUp' => $rolledUp, 'pruned' => $pruned];
    }

    private function rollupHosts(int $from, int $to, CarbonImmutable $since): int
    {
        $bucket = $this->bucket($to);

        return DB::affectingStatement(<<<SQL
            INSERT INTO server_metric_samples (server_id, resolution, at, cpu_pct, mem_used_mb, mem_total_mb, disk_used_mb, disk_total_mb, net_rx_bps, net_tx_bps, load1, build_cpu_cores)
            SELECT server_id, ?, {$bucket}, avg(cpu_pct), round(avg(mem_used_mb)), max(mem_total_mb), round(avg(disk_used_mb)), max(disk_total_mb),
                   round(avg(net_rx_bps)), round(avg(net_tx_bps)), avg(load1), avg(build_cpu_cores)
            FROM server_metric_samples
            WHERE resolution = ? AND at >= {$this->floor($to)}
            GROUP BY server_id, {$bucket}
            ON CONFLICT (server_id, resolution, at) DO UPDATE SET
                cpu_pct = excluded.cpu_pct, mem_used_mb = excluded.mem_used_mb, mem_total_mb = excluded.mem_total_mb,
                disk_used_mb = excluded.disk_used_mb, disk_total_mb = excluded.disk_total_mb,
                net_rx_bps = excluded.net_rx_bps, net_tx_bps = excluded.net_tx_bps, load1 = excluded.load1, build_cpu_cores = excluded.build_cpu_cores
            SQL, [$to, $from, $since->toIso8601String()]);
    }

    private function rollupWorkloads(int $from, int $to, CarbonImmutable $since): int
    {
        $bucket = $this->bucket($to);

        return DB::affectingStatement(<<<SQL
            INSERT INTO workload_metric_samples (workload_id, resolution, at, server_id, service_instance_id, project_id, replica, cpu_cores, mem_mb)
            SELECT workload_id, ?, {$bucket}, max(server_id), max(service_instance_id), max(project_id), max(replica), avg(cpu_cores), round(avg(mem_mb))
            FROM workload_metric_samples
            WHERE resolution = ? AND at >= {$this->floor($to)}
            GROUP BY workload_id, {$bucket}
            ON CONFLICT (workload_id, resolution, at) DO UPDATE SET cpu_cores = excluded.cpu_cores, mem_mb = excluded.mem_mb
            SQL, [$to, $from, $since->toIso8601String()]);
    }

    /**
     * Request counters and latency histograms are sums (docs/15 §1.3).
     */
    private function rollupRequests(int $from, int $to, CarbonImmutable $since): int
    {
        $bucket = $this->bucket($to);
        $latency = RequestStats::SUM_SQL;

        return DB::affectingStatement(<<<SQL
            INSERT INTO request_metric_samples (server_id, route_id, resolution, at, service_instance_id, project_id, requests,
                status_2xx, status_3xx, status_4xx, status_5xx, bytes_in, bytes_out, latency)
            SELECT server_id, route_id, ?, {$bucket}, max(service_instance_id), max(project_id), sum(requests),
                   sum(status_2xx), sum(status_3xx), sum(status_4xx), sum(status_5xx), sum(bytes_in), sum(bytes_out), {$latency}
            FROM request_metric_samples
            WHERE resolution = ? AND at >= {$this->floor($to)}
            GROUP BY server_id, route_id, {$bucket}
            ON CONFLICT (server_id, route_id, resolution, at) DO UPDATE SET
                requests = excluded.requests, status_2xx = excluded.status_2xx, status_3xx = excluded.status_3xx,
                status_4xx = excluded.status_4xx, status_5xx = excluded.status_5xx, bytes_in = excluded.bytes_in,
                bytes_out = excluded.bytes_out, latency = excluded.latency
            SQL, [$to, $from, $since->toIso8601String()]);
    }

    private function prune(string $table, int $resolution, string $cutoff): int
    {
        $deleted = 0;

        do {
            // Chunked so a large backlog never holds one long lock.
            $count = DB::affectingStatement(
                "DELETE FROM {$table} WHERE ctid IN (SELECT ctid FROM {$table} WHERE resolution = ? AND at < ? LIMIT ".self::DELETE_CHUNK.')',
                [$resolution, $cutoff],
            );
            $deleted += $count;
        } while ($count === self::DELETE_CHUNK);

        return $deleted;
    }

    /**
     * The UTC bucket of `at` at the target resolution.
     */
    private function bucket(int $resolution): string
    {
        return $resolution === self::DAY
            ? "date_bin('1 day', at, TIMESTAMPTZ '2000-01-01 00:00:00+00')"
            : "date_bin('{$resolution} minutes', at, TIMESTAMPTZ '2000-01-01 00:00:00+00')";
    }

    /**
     * The start of the bucket holding the window start, so the first bucket is complete.
     */
    private function floor(int $resolution): string
    {
        $width = $resolution === self::DAY ? '1 day' : "{$resolution} minutes";

        return "date_bin('{$width}', ?::timestamptz, TIMESTAMPTZ '2000-01-01 00:00:00+00')";
    }
}
