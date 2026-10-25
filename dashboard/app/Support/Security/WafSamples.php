<?php

declare(strict_types=1);

namespace App\Support\Security;

use App\Data\Protocol\V1\Report;
use App\Enums\Security\WafCategory;
use App\Support\Metrics\MetricSamples;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Normalizes Report.metrics.waf (docs/03 §13.4) into rows. Whitelist only,
 * like MetricSamples: known keys with the right types, the cross-field
 * rules of launchroomd's validator; a sample whose counts don't add up is
 * dropped as a whole, an event that fails its grammar (a client network
 * that isn't a canonical /24 or /48) is dropped alone. Query strings,
 * addresses and matched data are not part of the protocol, so nothing
 * else can arrive.
 */
final class WafSamples
{
    private const int MAX_AGE_MINUTES = 24 * 60;

    private const int MAX_FUTURE_MINUTES = 5;

    private const string ROUTE = '/^rt_[0-9A-HJKMNP-TV-Z]{26}$/D';

    private const string TARGET = '/^[A-Z][A-Z_]{0,39}(?::[A-Za-z0-9_.\[\]-]{1,64})?$/D';

    private const string REFERENCE = '/^[A-Za-z0-9_.-]{1,128}$/D';

    /**
     * @return list<array{route_id: string, at: CarbonImmutable, blocked: int, detected: int, categories: list<array{c: string, b: int, d: int}>, rules: list<array{r: int, b: int, d: int}>, paths: list<array{p: string, b: int, d: int}>, other_paths_blocked: int, other_paths_detected: int, events: list<array{at: CarbonImmutable, action: string, method: string, path: string, rules: list<int>, categories: list<string>, targets: list<string>, client_network: string, score: int, reference: string}>}>
     */
    public static function parse(mixed $metrics, CarbonImmutable $now): array
    {
        if (! is_array($metrics) || ! is_array($metrics['waf'] ?? null)) {
            return [];
        }

        $rows = [];

        foreach (array_slice($metrics['waf'], 0, 1024) as $item) {
            $row = is_array($item) ? self::sample($item, $now) : null;

            if ($row !== null) {
                $rows[$row['route_id'].'@'.$row['at']->getTimestamp()] = $row;
            }
        }

        return array_values($rows);
    }

    /**
     * @param  array<array-key, mixed>  $item
     * @return array{route_id: string, at: CarbonImmutable, blocked: int, detected: int, categories: list<array{c: string, b: int, d: int}>, rules: list<array{r: int, b: int, d: int}>, paths: list<array{p: string, b: int, d: int}>, other_paths_blocked: int, other_paths_detected: int, events: list<array{at: CarbonImmutable, action: string, method: string, path: string, rules: list<int>, categories: list<string>, targets: list<string>, client_network: string, score: int, reference: string}>}|null
     */
    private static function sample(array $item, CarbonImmutable $now): ?array
    {
        $route = $item['route'] ?? null;
        $at = self::time($item['at'] ?? null);
        $blocked = self::count($item['blocked'] ?? 0);
        $detected = self::count($item['detected'] ?? 0);

        if (! is_string($route) || preg_match(self::ROUTE, $route) !== 1 || ! $at instanceof CarbonImmutable || $blocked === null || $detected === null
            || $at->second !== 0 || $at->micro !== 0 || $at->minute % 10 !== 0 || $blocked + $detected < 1
            || $at->greaterThan($now->addMinutes(self::MAX_FUTURE_MINUTES)) || $at->lessThan($now->subMinutes(self::MAX_AGE_MINUTES))) {
            return null;
        }

        $categories = self::counts($item['categories'] ?? null, 'category', 13, $blocked, $detected, static fn (mixed $c): ?string => is_string($c) && WafCategory::tryFrom($c) !== null ? $c : null);
        $rules = self::counts($item['rules'] ?? [], 'rule', 20, $blocked, $detected, static fn (mixed $r): ?int => is_int($r) && $r >= 900000 && $r <= 999999 ? $r : null);
        $paths = self::counts($item['paths'] ?? [], 'path', 10, PHP_INT_MAX, PHP_INT_MAX, static fn (mixed $p): ?string => is_string($p) && preg_match(MetricSamples::PATH_PATTERN, $p) === 1 ? $p : null);
        $other = is_array($item['otherPaths'] ?? null) ? $item['otherPaths'] : [];
        $otherBlocked = self::count($other['blocked'] ?? 0);
        $otherDetected = self::count($other['detected'] ?? 0);

        if ($categories === null || $categories === [] || $rules === null || $paths === null || $otherBlocked === null || $otherDetected === null) {
            return null;
        }

        // Paths and "other" add up to the totals when any are reported.
        if (($paths !== [] || $otherBlocked + $otherDetected > 0)
            && ($otherBlocked + array_sum(array_column($paths, 'b')) !== $blocked || $otherDetected + array_sum(array_column($paths, 'd')) !== $detected)) {
            return null;
        }

        $events = [];
        $actions = ['blocked' => 0, 'detected' => 0];

        foreach (is_array($item['events'] ?? null) ? array_slice($item['events'], 0, 20) : [] as $raw) {
            $event = is_array($raw) ? self::event($raw, $at) : null;

            if ($event !== null) {
                $actions[$event['action']]++;
                $events[] = $event;
            }
        }

        if ($actions['blocked'] > $blocked || $actions['detected'] > $detected) {
            return null;
        }

        return [
            'route_id' => $route,
            'at' => $at,
            'blocked' => $blocked,
            'detected' => $detected,
            'categories' => array_map(static fn (array $c): array => ['c' => (string) $c['k'], 'b' => $c['b'], 'd' => $c['d']], $categories),
            'rules' => array_map(static fn (array $r): array => ['r' => (int) $r['k'], 'b' => $r['b'], 'd' => $r['d']], $rules),
            'paths' => array_map(static fn (array $p): array => ['p' => (string) $p['k'], 'b' => $p['b'], 'd' => $p['d']], $paths),
            'other_paths_blocked' => $otherBlocked,
            'other_paths_detected' => $otherDetected,
            'events' => $events,
        ];
    }

    /**
     * Counted items ({<key>, blocked, detected}): unique keys, each ≥ 1 in
     * total and within the sample's totals.
     *
     * @param  callable(mixed): (string|int|null)  $key
     * @return list<array{k: string|int, b: int, d: int}>|null
     */
    private static function counts(mixed $value, string $field, int $max, int $blocked, int $detected, callable $key): ?array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > $max) {
            return null;
        }

        $out = [];

        foreach ($value as $item) {
            $k = is_array($item) ? $key($item[$field] ?? null) : null;
            $b = is_array($item) ? self::count($item['blocked'] ?? 0) : null;
            $d = is_array($item) ? self::count($item['detected'] ?? 0) : null;

            if ($k === null || $b === null || $d === null || $b + $d < 1 || $b > $blocked || $d > $detected || isset($out[$k])) {
                return null;
            }

            $out[$k] = ['k' => $k, 'b' => $b, 'd' => $d];
        }

        return array_values($out);
    }

    /**
     * @param  array<array-key, mixed>  $raw
     * @return array{at: CarbonImmutable, action: string, method: string, path: string, rules: list<int>, categories: list<string>, targets: list<string>, client_network: string, score: int, reference: string}|null
     */
    private static function event(array $raw, CarbonImmutable $window): ?array
    {
        $at = self::time($raw['at'] ?? null);
        $action = $raw['action'] ?? null;
        $method = $raw['method'] ?? null;
        $path = $raw['path'] ?? null;
        $network = $raw['clientNetwork'] ?? null;
        $reference = $raw['reference'] ?? null;
        $score = $raw['score'] ?? 0;

        if (! $at instanceof CarbonImmutable || $at->lessThan($window) || ! $at->lessThan($window->addMinutes(10))
            || ! in_array($action, ['blocked', 'detected'], true)
            || ! is_string($method) || preg_match('/^[A-Z]{1,16}$/D', $method) !== 1
            || ! is_string($path) || preg_match(MetricSamples::PATH_PATTERN, $path) !== 1
            || ! ClientNetwork::isValid($network) || ! is_string($network)
            || ! is_string($reference) || preg_match(self::REFERENCE, $reference) !== 1
            || ! is_int($score) || $score < 0 || $score > 10000) {
            return null;
        }

        $rules = self::list($raw['rules'] ?? null, 1, 8, static fn (mixed $r): bool => is_int($r) && $r >= 900000 && $r <= 999999);
        $categories = self::list($raw['categories'] ?? null, 1, 13, static fn (mixed $c): bool => is_string($c) && WafCategory::tryFrom($c) !== null);
        $targets = self::list($raw['targets'] ?? [], 0, 8, static fn (mixed $t): bool => is_string($t) && strlen($t) <= 105 && preg_match(self::TARGET, $t) === 1);

        if ($rules === null || $categories === null || $targets === null) {
            return null;
        }

        return [
            'at' => $at,
            'action' => $action,
            'method' => $method,
            'path' => $path,
            'rules' => array_map(static fn (mixed $r): int => is_int($r) ? $r : 0, $rules),
            'categories' => array_map(static fn (mixed $c): string => is_string($c) ? $c : '', $categories),
            'targets' => array_map(static fn (mixed $t): string => is_string($t) ? $t : '', $targets),
            'client_network' => $network,
            'score' => $score,
            'reference' => $reference,
        ];
    }

    /**
     * @param  callable(mixed): bool  $valid
     * @return list<mixed>|null
     */
    private static function list(mixed $value, int $min, int $max, callable $valid): ?array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) < $min || count($value) > $max) {
            return null;
        }

        foreach ($value as $item) {
            if (! $valid($item)) {
                return null;
            }
        }

        return count(array_unique($value, SORT_REGULAR)) === count($value) ? $value : null;
    }

    private static function time(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match(Report::TIME_PATTERN, $value) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private static function count(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }
}
