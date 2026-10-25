<?php

declare(strict_types=1);

namespace App\Queries\Security;

use App\Data\Security\FeedStateData;
use App\Data\Security\FindingCardData;
use App\Data\Security\FindingInvestigationData;
use App\Data\Security\RuntimeCardData;
use App\Data\Security\SecurityPageData;
use App\Data\Security\SecurityServiceRefData;
use App\Data\Security\SecuritySummaryData;
use App\Data\Security\WorthKnowingGroupData;
use App\Data\Security\WorthKnowingItemData;
use App\Data\Services\DatabaseSource;
use App\Enums\AnalyticsRange;
use App\Enums\EnvironmentKind;
use App\Enums\Permission;
use App\Enums\Security\AdvisoryFeed;
use App\Enums\Security\FindingLevel;
use App\Enums\Security\FindingReason;
use App\Enums\Security\InventoryStatus;
use App\Enums\Security\SecurityFindingStatus;
use App\Enums\Security\Severity;
use App\Enums\Security\WafMode;
use App\Models\Security\AdvisoryFeedState;
use App\Models\Security\SecurityFinding;
use App\Models\Security\SecurityInvestigation;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Models\User;
use App\Support\Metrics\Cast;
use App\Support\Security\InvestigationSummary;
use App\Support\Security\LifecycleProducts;
use App\Support\Security\VersionOrder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The Security page (docs/22 §9.2) for the current team: summary tiles,
 * "Action needed" cards, the web firewall section, "Worth knowing" groups
 * and the inventory overview (runtimes, engines, base images).
 */
final readonly class SecurityOverview
{
    /** Logo keys of tracked products. */
    private const array LOGOS = [
        'nodejs' => 'node', 'python' => 'python', 'php' => 'php', 'ruby' => 'ruby', 'go' => 'go', 'nginx' => 'nginx',
        'postgresql' => 'postgres', 'mysql' => 'mysql', 'mariadb' => 'mariadb', 'redis' => 'redis', 'valkey' => 'valkey',
        'mongodb' => 'mongodb', 'clickhouse' => 'clickhouse', 'debian' => 'debian', 'ubuntu' => 'ubuntu', 'alpine-linux' => null,
        'eclipse-temurin' => null, 'dotnet' => null,
    ];

    public function __construct(
        private WafOverview $waf,
    ) {}

    public function handle(Team $team, User $user, AnalyticsRange $range): SecurityPageData
    {
        $now = CarbonImmutable::now();
        $refs = new SecurityServiceRefs;
        $live = $this->liveInstances($team->id);
        $refs->load($team->id, array_keys($live));

        $feeds = AdvisorySources::all();

        $osv = AdvisoryFeedState::of(AdvisoryFeed::Osv);
        $wait = config()->integer('launchroom.security.check_now_minutes', 10);
        $nextCheck = $osv->manual_check_at?->addMinutes($wait);

        $findings = SecurityFinding::query()
            ->where('team_id', $team->id)
            ->whereIn('status', [SecurityFindingStatus::Open->value])
            ->get();
        $refs->load($team->id, array_values(array_unique(array_map(static fn (SecurityFinding $f): int => $f->service_instance_id, $findings->all()))));
        $titles = $this->titles(self::advisoryIds($findings->all()));
        $investigations = SecurityInvestigation::query()->whereKey($findings->pluck('investigation_id')->filter()->unique()->values()->all())->get()->keyBy('id');

        $cards = [];

        foreach ($findings->where('level', FindingLevel::ActionNeeded) as $finding) {
            $card = $this->card($finding, $refs, $titles, $investigations->get($finding->investigation_id));

            if ($card instanceof FindingCardData) {
                $cards[] = [$finding, $card];
            }
        }

        usort($cards, static fn (array $a, array $b): int => [$b[0]->known_exploited, $b[0]->severity->rank(), $a[0]->first_seen_at->getTimestamp()]
            <=> [$a[0]->known_exploited, $a[0]->severity->rank(), $b[0]->first_seen_at->getTimestamp()]);

        [$runtimes, $baseImages] = $this->runtimes($live, $now);
        $routed = $this->routedInstances($team->id);
        $firewall = $this->waf->handle($team, $range, $refs, Gate::forUser($user)->allows('viewClientNetworks', [SecurityFinding::class, $team]));
        $blockedSeries = array_fill(0, count($firewall->timestamps), 0);

        foreach ($firewall->series as $series) {
            if ($series->key !== 'detected') {
                foreach ($series->values as $i => $value) {
                    $blockedSeries[$i] = ($blockedSeries[$i] ?? 0) + $value;
                }
            }
        }

        $summary = new SecuritySummaryData(
            actionNeeded: count($cards),
            actionNeededServices: count(array_unique(array_map(static fn (array $c): int => $c[0]->service_instance_id, $cards))),
            packages: $this->packageCount($live),
            servicesChecked: count(array_filter($live, static fn (array $i): bool => $i['status'] === InventoryStatus::Ready->value)),
            pendingInventories: count(array_filter($live, static fn (array $i): bool => $i['status'] === null || $i['status'] === InventoryStatus::Pending->value)),
            blocked: $firewall->blocked,
            previousBlocked: $this->waf->previousBlocked($team, $range),
            blockedSeries: array_values($blockedSeries),
            protectedServices: count(array_filter($routed, static fn (string $mode): bool => $mode === WafMode::Block->value)),
            watchingServices: count(array_filter($routed, static fn (string $mode): bool => $mode === WafMode::Log->value)),
            routedServices: count($routed),
        );

        return new SecurityPageData(
            hasServices: $live !== [],
            checkedAt: $osv->last_success_at?->toIso8601ZuluString(),
            checksOff: ! $osv->enabled,
            feeds: $feeds,
            canCheckNow: Gate::forUser($user)->allows('checkNow', [SecurityFinding::class, $team]),
            nextCheckAt: $nextCheck !== null && $nextCheck->isFuture() ? $nextCheck->toIso8601ZuluString() : null,
            canManageSources: $user->is_instance_owner === true,
            canManage: $user->hasTeamPermission($team, Permission::ManageProjects),
            summary: $summary,
            actionNeeded: array_map(static fn (array $c): FindingCardData => $c[1], $cards),
            firewall: $firewall,
            worthKnowing: $this->worthKnowing($findings->where('level', FindingLevel::WorthKnowing)->all(), $refs, $titles, $runtimes, $baseImages),
            runtimes: $runtimes,
            baseImages: $baseImages,
            notices: $this->notices($team->id, $refs, $feeds, $now),
            dismissed: SecurityFinding::query()->where('team_id', $team->id)->where('status', SecurityFindingStatus::Dismissed->value)->count(),
        );
    }

    /**
     * @param  array<string, string>  $titles
     */
    public function card(SecurityFinding $finding, SecurityServiceRefs $refs, array $titles, ?SecurityInvestigation $investigation): ?FindingCardData
    {
        $service = $refs->get($finding->service_instance_id);

        if (! $service instanceof SecurityServiceRefData) {
            return null;
        }

        $title = null;

        foreach ($finding->advisory_ids as $id) {
            $title ??= $titles[$id] ?? null;
        }

        return new FindingCardData(
            id: $finding->public_id,
            href: '/security/findings/'.$finding->public_id,
            advisory: $finding->group_key,
            title: $title,
            severity: $finding->severity->value,
            cvss: $finding->cvss_score === null ? null : (float) $finding->cvss_score,
            knownExploited: $finding->known_exploited,
            knownExploitedSince: $finding->known_exploited_since?->toDateString(),
            public: $finding->public,
            ecosystem: $finding->ecosystem,
            package: $finding->name,
            version: $finding->version,
            fixedVersion: $finding->fixed_version,
            level: $finding->level->value,
            reasons: $finding->reasons,
            status: $finding->status->value,
            firstSeenAt: $finding->first_seen_at->toIso8601ZuluString(),
            service: $service,
            investigation: $investigation instanceof SecurityInvestigation ? new FindingInvestigationData(
                $investigation->public_id,
                $investigation->status->value,
                $investigation->started_at->toIso8601ZuluString(),
                $investigation->finished_at?->toIso8601ZuluString(),
                InvestigationSummary::sentences($investigation),
            ) : null,
        );
    }

    /**
     * Every advisory id of these findings.
     *
     * @param  array<array-key, SecurityFinding>  $findings
     * @return list<string>
     */
    public static function advisoryIds(array $findings): array
    {
        $ids = [];

        foreach ($findings as $finding) {
            foreach ($finding->advisory_ids as $id) {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * Advisory summaries by id.
     *
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    public function titles(array $ids): array
    {
        $out = [];

        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach (DB::table('advisories')->whereIn('id', $chunk)->whereNotNull('summary')->get(['id', 'summary']) as $row) {
                $out[Cast::string($row->id ?? null)] = Cast::string($row->summary ?? null);
            }
        }

        return $out;
    }

    /**
     * Live, non-preview instances with their inventory state.
     *
     * @return array<int, array{inventory: int|null, status: string|null, distro: array<array-key, mixed>|null}>
     */
    private function liveInstances(int $teamId): array
    {
        $rows = DB::table('service_instances as si')
            ->join('environments as e', 'e.id', '=', 'si.environment_id')
            ->join('deployments as d', 'd.id', '=', 'si.active_deployment_id')
            ->leftJoin('image_inventories as i', 'i.id', '=', 'd.inventory_id')
            ->where('si.team_id', $teamId)
            ->whereNull('si.removed_at')
            ->where('e.kind', '!=', EnvironmentKind::Preview->value)
            ->get(['si.id', 'i.id as inventory_id', 'i.status', 'i.distro']);

        $out = [];

        foreach ($rows as $row) {
            $distro = is_string($row->distro ?? null) ? json_decode($row->distro, true) : null;
            $out[Cast::id($row->id ?? null)] = [
                'inventory' => ($row->inventory_id ?? null) === null ? null : Cast::id($row->inventory_id),
                'status' => is_string($row->status ?? null) ? $row->status : null,
                'distro' => is_array($distro) ? $distro : null,
            ];
        }

        return $out;
    }

    /**
     * Routed (has a domain), non-preview instances → firewall mode.
     *
     * @return array<int, string>
     */
    private function routedInstances(int $teamId): array
    {
        $rows = DB::table('service_instances as si')
            ->join('environments as e', 'e.id', '=', 'si.environment_id')
            ->where('si.team_id', $teamId)
            ->whereNull('si.removed_at')
            ->whereNotNull('si.active_deployment_id')
            ->where('e.kind', '!=', EnvironmentKind::Preview->value)
            ->whereExists(static fn (Builder $q) => $q->selectRaw('1')->from('domains')->whereColumn('domains.service_instance_id', 'si.id')->whereIn('domains.kind', ['custom', 'generated']))
            ->get(['si.id', 'si.waf_mode']);

        $out = [];

        foreach ($rows as $row) {
            $out[Cast::id($row->id ?? null)] = Cast::string($row->waf_mode ?? null);
        }

        return $out;
    }

    /**
     * @param  array<int, array{inventory: int|null}>  $live
     */
    private function packageCount(array $live): int
    {
        $inventories = array_values(array_unique(array_filter(array_column($live, 'inventory'), is_int(...))));

        if ($inventories === []) {
            return 0;
        }

        return Cast::id(DB::table('inventory_components')->whereIn('inventory_id', $inventories)->selectRaw('count(DISTINCT (ecosystem, name, version)) as n')->value('n'));
    }

    /**
     * Runtimes and engines in use with their release cycle, then base
     * images (docs/22 §9.2 part 5, §4.6).
     *
     * @param  array<int, array{inventory: int|null, status: string|null, distro: array<array-key, mixed>|null}>  $live
     * @return array{list<RuntimeCardData>, list<RuntimeCardData>}
     */
    private function runtimes(array $live, CarbonImmutable $now): array
    {
        /** @var array<string, array{product: string, version: string, instances: array<int, true>, raw?: array<string, true>}> $runtimes */
        $runtimes = [];
        $byInventory = [];

        foreach ($live as $instanceId => $instance) {
            if ($instance['inventory'] !== null && $instance['status'] === InventoryStatus::Ready->value) {
                $byInventory[$instance['inventory']][] = $instanceId;
            }
        }

        if ($byInventory !== []) {
            $rows = DB::table('inventory_components')
                ->whereIn('inventory_id', array_keys($byInventory))
                ->where('kind', 'runtime')
                ->get(['inventory_id', 'ecosystem', 'name', 'version']);

            foreach ($rows as $row) {
                $product = LifecycleProducts::forRuntime(Cast::string($row->ecosystem ?? null), Cast::string($row->name ?? null));

                if ($product === null) {
                    continue;
                }

                $raw = Cast::string($row->version ?? null);
                $version = LifecycleProducts::upstreamVersion(Cast::string($row->ecosystem ?? null), $raw);
                $key = "{$product}@{$version}";
                $runtimes[$key] ??= ['product' => $product, 'version' => $version, 'instances' => []];

                if ($raw !== $version) {
                    $runtimes[$key]['raw'][$raw] = true;
                }

                foreach ($byInventory[Cast::id($row->inventory_id ?? null)] ?? [] as $instanceId) {
                    $runtimes[$key]['instances'][$instanceId] = true;
                }
            }
        }

        // Database engines from the service settings.
        foreach (ServiceInstance::query()->whereKey(array_keys($live))->with('service')->get() as $instance) {
            $source = $instance->service->source;

            if (! $source instanceof DatabaseSource) {
                continue;
            }

            $product = LifecycleProducts::forEngine($source->engine->value);

            if ($product !== null) {
                $key = "{$product}@{$source->version}";
                $runtimes[$key] ??= ['product' => $product, 'version' => $source->version, 'instances' => []];
                $runtimes[$key]['instances'][$instance->id] = true;
            }
        }

        $distros = [];

        foreach ($live as $instanceId => $instance) {
            $id = $instance['distro']['id'] ?? null;
            $product = is_string($id) ? LifecycleProducts::forDistro($id) : null;

            if ($product !== null) {
                $version = Cast::string($instance['distro']['versionId'] ?? '');
                $key = "{$product}@{$version}";
                $distros[$key] ??= ['product' => $product, 'version' => $version, 'instances' => []];
                $distros[$key]['instances'][$instanceId] = true;
            }
        }

        $releases = $this->releases([...array_values($runtimes), ...array_values($distros)]);

        $runtimeCards = [];

        foreach ($runtimes as $key => $entry) {
            $runtimeCards[] = $this->runtimeCard($key, $entry, $releases, $now);
        }

        $distroCards = [];

        foreach ($distros as $key => $entry) {
            $distroCards[] = $this->runtimeCard($key, $entry, $releases, $now);
        }

        usort($runtimeCards, static fn (RuntimeCardData $a, RuntimeCardData $b): int => [$b->services, $a->label] <=> [$a->services, $b->label]);
        usort($distroCards, static fn (RuntimeCardData $a, RuntimeCardData $b): int => [$b->services, $a->label] <=> [$a->services, $b->label]);

        return [$runtimeCards, $distroCards];
    }

    /**
     * @param  array{product: string, version: string, instances: array<int, true>, raw?: array<string, true>}  $entry
     * @param  array<string, array{latest: string|null, latest_date: string|null, release_date: string|null, eol: string|null, is_eol: bool}>  $releases
     */
    private function runtimeCard(string $key, array $entry, array $releases, CarbonImmutable $now): RuntimeCardData
    {
        $cycle = LifecycleProducts::cycle($entry['product'], $entry['version']);
        $release = $cycle === null ? null : ($releases["{$entry['product']}|{$cycle}"] ?? null);
        $latest = $release['latest'] ?? null;
        $patch = $latest !== null && $latest !== $entry['version'] && ($release['latest_date'] ?? null) !== null
            && CarbonImmutable::parse((string) $release['latest_date'])->lessThan($now->subDays(30))
            && (VersionOrder::compare($entry['version'], $latest) ?? 0) < 0;

        return new RuntimeCardData(
            key: $key,
            product: $entry['product'],
            label: LifecycleProducts::label($entry['product']),
            logo: self::LOGOS[$entry['product']] ?? null,
            version: $entry['version'],
            cycle: $cycle,
            services: count($entry['instances']),
            releaseDate: $release['release_date'] ?? null,
            eol: $release['eol'] ?? null,
            isEol: ($release['is_eol'] ?? false) === true || (($release['eol'] ?? null) !== null && CarbonImmutable::parse((string) $release['eol'])->isPast()),
            latest: $latest,
            patchAvailable: $patch,
            packageVersions: array_map(strval(...), array_keys($entry['raw'] ?? [])),
        );
    }

    /**
     * @param  list<array{product: string, version: string}>  $entries
     * @return array<string, array{latest: string|null, latest_date: string|null, release_date: string|null, eol: string|null, is_eol: bool}>
     */
    private function releases(array $entries): array
    {
        $keys = [];

        foreach ($entries as $entry) {
            $cycle = LifecycleProducts::cycle($entry['product'], $entry['version']);

            if ($cycle !== null) {
                $keys[] = [$entry['product'], $cycle];
            }
        }

        if ($keys === []) {
            return [];
        }

        $out = [];
        $query = DB::table('lifecycle_releases');

        foreach ($keys as [$product, $cycle]) {
            $query->orWhere(static fn (Builder $q) => $q->where('product', $product)->where('cycle', $cycle));
        }

        foreach ($query->get() as $row) {
            $out[Cast::string($row->product ?? null).'|'.Cast::string($row->cycle ?? null)] = [
                'latest' => is_string($row->latest ?? null) ? $row->latest : null,
                'latest_date' => is_string($row->latest_date ?? null) ? substr($row->latest_date, 0, 10) : null,
                'release_date' => is_string($row->release_date ?? null) ? substr($row->release_date, 0, 10) : null,
                'eol' => is_string($row->eol ?? null) ? substr($row->eol, 0, 10) : null,
                'is_eol' => (bool) ($row->is_eol ?? false),
            ];
        }

        return $out;
    }

    /**
     * "Worth knowing" (docs/22 §5.4, §9.2 part 4): version facts first, then
     * findings grouped by their first reason; inside a group one row per
     * package version and advisory, most severe first.
     *
     * @param  array<int, SecurityFinding>  $findings
     * @param  array<string, string>  $titles
     * @param  list<RuntimeCardData>  $runtimes
     * @param  list<RuntimeCardData>  $baseImages
     * @return list<WorthKnowingGroupData>
     */
    private function worthKnowing(array $findings, SecurityServiceRefs $refs, array $titles, array $runtimes, array $baseImages): array
    {
        $groups = [];
        $eol = [];
        $patch = [];

        foreach ([...$runtimes, ...$baseImages] as $card) {
            $label = "{$card->label} ".($card->cycle ?? $card->version);
            $used = $card->services === 1 ? 'used by 1 service' : "used by {$card->services} services";

            if ($card->isEol && $card->eol !== null) {
                $eol[] = new WorthKnowingItemData($card->key, $card->label, $card->version, 'runtime', null, null, Severity::Unknown->value, null, null, [], FindingReason::EndOfLife->value,
                    "{$label} reached its end of life on ".CarbonImmutable::parse($card->eol)->format('j F Y').", {$used}");
            } elseif ($card->patchAvailable && $card->latest !== null) {
                $patch[] = new WorthKnowingItemData($card->key, $card->label, $card->version, 'runtime', null, null, Severity::Unknown->value, $card->latest, null, [], FindingReason::PatchAvailable->value,
                    "{$card->label} {$card->latest} is available ({$card->version} in use), {$used}");
            }
        }

        if ($eol !== []) {
            $groups[] = new WorthKnowingGroupData(FindingReason::EndOfLife->value, FindingReason::EndOfLife->group(), count($eol), $eol);
        }

        if ($patch !== []) {
            $groups[] = new WorthKnowingGroupData(FindingReason::PatchAvailable->value, FindingReason::PatchAvailable->group(), count($patch), $patch);
        }

        /** @var array<string, array<string, array{finding: SecurityFinding, services: array<string, SecurityServiceRefData>}>> $byReason */
        $byReason = [];

        foreach ($findings as $finding) {
            $reason = $finding->reasons[0] ?? null;
            $service = $refs->get($finding->service_instance_id);

            if (! is_string($reason) || FindingReason::tryFrom($reason) === null || ! $service instanceof SecurityServiceRefData) {
                continue;
            }

            $key = "{$finding->ecosystem}|{$finding->name}|{$finding->version}|{$finding->group_key}";
            $byReason[$reason][$key] ??= ['finding' => $finding, 'services' => []];
            $byReason[$reason][$key]['services'][$service->id.$service->environmentId] = $service;
        }

        $order = array_map(static fn (FindingReason $r): string => $r->value, FindingReason::cases());

        uksort($byReason, static fn (string $a, string $b): int => array_search($a, $order, true) <=> array_search($b, $order, true));

        foreach ($byReason as $reason => $items) {
            uasort($items, static fn (array $a, array $b): int => [$b['finding']->severity->rank(), $a['finding']->name] <=> [$a['finding']->severity->rank(), $b['finding']->name]);
            $rows = [];

            foreach (array_slice($items, 0, 50, true) as $key => $item) {
                $f = $item['finding'];
                $title = null;

                foreach ($f->advisory_ids as $id) {
                    $title ??= $titles[$id] ?? null;
                }

                $rows[] = new WorthKnowingItemData(
                    key: $key,
                    package: $f->name,
                    version: $f->version,
                    ecosystem: $f->ecosystem,
                    advisory: $f->group_key,
                    title: $title,
                    severity: $f->severity->value,
                    fixedVersion: $f->fixed_version,
                    href: '/security/findings/'.$f->public_id,
                    services: array_values($item['services']),
                    reason: $reason,
                    text: FindingReason::from($reason)->label(),
                );
            }

            $groups[] = new WorthKnowingGroupData($reason, FindingReason::from($reason)->group(), count($items), $rows);
        }

        return $groups;
    }

    /**
     * Inventories that couldn't be read and sources failing for 3 days
     * (docs/22 §3.1, §4.4).
     *
     * @param  list<FeedStateData>  $feeds
     * @return list<string>
     */
    private function notices(int $teamId, SecurityServiceRefs $refs, array $feeds, CarbonImmutable $now): array
    {
        $out = [];

        foreach ($feeds as $feed) {
            if ($feed->enabled && $feed->failingSince !== null && CarbonImmutable::parse($feed->failingSince)->lessThan($now->subDays(3))) {
                $out[] = "Can’t reach {$feed->host} since ".CarbonImmutable::parse($feed->failingSince)->format('j M').'; its data may be out of date.';
            }
        }

        $failed = DB::table('image_inventories as i')
            ->join('deployments as d', 'd.inventory_id', '=', 'i.id')
            ->join('service_instances as si', 'si.active_deployment_id', '=', 'd.id')
            ->where('i.team_id', $teamId)
            ->where('i.status', InventoryStatus::Failed->value)
            ->where('i.retries', '>=', 2)
            ->get(['si.id', 'i.error']);

        $refs->load($teamId, array_values(array_map(static fn (object $row): int => Cast::id($row->id ?? null), $failed->all())));

        foreach ($failed as $row) {
            $service = $refs->get(Cast::id($row->id ?? null));

            if ($service instanceof SecurityServiceRefData) {
                $out[] = "Couldn’t read the packages of {$service->name} in {$service->environmentName}: ".rtrim(Cast::string($row->error ?? null), '.').'.';
            }
        }

        return array_values(array_unique($out));
    }
}
