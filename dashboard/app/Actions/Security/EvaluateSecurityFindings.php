<?php

declare(strict_types=1);

namespace App\Actions\Security;

use App\Actions\Alerts\ResolveAlert;
use App\Enums\Alerts\AlertRule;
use App\Enums\EnvironmentKind;
use App\Enums\Security\FindingLevel;
use App\Enums\Security\InventorySource;
use App\Enums\Security\SecurityFindingStatus;
use App\Enums\Security\Severity;
use App\Models\Security\SecurityFinding;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Support\Metrics\Cast;
use App\Support\Security\FindingFacts;
use App\Support\Security\FindingRules;
use App\Support\Security\Lockfiles;
use App\Support\Security\VersionOrder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes the findings of one team (docs/22 §5): every live deployment
 * of a non-preview environment whose image has a ready inventory, every
 * component whose OSV query matched advisories, one finding per (service
 * instance, advisory group, component version), its level and reasons from
 * FindingRules.
 *
 * Lifecycle (§5.5): new matches open a finding, a resolved one with the
 * same key reopens (a rollback), findings no longer matched resolve,
 * dismissed ones reopen when their level rises to "Action needed" or the
 * advisory becomes known exploited. Missing data never resolves anything:
 * withdrawn advisories and versions that left the live image do.
 *
 * Findings that newly became "Action needed" are investigated once
 * automatically; their alert is raised when the investigation finishes
 * (so the notification carries its result), or right away when no
 * investigation can run. Alerts of instances without an open "Action
 * needed" finding resolve. Idempotent; one team at a time under an
 * advisory lock.
 */
final readonly class EvaluateSecurityFindings
{
    /** Minutes an automatic investigation may hold back a finding's alert. */
    public const int ALERT_WAIT_MINUTES = 10;

    public function __construct(
        private StartInvestigation $investigate,
        private RaiseVulnerabilityAlert $alert,
        private ResolveAlert $resolve,
    ) {}

    /**
     * @return array{open: int, actionNeeded: int, resolved: int}
     */
    public function handle(Team|int $team): array
    {
        $teamId = $team instanceof Team ? $team->id : $team;
        $now = CarbonImmutable::now();

        [$counts, $escalated] = DB::transaction(function () use ($teamId, $now): array {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["security-evaluate|{$teamId}"]);

            return $this->evaluate($teamId, $now);
        });

        $this->afterEvaluation($teamId, $escalated, $now);

        return $counts;
    }

    /**
     * @return array{array{open: int, actionNeeded: int, resolved: int}, list<int>}
     */
    private function evaluate(int $teamId, CarbonImmutable $now): array
    {
        $instances = $this->liveInstances($teamId);
        $existing = SecurityFinding::query()
            ->where('team_id', $teamId)
            ->whereIn('status', [SecurityFindingStatus::Open->value, SecurityFindingStatus::Dismissed->value])
            ->lockForUpdate()
            ->get()
            ->keyBy(static fn (SecurityFinding $f): string => self::key($f->service_instance_id, $f->group_key, $f->ecosystem, $f->name, $f->version));

        $seen = [];
        $escalated = [];

        if ($instances !== []) {
            $inventories = array_values(array_unique(array_column($instances, 'inventory_id')));
            $matches = $this->matches($inventories);

            // Missing data changes nothing (docs/22 §4.4): a version still in
            // the live image whose OSV answer is missing or failed keeps its findings.
            foreach ($this->unchecked($inventories) as $inventoryId => $keys) {
                foreach ($instances as $instanceId => $instance) {
                    if ($instance['inventory_id'] === $inventoryId) {
                        foreach ($existing as $key => $finding) {
                            if ($finding->service_instance_id === $instanceId && isset($keys[$finding->ecosystem."\0".$finding->name."\0".$finding->version])) {
                                $seen[$key] = true;
                            }
                        }
                    }
                }
            }
            $advisories = $this->advisories($matches);
            $kev = $this->knownExploited($advisories);
            $domains = $this->publicDomains(array_keys($instances));

            foreach ($instances as $instanceId => $instance) {
                foreach ($matches[$instance['inventory_id']] ?? [] as $component) {
                    foreach ($this->groups($component, $advisories) as $groupKey => $group) {
                        $key = self::key($instanceId, $groupKey, $component['ecosystem'], $component['name'], $component['version']);
                        $seen[$key] = true;
                        $finding = $this->upsert($existing->get($key), $instance, $component, $groupKey, $group, $kev, $domains[$instanceId] ?? null, $now);

                        if ($finding !== null) {
                            $escalated[] = $finding;
                        }
                    }
                }
            }
        }

        $resolved = 0;

        foreach ($existing as $key => $finding) {
            if (! isset($seen[$key])) {
                $finding->transitionTo(SecurityFindingStatus::Resolved);
                $resolved++;
            }
        }

        $open = SecurityFinding::query()->where('team_id', $teamId)->where('status', SecurityFindingStatus::Open->value);

        return [[
            'open' => (clone $open)->count(),
            'actionNeeded' => (clone $open)->where('level', FindingLevel::ActionNeeded->value)->count(),
            'resolved' => $resolved,
        ], $escalated];
    }

    /**
     * Creates, reopens or refreshes one finding. Returns its id when it
     * newly became an open "Action needed" finding.
     *
     * @param  array{instance: int, project: int, environment: int, deployment: int, inventory_id: int, built: bool, environment_kind: string, lockfiles: list<array{path: string, kind: string}>}  $instance
     * @param  array{ecosystem: string, name: string, version: string, kind: string, scope: string, presence: string, direct: bool, osv_ecosystem: string, osv_name: string, ids: list<string>}  $component
     * @param  array{ids: list<string>, severity: Severity, score: float|null, vector: string|null, fixed: list<string>, cves: list<string>}  $group
     * @param  array<string, string>  $kev  CVE → date added
     */
    private function upsert(?SecurityFinding $finding, array $instance, array $component, string $groupKey, array $group, array $kev, ?string $domain, CarbonImmutable $now): ?int
    {
        $kevDates = array_values(array_filter(array_map(static fn (string $cve): ?string => $kev[$cve] ?? null, $group['cves'])));
        sort($kevDates);
        $known = $kevDates !== [];
        $fixedVersion = VersionOrder::nextFixed($component['ecosystem'], $component['version'], $group['fixed'])
            ?? (count($group['fixed']) === 1 ? $group['fixed'][0] : null);

        $lockfile = Lockfiles::evidence($component['ecosystem'], $component['kind'], $instance['lockfiles']);
        $verdict = FindingRules::evaluate(new FindingFacts(
            ecosystem: $component['ecosystem'],
            name: $component['name'],
            kind: $component['kind'],
            scope: $component['scope'],
            presence: $component['presence'],
            builtImage: $instance['built'],
            preview: $instance['environment_kind'] === EnvironmentKind::Preview->value,
            public: $domain !== null,
            severity: $group['severity'],
            cvssScore: $group['score'],
            fixed: $group['fixed'] !== [],
            attackVector: $group['vector'],
            knownExploited: $known,
            fixedVersion: $fixedVersion,
            domain: $domain,
            lockfile: $lockfile !== null ? basename($lockfile) : null,
        ));

        $attributes = [
            'project_id' => $instance['project'],
            'environment_id' => $instance['environment'],
            'deployment_id' => $instance['deployment'],
            'inventory_id' => $instance['inventory_id'],
            'advisory_ids' => $group['ids'],
            'kind' => $component['kind'],
            'scope' => $component['scope'],
            'presence' => $component['presence'],
            'direct' => $component['direct'],
            'level' => $verdict->level,
            'reasons' => $verdict->reasonCodes(),
            'checks' => $verdict->checks,
            'attack_vector' => $group['vector'],
            'severity' => $group['severity'],
            'cvss_score' => $group['score'],
            'fixed_version' => $fixedVersion,
            'known_exploited' => $known,
            'known_exploited_since' => $kevDates[0] ?? null,
            'public' => $domain !== null,
            'last_seen_at' => $now,
        ];

        if (! $finding instanceof SecurityFinding) {
            // A version that comes back (a rollback) reopens its old finding.
            $finding = SecurityFinding::query()
                ->where('service_instance_id', $instance['instance'])
                ->where('group_key', $groupKey)
                ->where('ecosystem', $component['ecosystem'])
                ->where('name', $component['name'])
                ->where('version', $component['version'])
                ->where('status', SecurityFindingStatus::Resolved->value)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($finding instanceof SecurityFinding) {
                $finding->fill([...$attributes, 'level_changed_at' => $now]);
                $finding->transitionTo(SecurityFindingStatus::Open);

                return $verdict->level === FindingLevel::ActionNeeded ? $finding->id : null;
            }

            $finding = SecurityFinding::query()->create([
                ...$attributes,
                'team_id' => $this->teamOf($instance['instance']),
                'service_instance_id' => $instance['instance'],
                'group_key' => $groupKey,
                'ecosystem' => $component['ecosystem'],
                'name' => $component['name'],
                'version' => $component['version'],
                'first_seen_at' => $now,
                'level_changed_at' => $now,
            ]);

            return $verdict->level === FindingLevel::ActionNeeded ? $finding->id : null;
        }

        $rose = $finding->level === FindingLevel::WorthKnowing && $verdict->level === FindingLevel::ActionNeeded;
        $becameKnown = ! $finding->known_exploited && $known;

        $finding->fill([...$attributes, ...($finding->level !== $verdict->level ? ['level_changed_at' => $now] : [])]);

        // A dismissal holds until the finding gets more urgent (§5.5).
        if ($finding->status === SecurityFindingStatus::Dismissed && ($rose || ($becameKnown && $verdict->level === FindingLevel::ActionNeeded))) {
            $finding->forceFill(['level_changed_at' => $now, 'notified_at' => null]);
            $finding->transitionTo(SecurityFindingStatus::Open);

            return $finding->id;
        }

        $finding->save();

        return $rose && $finding->status === SecurityFindingStatus::Open ? $finding->id : null;
    }

    /**
     * Automatic investigations, alerts and resolutions after commit.
     *
     * @param  list<int>  $escalated
     */
    private function afterEvaluation(int $teamId, array $escalated, CarbonImmutable $now): void
    {
        $byInstance = [];

        foreach (SecurityFinding::query()->whereKey($escalated)->get() as $finding) {
            $byInstance[$finding->service_instance_id][] = $finding;
        }

        foreach ($byInstance as $instanceId => $findings) {
            $instance = ServiceInstance::query()->where('team_id', $teamId)->whereKey($instanceId)->first();

            if (! $instance instanceof ServiceInstance) {
                continue;
            }

            // Once, automatically, so the notification and the page carry results (§7.1).
            $investigation = $this->investigate->handle($instance, $findings[0], null, automatic: true)?->refresh();

            if ($investigation !== null) {
                SecurityFinding::query()->whereKey(array_map(static fn (SecurityFinding $f): int => $f->id, $findings))->whereNull('investigation_id')->update(['investigation_id' => $investigation->id]);
            }

            if ($investigation === null || $investigation->status->isTerminal()) {
                foreach ($findings as $finding) {
                    $this->alert->handle($finding->refresh());
                }
            }
        }

        // Findings whose investigation never finished in time still notify.
        $waiting = SecurityFinding::query()
            ->where('team_id', $teamId)
            ->where('status', SecurityFindingStatus::Open->value)
            ->where('level', FindingLevel::ActionNeeded->value)
            ->whereNull('notified_at')
            ->where('level_changed_at', '<=', $now->subMinutes(self::ALERT_WAIT_MINUTES))
            ->get();

        foreach ($waiting as $finding) {
            $this->alert->handle($finding);
        }

        // Alerts of instances without an open "Action needed" finding resolve.
        $urgent = SecurityFinding::query()
            ->where('team_id', $teamId)
            ->where('status', SecurityFindingStatus::Open->value)
            ->where('level', FindingLevel::ActionNeeded->value)
            ->distinct()
            ->pluck('service_instance_id')
            ->all();

        $firing = DB::table('alerts')
            ->where('team_id', $teamId)
            ->where('rule', AlertRule::Vulnerability->value)
            ->where('status', 'firing')
            ->pluck('service_instance_id')
            ->all();

        foreach (array_diff(array_map(Cast::id(...), $firing), array_map(Cast::id(...), $urgent)) as $instanceId) {
            $publicId = ServiceInstance::query()->whereKey($instanceId)->value('public_id');

            if (is_string($publicId)) {
                $this->resolve->handle($teamId, AlertRule::Vulnerability, 'security:'.$publicId, 'No open finding needs action anymore.');
            }
        }
    }

    /**
     * Live, non-preview service instances whose image has a ready inventory.
     *
     * @return array<int, array{instance: int, project: int, environment: int, deployment: int, inventory_id: int, built: bool, environment_kind: string, lockfiles: list<array{path: string, kind: string}>}>
     */
    private function liveInstances(int $teamId): array
    {
        $rows = DB::table('service_instances as si')
            ->join('environments as e', 'e.id', '=', 'si.environment_id')
            ->join('deployments as d', 'd.id', '=', 'si.active_deployment_id')
            ->join('image_inventories as i', 'i.id', '=', 'd.inventory_id')
            ->where('si.team_id', $teamId)
            ->whereNull('si.removed_at')
            ->where('e.kind', '!=', EnvironmentKind::Preview->value)
            ->where('i.status', 'ready')
            ->where('i.team_id', $teamId)
            ->get(['si.id', 'e.project_id', 'e.id as environment_id', 'e.kind', 'd.id as deployment_id', 'i.id as inventory_id', 'i.source', 'i.lockfiles']);

        $out = [];

        foreach ($rows as $row) {
            $lockfiles = json_decode(Cast::string($row->lockfiles ?? null), true);
            $out[Cast::id($row->id ?? null)] = [
                'instance' => Cast::id($row->id ?? null),
                'project' => Cast::id($row->project_id ?? null),
                'environment' => Cast::id($row->environment_id ?? null),
                'deployment' => Cast::id($row->deployment_id ?? null),
                'inventory_id' => Cast::id($row->inventory_id ?? null),
                'built' => ($row->source ?? null) === InventorySource::Built->value,
                'environment_kind' => Cast::string($row->kind ?? null),
                'lockfiles' => is_array($lockfiles) ? array_values(array_filter($lockfiles, static fn (mixed $l): bool => is_array($l) && is_string($l['path'] ?? null) && is_string($l['kind'] ?? null))) : [],
            ];
        }

        return $out;
    }

    /**
     * Components with matched advisory ids, per inventory.
     *
     * @param  list<int>  $inventoryIds
     * @return array<int, list<array{ecosystem: string, name: string, version: string, kind: string, scope: string, presence: string, direct: bool, osv_ecosystem: string, osv_name: string, ids: list<string>}>>
     */
    private function matches(array $inventoryIds): array
    {
        $rows = DB::table('inventory_components as c')
            ->join('advisory_queries as q', static function (JoinClause $join): void {
                $join->on('q.osv_ecosystem', '=', 'c.osv_ecosystem')->on('q.osv_name', '=', 'c.osv_name')->on('q.version', '=', 'c.osv_version');
            })
            ->whereIn('c.inventory_id', $inventoryIds)
            ->whereRaw('jsonb_array_length(q.advisory_ids) > 0')
            ->get(['c.inventory_id', 'c.ecosystem', 'c.name', 'c.version', 'c.kind', 'c.scope', 'c.presence', 'c.direct', 'c.osv_ecosystem', 'c.osv_name', 'q.advisory_ids']);

        $out = [];

        foreach ($rows as $row) {
            $ids = json_decode(Cast::string($row->advisory_ids ?? null), true);
            $out[Cast::id($row->inventory_id ?? null)][] = [
                'ecosystem' => Cast::string($row->ecosystem ?? null),
                'name' => Cast::string($row->name ?? null),
                'version' => Cast::string($row->version ?? null),
                'kind' => Cast::string($row->kind ?? null),
                'scope' => Cast::string($row->scope ?? null),
                'presence' => Cast::string($row->presence ?? null),
                'direct' => (bool) ($row->direct ?? false),
                'osv_ecosystem' => Cast::string($row->osv_ecosystem ?? null),
                'osv_name' => Cast::string($row->osv_name ?? null),
                'ids' => is_array($ids) ? array_values(array_filter($ids, is_string(...))) : [],
            ];
        }

        return $out;
    }

    /**
     * Components whose OSV answer is missing or failed, per inventory.
     *
     * @param  list<int>  $inventoryIds
     * @return array<int, array<string, true>>
     */
    private function unchecked(array $inventoryIds): array
    {
        $rows = DB::table('inventory_components as c')
            ->leftJoin('advisory_queries as q', static function (JoinClause $join): void {
                $join->on('q.osv_ecosystem', '=', 'c.osv_ecosystem')->on('q.osv_name', '=', 'c.osv_name')->on('q.version', '=', 'c.osv_version');
            })
            ->whereIn('c.inventory_id', $inventoryIds)
            ->whereNotNull('c.osv_ecosystem')
            ->where(static fn (Builder $q) => $q->whereNull('q.checked_at')->orWhereNotNull('q.error'))
            ->get(['c.inventory_id', 'c.ecosystem', 'c.name', 'c.version']);

        $out = [];

        foreach ($rows as $row) {
            $out[Cast::id($row->inventory_id ?? null)][Cast::string($row->ecosystem ?? null)."\0".Cast::string($row->name ?? null)."\0".Cast::string($row->version ?? null)] = true;
        }

        return $out;
    }

    /**
     * Matched advisory records that aren't withdrawn.
     *
     * @param  array<int, list<array{ids: list<string>}>>  $matches
     * @return array<string, array{group: string, label: string|null, score: float|null, vector: string|null, cves: list<string>, affected: list<array<array-key, mixed>>}>
     */
    private function advisories(array $matches): array
    {
        $ids = [];

        foreach ($matches as $components) {
            foreach ($components as $component) {
                foreach ($component['ids'] as $id) {
                    $ids[$id] = true;
                }
            }
        }

        $out = [];

        foreach (array_chunk(array_keys($ids), 1000) as $chunk) {
            $rows = DB::table('advisories')->whereIn('id', $chunk)->whereNull('withdrawn')
                ->get(['id', 'group_key', 'severity_label', 'cvss', 'attack_vector', 'cve_ids', 'affected']);

            foreach ($rows as $row) {
                $cvss = json_decode(Cast::string($row->cvss ?? null), true);
                $cves = json_decode(Cast::string($row->cve_ids ?? null), true);
                $affected = json_decode(Cast::string($row->affected ?? null), true);
                $out[Cast::string($row->id ?? null)] = [
                    'group' => Cast::string($row->group_key ?? null),
                    'label' => is_string($row->severity_label ?? null) ? $row->severity_label : null,
                    'score' => is_array($cvss) && is_numeric($cvss['v3Score'] ?? null) ? (float) $cvss['v3Score'] : null,
                    'vector' => is_string($row->attack_vector ?? null) ? $row->attack_vector : null,
                    'cves' => is_array($cves) ? array_values(array_filter($cves, is_string(...))) : [],
                    'affected' => is_array($affected) ? array_values(array_filter($affected, is_array(...))) : [],
                ];
            }
        }

        return $out;
    }

    /**
     * The component's matched advisories by group: severity (GHSA label
     * first, else CVSS v3), the highest score, the vector of the most
     * severe record, the fixed versions of the matched package and CVEs.
     *
     * @param  array{ecosystem: string, name: string, version: string, osv_ecosystem: string, osv_name: string, ids: list<string>}  $component
     * @param  array<string, array{group: string, label: string|null, score: float|null, vector: string|null, cves: list<string>, affected: list<array<array-key, mixed>>}>  $advisories
     * @return array<string, array{ids: list<string>, severity: Severity, score: float|null, vector: string|null, fixed: list<string>, cves: list<string>}>
     */
    private function groups(array $component, array $advisories): array
    {
        $groups = [];

        foreach ($component['ids'] as $id) {
            $advisory = $advisories[$id] ?? null;

            if ($advisory === null) {
                continue;
            }

            $severity = Severity::fromLabel($advisory['label']) ?? Severity::fromScore($advisory['score']);
            $group = $groups[$advisory['group']] ?? ['ids' => [], 'severity' => Severity::Unknown, 'score' => null, 'vector' => null, 'fixed' => [], 'cves' => [], 'rank' => -1];
            $group['ids'][] = $id;
            $group['cves'] = array_values(array_unique([...$group['cves'], ...$advisory['cves']]));

            if ($severity->rank() > $group['rank'] || ($severity->rank() === $group['rank'] && ($advisory['score'] ?? 0) > ($group['score'] ?? 0))) {
                $group['severity'] = $severity;
                $group['rank'] = $severity->rank();
                $group['vector'] = $advisory['vector'];
            }

            if ($advisory['score'] !== null && ($group['score'] === null || $advisory['score'] > $group['score'])) {
                $group['score'] = $advisory['score'];
            }

            foreach ($advisory['affected'] as $affected) {
                if (($affected['ecosystem'] ?? null) === $component['osv_ecosystem'] && ($affected['name'] ?? null) === $component['osv_name'] && is_array($affected['fixed'] ?? null)) {
                    $group['fixed'] = array_values(array_unique([...$group['fixed'], ...array_values(array_filter($affected['fixed'], is_string(...)))]));
                }
            }

            $groups[$advisory['group']] = $group;
        }

        return array_map(static fn (array $group): array => [
            'ids' => $group['ids'],
            'severity' => $group['severity'],
            'score' => $group['score'],
            'vector' => $group['vector'],
            'fixed' => $group['fixed'],
            'cves' => $group['cves'],
        ], $groups);
    }

    /**
     * @param  array<string, array{cves: list<string>}>  $advisories
     * @return array<string, string> CVE → date added
     */
    private function knownExploited(array $advisories): array
    {
        $cves = [];

        foreach ($advisories as $advisory) {
            foreach ($advisory['cves'] as $cve) {
                $cves[$cve] = true;
            }
        }

        $out = [];

        foreach (array_chunk(array_keys($cves), 1000) as $chunk) {
            foreach (DB::table('known_exploited')->whereIn('cve_id', $chunk)->get(['cve_id', 'date_added']) as $row) {
                $out[Cast::string($row->cve_id ?? null)] = substr(Cast::string($row->date_added ?? null), 0, 10);
            }
        }

        return $out;
    }

    /**
     * A public hostname per instance: generated and custom domains (custom
     * first). Previews aren't evaluated, so no route here is protected.
     *
     * @param  list<int>  $instanceIds
     * @return array<int, string>
     */
    private function publicDomains(array $instanceIds): array
    {
        $out = [];

        $rows = DB::table('domains')
            ->whereIn('service_instance_id', $instanceIds)
            ->whereIn('kind', ['custom', 'generated'])
            ->orderByRaw("CASE kind WHEN 'custom' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->get(['service_instance_id', 'hostname']);

        foreach ($rows as $row) {
            $out[Cast::id($row->service_instance_id ?? null)] ??= Cast::string($row->hostname ?? null);
        }

        return $out;
    }

    private function teamOf(int $instanceId): int
    {
        return Cast::id(ServiceInstance::query()->whereKey($instanceId)->value('team_id'));
    }

    private static function key(int $instance, string $group, string $ecosystem, string $name, string $version): string
    {
        return implode("\0", [$instance, $group, $ecosystem, $name, $version]);
    }
}
