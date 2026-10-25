<?php

declare(strict_types=1);

namespace App\Actions\Security;

use App\Support\Metrics\Cast;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Rollups and retention of the web firewall's results (docs/22 §6.5):
 *
 * - 10-minute rows → hourly rows, recomputed for the last 3 hours on every
 *   run so late windows fold in (idempotent upserts over UTC hours);
 * - 10-minute rows older than `launchroom.waf.sample_retention_days` (7),
 *   hourly rows older than `hourly_retention_days` (90) and event samples
 *   older than `event_retention_days` (7) are deleted in chunks.
 *
 * Merged rules keep the top 20 and paths the top 10, the rest of the paths
 * folded into the `other_paths_*` columns, so a rollup row is never bigger
 * than a report row. Runs with the metrics rollup every ten minutes.
 */
final readonly class RollupWafSamples
{
    public const int TEN_MINUTES = 10;

    public const int HOUR = 60;

    private const int DELETE_CHUNK = 10_000;

    /**
     * @return array{rolledUp: int, pruned: int}
     */
    public function handle(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $rolledUp = $this->rollup($now->subHours(3)->startOfHour());
        $pruned = $this->prune('waf_samples', 'at < ? AND resolution = '.self::TEN_MINUTES, $now->subDays(max(1, Config::integer('launchroom.waf.sample_retention_days', 7))))
            + $this->prune('waf_samples', 'at < ? AND resolution = '.self::HOUR, $now->subDays(max(1, Config::integer('launchroom.waf.hourly_retention_days', 90))))
            + $this->prune('waf_events', 'at < ?', $now->subDays(max(1, Config::integer('launchroom.waf.event_retention_days', 7))));

        return ['rolledUp' => $rolledUp, 'pruned' => $pruned];
    }

    private function rollup(CarbonImmutable $since): int
    {
        $rows = DB::table('waf_samples')
            ->where('resolution', self::TEN_MINUTES)
            ->where('at', '>=', $since->toIso8601String())
            ->orderBy('at')
            ->get();

        /** @var array<string, array{server_id: int, route_id: string, at: int, service_instance_id: int, project_id: int, blocked: int, detected: int, categories: array<string, array{b: int, d: int}>, rules: array<int, array{b: int, d: int}>, paths: array<string, array{b: int, d: int}>, other_b: int, other_d: int}> $buckets */
        $buckets = [];

        foreach ($rows as $row) {
            $hour = CarbonImmutable::parse(Cast::string($row->at ?? null))->utc()->startOfHour()->getTimestamp();
            $key = Cast::id($row->server_id ?? null).'|'.Cast::string($row->route_id ?? null).'|'.$hour;
            $buckets[$key] ??= [
                'server_id' => Cast::id($row->server_id ?? null),
                'route_id' => Cast::string($row->route_id ?? null),
                'at' => $hour,
                'service_instance_id' => Cast::id($row->service_instance_id ?? null),
                'project_id' => Cast::id($row->project_id ?? null),
                'blocked' => 0, 'detected' => 0, 'categories' => [], 'rules' => [], 'paths' => [], 'other_b' => 0, 'other_d' => 0,
            ];
            $bucket = &$buckets[$key];
            $bucket['blocked'] += Cast::id($row->blocked ?? null);
            $bucket['detected'] += Cast::id($row->detected ?? null);
            $bucket['other_b'] += Cast::id($row->other_paths_blocked ?? null);
            $bucket['other_d'] += Cast::id($row->other_paths_detected ?? null);

            foreach ($this->decode($row->categories ?? null) as $c) {
                $bucket['categories'][Cast::string($c['c'] ?? null)]['b'] = ($bucket['categories'][Cast::string($c['c'] ?? null)]['b'] ?? 0) + Cast::id($c['b'] ?? null);
                $bucket['categories'][Cast::string($c['c'] ?? null)]['d'] = ($bucket['categories'][Cast::string($c['c'] ?? null)]['d'] ?? 0) + Cast::id($c['d'] ?? null);
            }

            foreach ($this->decode($row->rules ?? null) as $r) {
                $bucket['rules'][Cast::id($r['r'] ?? null)]['b'] = ($bucket['rules'][Cast::id($r['r'] ?? null)]['b'] ?? 0) + Cast::id($r['b'] ?? null);
                $bucket['rules'][Cast::id($r['r'] ?? null)]['d'] = ($bucket['rules'][Cast::id($r['r'] ?? null)]['d'] ?? 0) + Cast::id($r['d'] ?? null);
            }

            foreach ($this->decode($row->paths ?? null) as $p) {
                $bucket['paths'][Cast::string($p['p'] ?? null)]['b'] = ($bucket['paths'][Cast::string($p['p'] ?? null)]['b'] ?? 0) + Cast::id($p['b'] ?? null);
                $bucket['paths'][Cast::string($p['p'] ?? null)]['d'] = ($bucket['paths'][Cast::string($p['p'] ?? null)]['d'] ?? 0) + Cast::id($p['d'] ?? null);
            }

            unset($bucket);
        }

        $insert = [];

        foreach ($buckets as $bucket) {
            [$paths, $otherB, $otherD] = $this->top($bucket['paths'], 10);
            [$rules] = $this->top($bucket['rules'], 20);

            $insert[] = [
                'server_id' => $bucket['server_id'],
                'route_id' => $bucket['route_id'],
                'resolution' => self::HOUR,
                'at' => CarbonImmutable::createFromTimestampUTC($bucket['at'])->toIso8601String(),
                'service_instance_id' => $bucket['service_instance_id'],
                'project_id' => $bucket['project_id'],
                'blocked' => $bucket['blocked'],
                'detected' => $bucket['detected'],
                'categories' => json_encode(array_map(static fn (string|int $c, array $n): array => ['c' => (string) $c, 'b' => $n['b'], 'd' => $n['d']], array_keys($bucket['categories']), $bucket['categories']), JSON_THROW_ON_ERROR),
                'rules' => json_encode(array_map(static fn (string|int $r, array $n): array => ['r' => (int) $r, 'b' => $n['b'], 'd' => $n['d']], array_keys($rules), $rules), JSON_THROW_ON_ERROR),
                'paths' => json_encode(array_map(static fn (string|int $p, array $n): array => ['p' => (string) $p, 'b' => $n['b'], 'd' => $n['d']], array_keys($paths), $paths), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'other_paths_blocked' => $bucket['other_b'] + $otherB,
                'other_paths_detected' => $bucket['other_d'] + $otherD,
            ];
        }

        foreach (array_chunk($insert, 500) as $chunk) {
            DB::table('waf_samples')->upsert(
                $chunk,
                ['server_id', 'route_id', 'resolution', 'at'],
                ['service_instance_id', 'project_id', 'blocked', 'detected', 'categories', 'rules', 'paths', 'other_paths_blocked', 'other_paths_detected'],
            );
        }

        return count($insert);
    }

    /**
     * The `$max` largest entries and the sums of the rest.
     *
     * @template K of array-key
     *
     * @param  array<K, array{b: int, d: int}>  $items
     * @return array{array<K, array{b: int, d: int}>, int, int}
     */
    private function top(array $items, int $max): array
    {
        uasort($items, static fn (array $a, array $b): int => ($b['b'] + $b['d']) <=> ($a['b'] + $a['d']));
        $rest = array_slice($items, $max, null, true);

        return [array_slice($items, 0, $max, true), array_sum(array_column($rest, 'b')), array_sum(array_column($rest, 'd'))];
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function decode(mixed $json): array
    {
        $value = is_string($json) ? json_decode($json, true) : null;

        return is_array($value) ? array_values(array_filter($value, is_array(...))) : [];
    }

    private function prune(string $table, string $where, CarbonImmutable $cutoff): int
    {
        $deleted = 0;

        do {
            $count = DB::affectingStatement(
                "DELETE FROM {$table} WHERE ctid IN (SELECT ctid FROM {$table} WHERE {$where} LIMIT ".self::DELETE_CHUNK.')',
                [$cutoff->toIso8601String()],
            );
            $deleted += $count;
        } while ($count === self::DELETE_CHUNK);

        return $deleted;
    }
}
