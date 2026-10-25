<?php

declare(strict_types=1);

namespace App\Support\Metrics;

use App\Data\Protocol\V1\Report;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Normalizes Report.metrics (protocol/schema/v1/report.json `ReportMetrics`)
 * into rows. Whitelist only (docs/09 R12): known keys with the right types,
 * values clamped to sane bounds; an item that doesn't fit is dropped instead
 * of failing the report, so one odd sample never blocks the server's status.
 */
final class MetricSamples
{
    /** Minutes a sample may lie in the future (clock skew) or the past (buffer of about an hour, plus slack). */
    private const int MAX_FUTURE_MINUTES = 5;

    private const int MAX_AGE_MINUTES = 6 * 60;

    /**
     * @return list<array{at: CarbonImmutable, cpu_pct: float|null, build_cpu_cores: float|null, mem_used_mb: int|null, mem_total_mb: int|null, disk_used_mb: int|null, disk_total_mb: int|null, net_rx_bps: int|null, net_tx_bps: int|null, load1: float|null}>
     */
    public static function host(mixed $metrics, CarbonImmutable $now): array
    {
        $rows = [];

        foreach (self::items($metrics, 'host', 120) as $item) {
            $at = self::minute($item['at'] ?? null, $now);

            if (! $at instanceof CarbonImmutable) {
                continue;
            }

            $rows[$at->getTimestamp()] = [
                'at' => $at,
                'cpu_pct' => self::float($item['cpuPct'] ?? null, 100),
                'build_cpu_cores' => self::float($item['buildCpuCores'] ?? null, 4096),
                'mem_used_mb' => self::int($item['memUsedMb'] ?? null),
                'mem_total_mb' => self::int($item['memTotalMb'] ?? null),
                'disk_used_mb' => self::int($item['diskUsedMb'] ?? null),
                'disk_total_mb' => self::int($item['diskTotalMb'] ?? null),
                'net_rx_bps' => self::int($item['netRxBps'] ?? null, PHP_INT_MAX),
                'net_tx_bps' => self::int($item['netTxBps'] ?? null, PHP_INT_MAX),
                'load1' => self::float($item['load1'] ?? null, 1_000_000),
            ];
        }

        return array_values($rows);
    }

    /**
     * @return list<array{workload_id: string, at: CarbonImmutable, cpu_cores: float|null, mem_mb: int|null}>
     */
    public static function workloads(mixed $metrics, CarbonImmutable $now): array
    {
        $rows = [];

        foreach (self::items($metrics, 'workloads', 8192) as $item) {
            $workload = $item['workload'] ?? null;
            $at = self::minute($item['at'] ?? null, $now);

            if (! is_string($workload) || preg_match('/^wl_[0-9A-HJKMNP-TV-Z]{26}$/D', $workload) !== 1 || ! $at instanceof CarbonImmutable) {
                continue;
            }

            $cpu = self::float($item['cpuCores'] ?? null, 4096);
            $mem = self::int($item['memMb'] ?? null);

            if ($cpu === null && $mem === null) {
                continue;
            }

            $rows[$workload.'@'.$at->getTimestamp()] = ['workload_id' => $workload, 'at' => $at, 'cpu_cores' => $cpu, 'mem_mb' => $mem];
        }

        return array_values($rows);
    }

    /**
     * Request metrics of an edge (docs/15 §1.2): one row per (route, minute).
     * Counts that don't add up (status classes or latency buckets above
     * `requests`) drop the item; a malformed histogram drops only the histogram.
     *
     * @return list<array{route_id: string, at: CarbonImmutable, requests: int, status_2xx: int, status_3xx: int, status_4xx: int, status_5xx: int, bytes_in: int, bytes_out: int, latency: list<int>|null}>
     */
    public static function requests(mixed $metrics, CarbonImmutable $now): array
    {
        $rows = [];

        foreach (self::items($metrics, 'requests', 8192) as $item) {
            $route = $item['route'] ?? null;
            $at = self::minute($item['at'] ?? null, $now);
            $requests = self::int($item['requests'] ?? null, PHP_INT_MAX);

            if (! is_string($route) || preg_match('/^rt_[0-9A-HJKMNP-TV-Z]{26}$/D', $route) !== 1 || ! $at instanceof CarbonImmutable || $requests === null || $requests < 1) {
                continue;
            }

            $status = [];

            foreach (['2xx', '3xx', '4xx', '5xx'] as $class) {
                $value = $item['status'.$class] ?? 0;
                $status[$class] = self::int($value, PHP_INT_MAX);

                if ($status[$class] === null) {
                    continue 2;
                }
            }

            if (array_sum($status) > $requests) {
                continue;
            }

            $rows[$route.'@'.$at->getTimestamp()] = [
                'route_id' => $route,
                'at' => $at,
                'requests' => $requests,
                'status_2xx' => (int) $status['2xx'],
                'status_3xx' => (int) $status['3xx'],
                'status_4xx' => (int) $status['4xx'],
                'status_5xx' => (int) $status['5xx'],
                'bytes_in' => self::int($item['bytesIn'] ?? 0, PHP_INT_MAX) ?? 0,
                'bytes_out' => self::int($item['bytesOut'] ?? 0, PHP_INT_MAX) ?? 0,
                'latency' => self::histogram($item['latencyMs'] ?? null, $requests),
            ];
        }

        return array_values($rows);
    }

    /** Analytics windows may be older: an edge buffers them while the dashboard is unreachable. */
    private const int MAX_ANALYTICS_AGE_MINUTES = 24 * 60;

    /** state.RequestPath: "/" + printable ASCII without "?" and "#", ≤ 128 bytes. */
    public const string PATH_PATTERN = '/^\/[!"$->@-~]{0,127}$/D';

    /** state.Hostname. */
    public const string HOST_PATTERN = '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D';

    /**
     * Request analytics of an edge (docs/15 §5.2): one row per (route,
     * 10-minute window) with its top paths and referrer hosts. Whitelisted
     * like the other samples; an item whose counts don't add up (paths plus
     * other ≠ requests, referrers or client classes above requests), with a
     * malformed or duplicate path or host, is dropped as a whole.
     *
     * @return list<array{route_id: string, at: CarbonImmutable, requests: int, paths: list<array{p: string, r: int, e4: int, e5: int}>, other_requests: int, other_4xx: int, other_5xx: int, referrers: list<array{h: string, r: int}>, other_referrers: int, browsers: int, bots: int, other_agents: int}>
     */
    public static function analytics(mixed $metrics, CarbonImmutable $now): array
    {
        $rows = [];

        foreach (self::items($metrics, 'analytics', 1024) as $item) {
            $route = $item['route'] ?? null;
            $at = self::minute($item['at'] ?? null, $now, self::MAX_ANALYTICS_AGE_MINUTES);
            $requests = self::int($item['requests'] ?? null, PHP_INT_MAX);

            if (! is_string($route) || preg_match('/^rt_[0-9A-HJKMNP-TV-Z]{26}$/D', $route) !== 1
                || ! $at instanceof CarbonImmutable || $at->minute % 10 !== 0 || $requests === null || $requests < 1) {
                continue;
            }

            $paths = self::pathCounts($item['paths'] ?? []);
            $other = is_array($item['otherPaths'] ?? null) ? self::totals($item['otherPaths']) : (isset($item['otherPaths']) ? null : ['r' => 0, 'e4' => 0, 'e5' => 0]);
            $referrers = self::referrerCounts($item['referrers'] ?? []);
            $counts = [];

            foreach (['otherReferrers', 'browsers', 'bots', 'otherAgents'] as $key) {
                $counts[$key] = self::int($item[$key] ?? 0, PHP_INT_MAX);
            }

            if ($paths === null || $other === null || $referrers === null || in_array(null, $counts, true)) {
                continue;
            }

            $pathSum = $other['r'] + array_sum(array_column($paths, 'r'));
            $referrerSum = (int) $counts['otherReferrers'] + array_sum(array_column($referrers, 'r'));
            $agentSum = (int) $counts['browsers'] + (int) $counts['bots'] + (int) $counts['otherAgents'];

            if ($pathSum !== $requests || $referrerSum > $requests || $agentSum > $requests) {
                continue;
            }

            $rows[$route.'@'.$at->getTimestamp()] = [
                'route_id' => $route,
                'at' => $at,
                'requests' => $requests,
                'paths' => $paths,
                'other_requests' => $other['r'],
                'other_4xx' => $other['e4'],
                'other_5xx' => $other['e5'],
                'referrers' => $referrers,
                'other_referrers' => (int) $counts['otherReferrers'],
                'browsers' => (int) $counts['browsers'],
                'bots' => (int) $counts['bots'],
                'other_agents' => (int) $counts['otherAgents'],
            ];
        }

        return array_values($rows);
    }

    /**
     * @return list<array{p: string, r: int, e4: int, e5: int}>|null
     */
    private static function pathCounts(mixed $value): ?array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > 50) {
            return null;
        }

        $out = [];

        foreach ($value as $item) {
            $path = is_array($item) ? ($item['path'] ?? null) : null;
            $totals = is_array($item) ? self::totals($item) : null;

            if (! is_string($path) || preg_match(self::PATH_PATTERN, $path) !== 1 || $totals === null || $totals['r'] < 1 || isset($out[$path])) {
                return null;
            }

            $out[$path] = ['p' => $path, ...$totals];
        }

        return array_values($out);
    }

    /**
     * @param  array<array-key, mixed>  $item
     * @return array{r: int, e4: int, e5: int}|null
     */
    private static function totals(array $item): ?array
    {
        $r = self::int($item['requests'] ?? null, PHP_INT_MAX);
        $e4 = self::int($item['status4xx'] ?? 0, PHP_INT_MAX);
        $e5 = self::int($item['status5xx'] ?? 0, PHP_INT_MAX);

        return $r === null || $e4 === null || $e5 === null || $e4 + $e5 > $r ? null : ['r' => $r, 'e4' => $e4, 'e5' => $e5];
    }

    /**
     * @return list<array{h: string, r: int}>|null
     */
    private static function referrerCounts(mixed $value): ?array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > 20) {
            return null;
        }

        $out = [];

        foreach ($value as $item) {
            $host = is_array($item) ? ($item['host'] ?? null) : null;
            $r = is_array($item) ? self::int($item['requests'] ?? null, PHP_INT_MAX) : null;

            if (! is_string($host) || strlen($host) > 253 || preg_match(self::HOST_PATTERN, $host) !== 1 || $r === null || $r < 1 || isset($out[$host])) {
                return null;
            }

            $out[$host] = ['h' => $host, 'r' => $r];
        }

        return array_values($out);
    }

    /**
     * @return list<int>|null
     */
    private static function histogram(mixed $value, int $requests): ?array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) !== RequestStats::BUCKETS) {
            return null;
        }

        $out = [];

        foreach ($value as $count) {
            if (! is_int($count) || $count < 0) {
                return null;
            }

            $out[] = $count;
        }

        return array_sum($out) <= $requests ? $out : null;
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private static function items(mixed $metrics, string $key, int $max): array
    {
        if (! is_array($metrics) || ! is_array($metrics[$key] ?? null)) {
            return [];
        }

        return array_values(array_filter(array_slice($metrics[$key], 0, $max), is_array(...)));
    }

    private static function minute(mixed $value, CarbonImmutable $now, int $maxAge = self::MAX_AGE_MINUTES): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match(Report::TIME_PATTERN, $value) !== 1) {
            return null;
        }

        try {
            $at = CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }

        if ($at->second !== 0 || $at->micro !== 0
            || $at->greaterThan($now->addMinutes(self::MAX_FUTURE_MINUTES))
            || $at->lessThan($now->subMinutes($maxAge))) {
            return null;
        }

        return $at;
    }

    private static function float(mixed $value, float $max): ?float
    {
        if (! is_int($value) && ! is_float($value)) {
            return null;
        }

        $value = (float) $value;

        return is_finite($value) && $value >= 0 ? min($value, $max) : null;
    }

    private static function int(mixed $value, int $max = 2_147_483_647): ?int
    {
        return is_int($value) && $value >= 0 ? min($value, $max) : null;
    }
}
