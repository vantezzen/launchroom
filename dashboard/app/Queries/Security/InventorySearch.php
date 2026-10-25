<?php

declare(strict_types=1);

namespace App\Queries\Security;

use App\Data\Security\FindingCardData;
use App\Data\Security\InventoryPackageData;
use App\Data\Security\InventorySearchData;
use App\Data\Security\InventoryUseData;
use App\Enums\EnvironmentKind;
use App\Enums\Security\InventoryStatus;
use App\Enums\Security\SecurityFindingStatus;
use App\Models\Security\SecurityFinding;
use App\Models\Team;
use App\Support\Metrics\Cast;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * "Search packages and runtimes" (docs/22 §9.2 part 5): words match name
 * substrings, a version-like token filters versions by prefix,
 * `ecosystem:npm`, `service:api` and `scope:runtime` filter. Results are
 * grouped by package name, then version, with the services running it and
 * their open findings. Live, non-preview deployments of the team only.
 */
final readonly class InventorySearch
{
    private const int LIMIT = 400;

    public function __construct(
        private SecurityOverview $overview,
    ) {}

    public function handle(Team $team, string $query): InventorySearchData
    {
        $query = mb_substr(trim($query), 0, 200);
        $filters = self::parse($query);

        if ($filters['words'] === [] && $filters['ecosystem'] === null && $filters['service'] === null && $filters['scope'] === null) {
            return new InventorySearchData($query, [], false);
        }

        $rows = DB::table('inventory_components as c')
            ->join('image_inventories as i', 'i.id', '=', 'c.inventory_id')
            ->join('deployments as d', 'd.inventory_id', '=', 'i.id')
            ->join('service_instances as si', 'si.active_deployment_id', '=', 'd.id')
            ->join('services as s', 's.id', '=', 'si.service_id')
            ->join('environments as e', 'e.id', '=', 'si.environment_id')
            ->where('i.team_id', $team->id)
            ->where('si.team_id', $team->id)
            ->where('i.status', InventoryStatus::Ready->value)
            ->whereNull('si.removed_at')
            ->where('e.kind', '!=', EnvironmentKind::Preview->value)
            ->when($filters['ecosystem'] !== null, static fn (Builder $q) => $q->where('c.ecosystem', $filters['ecosystem']))
            ->when($filters['scope'] !== null, static fn (Builder $q) => $q->where('c.scope', $filters['scope']))
            ->when($filters['service'] !== null, static fn (Builder $q) => $q->whereRaw('lower(s.name) LIKE ?', ['%'.self::like((string) $filters['service']).'%']))
            ->when($filters['version'] !== null, static fn (Builder $q) => $q->where(static fn (Builder $v) => $v->where('c.version', 'like', self::like((string) $filters['version']).'%')->orWhere('c.version', 'like', 'v'.self::like((string) $filters['version']).'%')))
            ->orderBy('c.name')
            ->orderBy('c.version')
            ->limit(self::LIMIT + 1)
            ->select(['c.ecosystem', 'c.name', 'c.version', 'c.scope', 'c.kind', 'si.id as instance_id']);

        foreach ($filters['words'] as $word) {
            $rows->whereRaw('lower(c.name) LIKE ?', ['%'.self::like($word).'%']);
        }

        $results = $rows->get();
        $truncated = $results->count() > self::LIMIT;
        $results = $results->take(self::LIMIT);

        $refs = new SecurityServiceRefs;
        $refs->load($team->id, array_values(array_unique(array_map(static fn (object $r): int => Cast::id($r->instance_id ?? null), $results->all()))));

        $findings = SecurityFinding::query()
            ->where('team_id', $team->id)
            ->where('status', SecurityFindingStatus::Open->value)
            ->whereIn('name', $results->pluck('name')->unique()->values()->all())
            ->get();
        $titles = $this->overview->titles(SecurityOverview::advisoryIds($findings->all()));
        $rank = ['runtime' => 3, 'unknown' => 2, 'client' => 1, 'dev' => 0];

        /** @var array<string, array{name: string, ecosystem: string, versions: array<string, array{scope: string, kind: string, services: array<int, true>}>}> $packages */
        $packages = [];

        foreach ($results as $row) {
            $name = Cast::string($row->name ?? null);
            $ecosystem = Cast::string($row->ecosystem ?? null);
            $version = Cast::string($row->version ?? null);
            $scope = Cast::string($row->scope ?? null);
            $key = "{$ecosystem}|{$name}";
            $packages[$key] ??= ['name' => $name, 'ecosystem' => $ecosystem, 'versions' => []];
            $packages[$key]['versions'][$version] ??= ['scope' => $scope, 'kind' => Cast::string($row->kind ?? null), 'services' => []];

            if (($rank[$scope] ?? 0) > ($rank[$packages[$key]['versions'][$version]['scope']] ?? 0)) {
                $packages[$key]['versions'][$version]['scope'] = $scope;
            }

            $packages[$key]['versions'][$version]['services'][Cast::id($row->instance_id ?? null)] = true;
        }

        $out = [];

        foreach ($packages as $package) {
            $versions = [];

            foreach ($package['versions'] as $version => $use) {
                $cards = [];

                foreach ($findings as $finding) {
                    if ($finding->name === $package['name'] && $finding->version === (string) $version && $finding->ecosystem === $package['ecosystem']) {
                        $card = $this->overview->card($finding, $refs, $titles, null);

                        if ($card instanceof FindingCardData) {
                            $cards[$finding->group_key] = $card;
                        }
                    }
                }

                $versions[] = new InventoryUseData(
                    (string) $version,
                    $use['scope'],
                    $use['kind'],
                    array_values(array_filter(array_map($refs->get(...), array_keys($use['services'])))),
                    array_values($cards),
                );
            }

            $out[] = new InventoryPackageData($package['name'], $package['ecosystem'], $versions);
        }

        return new InventorySearchData($query, $out, $truncated);
    }

    /**
     * @return array{words: list<string>, version: string|null, ecosystem: string|null, service: string|null, scope: string|null}
     */
    public static function parse(string $query): array
    {
        $out = ['words' => [], 'version' => null, 'ecosystem' => null, 'service' => null, 'scope' => null];

        foreach (preg_split('/[\s,]+/', mb_strtolower($query)) ?: [] as $token) {
            if ($token === '') {
                continue;
            }

            if (preg_match('/^(ecosystem|service|scope):(.+)$/', $token, $m) === 1) {
                $out[$m[1]] = mb_substr($m[2], 0, 64);

                continue;
            }

            if (preg_match('/^v?\d+(?:\.\d+){0,3}[a-z0-9.+~-]*$/', $token) === 1) {
                $out['version'] = ltrim($token, 'v');

                continue;
            }

            if (count($out['words']) < 5) {
                $out['words'][] = mb_substr($token, 0, 64);
            }
        }

        return $out;
    }

    private static function like(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }
}
