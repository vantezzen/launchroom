<?php

declare(strict_types=1);

namespace App\Actions\Metrics;

use App\Support\Metrics\AnalyticsRow;
use App\Support\Metrics\Cast;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Rollups and retention of request analytics (docs/15 §5.3):
 *
 * - 10-minute rows → hourly rows, recomputed for the last 3 hours on every
 *   run, so late windows are folded in;
 * - hourly rows → daily rows, recomputed for the last 3 days;
 * - 10-minute rows older than 48 h, hourly rows older than
 *   `launchroom.analytics.hourly_retention_days` (7) and daily rows older
 *   than `daily_retention_days` (90) are deleted in chunks.
 *
 * Counts are summed per (server, route, bucket); the merged path and
 * referrer lists keep their top entries and fold the rest into the
 * `other_*` columns, so a rollup row is never bigger than a report row.
 * Idempotent (upserts over fixed UTC buckets). Runs with the usage metrics
 * rollup every ten minutes (routes/console.php).
 */
final readonly class RollupRequestAnalytics
{
    public const int TEN_MINUTES = 10;

    public const int HOUR = 60;

    public const int DAY = 1440;

    private const int DELETE_CHUNK = 10_000;

    /**
     * @return array{rolledUp: int, pruned: int}
     */
    public function handle(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $rolledUp = $this->rollup(self::TEN_MINUTES, self::HOUR, $now->subHours(3)->startOfHour())
            + $this->rollup(self::HOUR, self::DAY, $now->subDays(3)->startOfDay());

        $pruned = 0;

        foreach ($this->retention() as $resolution => $days) {
            $pruned += $this->prune($resolution, $now->subMinutes((int) round($days * 1440))->toIso8601String());
        }

        return ['rolledUp' => $rolledUp, 'pruned' => $pruned];
    }

    /**
     * Days each resolution is kept.
     *
     * @return array<int, float>
     */
    private function retention(): array
    {
        return [
            self::TEN_MINUTES => 2.0,
            self::HOUR => (float) max(1, Config::integer('launchroom.analytics.hourly_retention_days', 7)),
            self::DAY => (float) max(1, Config::integer('launchroom.analytics.daily_retention_days', 90)),
        ];
    }

    private function rollup(int $from, int $to, CarbonImmutable $since): int
    {
        $pairs = DB::table('request_analytics_samples')
            ->where('resolution', $from)
            ->where('at', '>=', $since->toIso8601String())
            ->distinct()
            ->get(['server_id', 'route_id']);
        $written = 0;

        foreach ($pairs as $pair) {
            $rows = DB::table('request_analytics_samples')
                ->where('server_id', $pair->server_id)
                ->where('route_id', $pair->route_id)
                ->where('resolution', $from)
                ->where('at', '>=', $since->toIso8601String())
                ->orderBy('at')
                ->get();

            /** @var array<int, AnalyticsRow> $buckets */
            $buckets = [];

            foreach ($rows as $row) {
                $at = CarbonImmutable::parse(Cast::string($row->at ?? null))->utc();
                $bucket = ($to === self::DAY ? $at->startOfDay() : $at->startOfHour())->getTimestamp();
                $buckets[$bucket] ??= new AnalyticsRow(Cast::id($row->service_instance_id ?? null), Cast::id($row->project_id ?? null));
                $buckets[$bucket]->add($row);
            }

            $insert = [];

            foreach ($buckets as $bucket => $agg) {
                $insert[] = [
                    'server_id' => $pair->server_id,
                    'route_id' => $pair->route_id,
                    'resolution' => $to,
                    'at' => CarbonImmutable::createFromTimestampUTC($bucket)->toIso8601String(),
                    ...$agg->columns(),
                ];
            }

            foreach (array_chunk($insert, 500) as $chunk) {
                DB::table('request_analytics_samples')->upsert(
                    $chunk,
                    ['server_id', 'route_id', 'resolution', 'at'],
                    ['service_instance_id', 'project_id', 'requests', 'paths', 'other_requests', 'other_4xx', 'other_5xx', 'referrers', 'other_referrers', 'browsers', 'bots', 'other_agents'],
                );
                $written += count($chunk);
            }
        }

        return $written;
    }

    private function prune(int $resolution, string $cutoff): int
    {
        $deleted = 0;

        do {
            $count = DB::affectingStatement(
                'DELETE FROM request_analytics_samples WHERE ctid IN (SELECT ctid FROM request_analytics_samples WHERE resolution = ? AND at < ? LIMIT '.self::DELETE_CHUNK.')',
                [$resolution, $cutoff],
            );
            $deleted += $count;
        } while ($count === self::DELETE_CHUNK);

        return $deleted;
    }
}
