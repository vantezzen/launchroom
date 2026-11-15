<?php

declare(strict_types=1);

namespace App\Queries\Metrics;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * "Now" in the metrics tables (docs/15 §5): the newest per-minute
 * (resolution 1) sample, when it is at most WITHIN_MINUTES old. Older means
 * the server stopped reporting, and there is no current value.
 */
final class LatestSample
{
    public const int WITHIN_MINUTES = 5;

    public static function cutoff(CarbonImmutable $now): string
    {
        return $now->subMinutes(self::WITHIN_MINUTES)->toIso8601String();
    }

    /**
     * Workload rows (`w`) of each service instance's newest minute.
     */
    public static function workloadsPerInstance(CarbonImmutable $now): Builder
    {
        return DB::table('workload_metric_samples as w')
            ->where('w.resolution', 1)
            ->whereRaw('w.at = (SELECT max(x.at) FROM workload_metric_samples x WHERE x.service_instance_id = w.service_instance_id AND x.resolution = 1 AND x.at >= ?)', [self::cutoff($now)]);
    }

    /**
     * Workload rows (`w`) of each server's newest minute.
     */
    public static function workloadsPerServer(CarbonImmutable $now): Builder
    {
        return DB::table('workload_metric_samples as w')
            ->where('w.resolution', 1)
            ->whereRaw('w.at = (SELECT max(x.at) FROM workload_metric_samples x WHERE x.server_id = w.server_id AND x.resolution = 1 AND x.at >= ?)', [self::cutoff($now)]);
    }

    /**
     * Each server's newest host row with these columns (`server_id` and `at` included).
     *
     * @param  list<int>  $serverIds
     * @param  list<string>  $columns
     */
    public static function servers(array $serverIds, CarbonImmutable $now, array $columns): Builder
    {
        return DB::table('server_metric_samples')
            ->select(['server_id', 'at', ...$columns])
            ->distinct('server_id')
            ->whereIn('server_id', $serverIds)
            ->where('resolution', 1)
            ->where('at', '>=', self::cutoff($now))
            ->orderBy('server_id')
            ->orderByDesc('at');
    }
}
