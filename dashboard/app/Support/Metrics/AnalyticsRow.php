<?php

declare(strict_types=1);

namespace App\Support\Metrics;

use JsonException;

/**
 * Sums request analytics rows (docs/15 §5.3) into one: counts add up, the
 * path and referrer lists are merged and cut back to their top entries with
 * the rest folded into the "other" counts. Used by the rollups.
 */
final class AnalyticsRow
{
    public const int TOP_PATHS = 50;

    public const int TOP_REFERRERS = 20;

    public int $requests = 0;

    /** @var array<string, array{r: int, e4: int, e5: int}> */
    private array $paths = [];

    private int $otherRequests = 0;

    private int $other4xx = 0;

    private int $other5xx = 0;

    /** @var array<string, int> */
    private array $referrers = [];

    private int $otherReferrers = 0;

    private int $browsers = 0;

    private int $bots = 0;

    private int $otherAgents = 0;

    public function __construct(public readonly int $serviceInstanceId, public readonly int $projectId) {}

    /**
     * Adds one stored row (a `request_analytics_samples` record).
     */
    public function add(object $row): void
    {
        $this->requests += Cast::int($row->requests ?? null) ?? 0;
        $this->otherRequests += Cast::int($row->other_requests ?? null) ?? 0;
        $this->other4xx += Cast::int($row->other_4xx ?? null) ?? 0;
        $this->other5xx += Cast::int($row->other_5xx ?? null) ?? 0;
        $this->otherReferrers += Cast::int($row->other_referrers ?? null) ?? 0;
        $this->browsers += Cast::int($row->browsers ?? null) ?? 0;
        $this->bots += Cast::int($row->bots ?? null) ?? 0;
        $this->otherAgents += Cast::int($row->other_agents ?? null) ?? 0;

        foreach ($this->list($row->paths ?? null) as $path) {
            $p = $path['p'] ?? null;

            if (! is_string($p)) {
                continue;
            }

            $current = $this->paths[$p] ?? ['r' => 0, 'e4' => 0, 'e5' => 0];
            $this->paths[$p] = [
                'r' => $current['r'] + (Cast::int($path['r'] ?? null) ?? 0),
                'e4' => $current['e4'] + (Cast::int($path['e4'] ?? null) ?? 0),
                'e5' => $current['e5'] + (Cast::int($path['e5'] ?? null) ?? 0),
            ];
        }

        foreach ($this->list($row->referrers ?? null) as $referrer) {
            $h = $referrer['h'] ?? null;

            if (is_string($h)) {
                $this->referrers[$h] = ($this->referrers[$h] ?? 0) + (Cast::int($referrer['r'] ?? null) ?? 0);
            }
        }
    }

    /**
     * The row's columns (without the key), lists cut to their top entries.
     *
     * @return array{service_instance_id: int, project_id: int, requests: int, paths: string, other_requests: int, other_4xx: int, other_5xx: int, referrers: string, other_referrers: int, browsers: int, bots: int, other_agents: int}
     */
    public function columns(): array
    {
        $paths = $this->paths;
        uksort($paths, static fn (string $a, string $b): int => [$paths[$b]['r'], $a] <=> [$paths[$a]['r'], $b]);
        $keptPaths = [];
        $otherRequests = $this->otherRequests;
        $other4xx = $this->other4xx;
        $other5xx = $this->other5xx;

        foreach ($paths as $p => $counts) {
            if (count($keptPaths) < self::TOP_PATHS) {
                $keptPaths[] = ['p' => (string) $p, ...$counts];

                continue;
            }

            $otherRequests += $counts['r'];
            $other4xx += $counts['e4'];
            $other5xx += $counts['e5'];
        }

        $referrers = $this->referrers;
        uksort($referrers, static fn (string $a, string $b): int => [$referrers[$b], $a] <=> [$referrers[$a], $b]);
        $keptReferrers = [];
        $otherReferrers = $this->otherReferrers;

        foreach ($referrers as $h => $r) {
            if (count($keptReferrers) < self::TOP_REFERRERS) {
                $keptReferrers[] = ['h' => (string) $h, 'r' => $r];
            } else {
                $otherReferrers += $r;
            }
        }

        return [
            'service_instance_id' => $this->serviceInstanceId,
            'project_id' => $this->projectId,
            'requests' => $this->requests,
            'paths' => json_encode($keptPaths, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'other_requests' => $otherRequests,
            'other_4xx' => $other4xx,
            'other_5xx' => $other5xx,
            'referrers' => json_encode($keptReferrers, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'other_referrers' => $otherReferrers,
            'browsers' => $this->browsers,
            'bots' => $this->bots,
            'other_agents' => $this->otherAgents,
        ];
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function list(mixed $json): array
    {
        if (! is_string($json)) {
            return [];
        }

        try {
            $decoded = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? array_values(array_filter($decoded, is_array(...))) : [];
    }
}
