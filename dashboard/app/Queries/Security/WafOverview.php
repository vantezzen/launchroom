<?php

declare(strict_types=1);

namespace App\Queries\Security;

use App\Data\Security\SecurityServiceRefData;
use App\Data\Security\WafCategoryCountData;
use App\Data\Security\WafEdgeProblemData;
use App\Data\Security\WafEventData;
use App\Data\Security\WafExclusionDraftData;
use App\Data\Security\WafOverviewData;
use App\Data\Security\WafRuleData;
use App\Data\Security\WafSeriesData;
use App\Data\Security\WafServiceCountData;
use App\Enums\AnalyticsRange;
use App\Enums\DaemonFeature;
use App\Enums\EnvironmentKind;
use App\Enums\Security\WafCategory;
use App\Enums\Security\WafMode;
use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Models\Server;
use App\Models\Team;
use App\Support\Metrics\Cast;
use App\Support\Security\CrsRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The web firewall's results for a team (docs/22 §9.2 part 3): requests
 * blocked per hour (per day for a month) by category, detections of Log
 * only services, totals per category and service, the services watching
 * without blocking, the latest events and edges that can't run the
 * firewall. Day and Week read the 10-minute rows, Month the hourly ones.
 */
final readonly class WafOverview
{
    /** Chart series in palette order (docs/22 §9.2): the rest folds into "Other". */
    public const array SERIES = ['sqli', 'xss', 'rce', 'scanner', 'other'];

    public function handle(Team $team, AnalyticsRange $range, SecurityServiceRefs $refs, bool $networksVisible): WafOverviewData
    {
        $now = CarbonImmutable::now();
        $bucket = $range->bucketSeconds();
        $end = CarbonImmutable::createFromTimestampUTC(intdiv($now->getTimestamp(), $bucket) * $bucket + $bucket);
        $start = $end->subSeconds($range->seconds());
        $times = [];

        for ($t = $start->getTimestamp(); $t < $end->getTimestamp(); $t += $bucket) {
            $times[] = $t;
        }

        $series = array_fill_keys([...self::SERIES, 'detected'], array_fill(0, count($times), 0));
        $categories = [];
        $byInstance = [];
        $blocked = 0;
        $detected = 0;

        foreach ($this->rows($team->id, $range, $start, $end) as $row) {
            $at = CarbonImmutable::parse(Cast::string($row->at ?? null))->getTimestamp();
            $i = intdiv($at - $start->getTimestamp(), $bucket);

            if ($i < 0 || $i >= count($times)) {
                continue;
            }

            $b = Cast::id($row->blocked ?? null);
            $d = Cast::id($row->detected ?? null);
            $blocked += $b;
            $detected += $d;
            $series['detected'][$i] += $d;
            $instance = Cast::id($row->service_instance_id ?? null);
            $byInstance[$instance]['blocked'] = ($byInstance[$instance]['blocked'] ?? 0) + $b;
            $byInstance[$instance]['detected'] = ($byInstance[$instance]['detected'] ?? 0) + $d;
            $counts = [];

            foreach ($this->jsonRows($row->categories ?? null) as $c) {
                $category = Cast::string($c['c'] ?? null);
                $categories[$category]['b'] = ($categories[$category]['b'] ?? 0) + Cast::id($c['b'] ?? null);
                $categories[$category]['d'] = ($categories[$category]['d'] ?? 0) + Cast::id($c['d'] ?? null);
                $key = WafCategory::tryFrom($category)?->chartSeries() ?? 'other';
                $counts[$key] = ($counts[$key] ?? 0) + Cast::id($c['b'] ?? null);
            }

            // A request with rules of several categories counts in each:
            // share its blocked count out so the bars add up to requests.
            foreach (self::apportion($b, $counts) as $key => $value) {
                $series[$key][$i] += $value;
            }
        }

        $refs->load($team->id, array_keys($byInstance));

        $categoryData = [];

        foreach ($categories as $category => $n) {
            $case = WafCategory::tryFrom($category);

            if ($case !== null && $n['b'] + $n['d'] > 0) {
                $categoryData[] = new WafCategoryCountData($case->value, $case->label(), $n['b'], $n['d']);
            }
        }

        usort($categoryData, static fn (WafCategoryCountData $a, WafCategoryCountData $b): int => ($b->blocked + $b->detected) <=> ($a->blocked + $a->detected));

        $services = [];

        foreach ($byInstance as $instance => $n) {
            $ref = $refs->get($instance);

            if ($ref instanceof SecurityServiceRefData) {
                $services[] = new WafServiceCountData($ref, $n['blocked'], $n['detected'], [], null, null);
            }
        }

        usort($services, static fn (WafServiceCountData $a, WafServiceCountData $b): int => ($b->blocked + $b->detected) <=> ($a->blocked + $a->detected));

        return new WafOverviewData(
            range: $range->value,
            bucketSeconds: $bucket,
            timestamps: array_map(static fn (int $t): string => CarbonImmutable::createFromTimestampUTC($t)->toIso8601ZuluString(), $times),
            series: array_map(static fn (string $key): WafSeriesData => new WafSeriesData($key, self::seriesLabel($key), array_values($series[$key])), array_keys($series)),
            blocked: $blocked,
            detected: $detected,
            categories: $categoryData,
            services: array_slice($services, 0, 5),
            watching: $this->watching($team->id, $refs),
            recent: $this->events($team->id, $refs, $networksVisible, 20),
            edges: $this->edges($team->id),
            ruleSet: $this->ruleSet($team->id),
            networksVisible: $networksVisible,
        );
    }

    public function previousBlocked(Team $team, AnalyticsRange $range): ?int
    {
        $now = CarbonImmutable::now();
        $end = $now->subSeconds($range->seconds());
        $start = $end->subSeconds($range->seconds());

        // Older than the 10-minute rows keep: use the hourly rows.
        $resolution = $start->lessThan($now->subDays(7)) ? 60 : 10;
        $sum = DB::table('waf_samples')
            ->whereIn('service_instance_id', DB::table('service_instances')->select('id')->where('team_id', $team->id))
            ->where('resolution', $resolution)
            ->where('at', '>=', $start->toIso8601String())
            ->where('at', '<', $end->toIso8601String())
            ->selectRaw('count(*) as n, coalesce(sum(blocked), 0) as blocked')
            ->first();

        return $sum === null || Cast::id($sum->n ?? null) === 0 ? null : Cast::id($sum->blocked ?? null);
    }

    /**
     * Events of the team (or of one instance), newest first.
     *
     * @return list<WafEventData>
     */
    public function events(int $teamId, SecurityServiceRefs $refs, bool $networksVisible, int $limit, ?int $instanceId = null): array
    {
        $rows = DB::table('waf_events')
            ->whereIn('service_instance_id', DB::table('service_instances')->select('id')->where('team_id', $teamId))
            ->when($instanceId !== null, static fn (Builder $q) => $q->where('service_instance_id', $instanceId))
            ->where('at', '>=', CarbonImmutable::now()->subDays(7)->toIso8601String())
            ->orderByDesc('at')
            ->limit($limit)
            ->get();

        $refs->load($teamId, array_values(array_map(static fn (object $row): int => Cast::id($row->service_instance_id ?? null), $rows->all())));
        $out = [];

        foreach ($rows as $row) {
            $service = $refs->get(Cast::id($row->service_instance_id ?? null));

            if (! $service instanceof SecurityServiceRefData) {
                continue;
            }

            $rules = array_values(array_filter($this->scalars($row->rule_ids ?? null), is_int(...)));
            $targets = array_values(array_filter($this->scalars($row->targets ?? null), is_string(...)));
            $path = Cast::string($row->path ?? null);

            $out[] = new WafEventData(
                id: Cast::string($row->id ?? null),
                at: CarbonImmutable::parse(Cast::string($row->at ?? null))->toIso8601ZuluString(),
                action: Cast::string($row->action ?? null),
                method: Cast::string($row->method ?? null),
                path: $path,
                rules: array_map(static fn (int $rule): WafRuleData => new WafRuleData($rule, CrsRules::message($rule), WafCategory::ofRule($rule)->value), $rules),
                categories: array_values(array_filter($this->scalars($row->categories ?? null), is_string(...))),
                targets: $targets,
                clientNetwork: $networksVisible ? Cast::string($row->client_network ?? null) : null,
                score: Cast::id($row->score ?? null),
                reference: Cast::string($row->reference ?? null),
                service: $service,
                exclusion: self::draft($rules, $path, $targets, $service->href),
            );
        }

        return $out;
    }

    /**
     * Log only services with their detections in the last 7 days.
     *
     * @return list<WafServiceCountData>
     */
    public function watching(int $teamId, SecurityServiceRefs $refs, ?int $instanceId = null): array
    {
        $instances = DB::table('service_instances as si')
            ->join('environments as e', 'e.id', '=', 'si.environment_id')
            ->where('si.team_id', $teamId)
            ->whereNull('si.removed_at')
            ->where('si.waf_mode', WafMode::Log->value)
            ->where('e.kind', '!=', EnvironmentKind::Preview->value)
            ->when($instanceId !== null, static fn (Builder $q) => $q->where('si.id', $instanceId))
            ->whereExists(static fn (Builder $q) => $q->selectRaw('1')->from('domains')->whereColumn('domains.service_instance_id', 'si.id')->whereIn('domains.kind', ['custom', 'generated']))
            ->pluck('si.id')
            ->all();
        $instances = array_values(array_map(Cast::id(...), $instances));

        if ($instances === []) {
            return [];
        }

        $refs->load($teamId, $instances);
        $since = CarbonImmutable::now()->subDays(7);
        /** @var array<int, array{detected: int, blocked: int, categories: array<string, int>, paths: array<string, int>}> $stats */
        $stats = [];

        foreach (DB::table('waf_samples')->whereIn('service_instance_id', $instances)->where('resolution', 10)->where('at', '>=', $since->toIso8601String())->get(['service_instance_id', 'blocked', 'detected', 'categories', 'paths']) as $row) {
            $id = Cast::id($row->service_instance_id ?? null);
            $stats[$id] ??= ['detected' => 0, 'blocked' => 0, 'categories' => [], 'paths' => []];
            $stats[$id]['detected'] += Cast::id($row->detected ?? null);
            $stats[$id]['blocked'] += Cast::id($row->blocked ?? null);

            foreach ($this->jsonRows($row->categories ?? null) as $c) {
                $key = Cast::string($c['c'] ?? null);
                $stats[$id]['categories'][$key] = ($stats[$id]['categories'][$key] ?? 0) + Cast::id($c['d'] ?? null) + Cast::id($c['b'] ?? null);
            }

            foreach ($this->jsonRows($row->paths ?? null) as $p) {
                $key = Cast::string($p['p'] ?? null);
                $stats[$id]['paths'][$key] = ($stats[$id]['paths'][$key] ?? 0) + Cast::id($p['d'] ?? null) + Cast::id($p['b'] ?? null);
            }
        }

        $out = [];

        foreach ($instances as $id) {
            $ref = $refs->get($id);

            if (! $ref instanceof SecurityServiceRefData) {
                continue;
            }

            $cats = $stats[$id]['categories'] ?? [];
            arsort($cats);
            $paths = $stats[$id]['paths'] ?? [];
            arsort($paths);

            $out[] = new WafServiceCountData(
                service: $ref,
                blocked: $stats[$id]['blocked'] ?? 0,
                detected: $stats[$id]['detected'] ?? 0,
                categories: array_map(static fn (string|int $c): string => WafCategory::tryFrom((string) $c)?->label() ?? 'Other', array_slice(array_keys($cats), 0, 2)),
                topPath: array_key_first($paths) ?? null,
                settingsUrl: "/p/{$ref->projectId}/{$ref->environmentId}/s/{$ref->id}/firewall",
            );
        }

        usort($out, static fn (WafServiceCountData $a, WafServiceCountData $b): int => $b->detected <=> $a->detected);

        return $out;
    }

    /**
     * Edges that serve routes without the firewall (docs/22 §6.6): a
     * refused config, or a launchroomd whose Caddy has no firewall module
     * while services ask for it.
     *
     * @return list<WafEdgeProblemData>
     */
    public function edges(int $teamId): array
    {
        $wanted = DB::table('service_instances')->where('team_id', $teamId)->whereNull('removed_at')->where('waf_mode', '!=', WafMode::Off->value)->exists();

        if (! $wanted) {
            return [];
        }

        $out = [];

        foreach (Server::query()->where('team_id', $teamId)->whereIn('status', [ServerStatus::Online->value, ServerStatus::Unreachable->value])->whereJsonContains('roles', ServerRole::Edge->value)->orderBy('name')->get() as $server) {
            $state = $server->waf_status['state'] ?? null;

            if ($state === 'error') {
                $out[] = new WafEdgeProblemData($server->name, $server->public_id, 'error', $server->waf_status['message'] ?? null);
            } elseif ($state === 'unsupported' || ! $server->supportsDaemonFeature(DaemonFeature::Waf)) {
                $out[] = new WafEdgeProblemData($server->name, $server->public_id, 'unsupported', null);
            }
        }

        return $out;
    }

    private function ruleSet(int $teamId): ?string
    {
        foreach (Server::query()->where('team_id', $teamId)->whereNotNull('waf_status')->get() as $server) {
            $label = $server->waf_status['ruleSet'] ?? null;

            if (is_string($label)) {
                return $label;
            }
        }

        return null;
    }

    /**
     * @return iterable<object>
     */
    private function rows(int $teamId, AnalyticsRange $range, CarbonImmutable $start, CarbonImmutable $end): iterable
    {
        return DB::table('waf_samples')
            ->whereIn('service_instance_id', DB::table('service_instances')->select('id')->where('team_id', $teamId))
            ->where('resolution', $range === AnalyticsRange::Month ? 60 : 10)
            ->where('at', '>=', $start->toIso8601String())
            ->where('at', '<', $end->toIso8601String())
            ->orderBy('at')
            ->get(['at', 'service_instance_id', 'blocked', 'detected', 'categories']);
    }

    /**
     * Splits $total over the categories in proportion to their counts
     * (largest remainders), so stacked bars sum to the requests.
     *
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    public static function apportion(int $total, array $counts): array
    {
        $sum = array_sum($counts);

        if ($total <= 0 || $sum <= 0) {
            return [];
        }

        if ($sum === $total) {
            return $counts;
        }

        $out = [];
        $remainders = [];

        foreach ($counts as $key => $count) {
            $exact = $total * $count / $sum;
            $out[$key] = (int) floor($exact);
            $remainders[$key] = $exact - $out[$key];
        }

        arsort($remainders);

        foreach (array_keys($remainders) as $key) {
            if (array_sum($out) >= $total) {
                break;
            }

            $out[$key]++;
        }

        return $out;
    }

    /**
     * "Allow requests like this" (docs/22 §6.4): the first excludable rule,
     * the path cut to its static prefix, the first target an exclusion can
     * name.
     *
     * @param  list<int>  $rules
     * @param  list<string>  $targets
     */
    public static function draft(array $rules, string $path, array $targets, string $serviceHref): ?WafExclusionDraftData
    {
        $rule = null;

        foreach ($rules as $candidate) {
            if ($candidate >= 911000 && $candidate <= 944999) {
                $rule = $candidate;

                break;
            }
        }

        if ($rule === null) {
            return null;
        }

        $prefix = '';

        foreach (explode('/', ltrim($path, '/')) as $segment) {
            if ($segment === '' || preg_match('/^[A-Za-z0-9._~-]+$/D', $segment) !== 1) {
                break;
            }

            $prefix .= '/'.$segment;
        }

        $prefix = $prefix === '' || strlen($prefix) > 128 ? null : $prefix;
        $target = null;

        foreach ($targets as $candidate) {
            $mapped = preg_replace('/^ARGS_(?:GET|POST):/', 'ARGS:', $candidate) ?? $candidate;

            if (preg_match('/^(?:(?:ARGS|REQUEST_COOKIES|REQUEST_HEADERS):[A-Za-z0-9_.-]{1,64}|ARGS_NAMES|REQUEST_COOKIES_NAMES|REQUEST_BODY|XML|FILES)$/D', $mapped) === 1) {
                $target = $mapped;

                break;
            }
        }

        return new WafExclusionDraftData($rule, $prefix, $target, "{$serviceHref}/settings/firewall", "{$serviceHref}/firewall/exclusions");
    }

    private static function seriesLabel(string $key): string
    {
        return match ($key) {
            'sqli' => 'SQL injection',
            'xss' => 'Cross-site scripting',
            'rce' => 'Code execution',
            'scanner' => 'Scanners',
            'detected' => 'Would have been blocked',
            default => 'Other',
        };
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function jsonRows(mixed $json): array
    {
        $value = is_string($json) ? json_decode($json, true) : null;

        return is_array($value) ? array_values(array_filter($value, is_array(...))) : [];
    }

    /**
     * @return list<mixed>
     */
    private function scalars(mixed $json): array
    {
        $value = is_string($json) ? json_decode($json, true) : null;

        return is_array($value) ? array_values($value) : [];
    }
}
