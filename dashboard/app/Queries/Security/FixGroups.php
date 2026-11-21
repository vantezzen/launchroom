<?php

declare(strict_types=1);

namespace App\Queries\Security;

use App\Data\Security\FindingInvestigationData;
use App\Data\Security\FixGroupAdvisoryData;
use App\Data\Security\FixGroupData;
use App\Data\Security\FixGroupServiceData;
use App\Data\Security\SecurityServiceRefData;
use App\Enums\EnvironmentKind;
use App\Enums\Security\Severity;
use App\Models\Security\SecurityFinding;
use App\Models\Security\SecurityInvestigation;
use App\Support\Metrics\Cast;
use App\Support\Security\FixGroup;
use App\Support\Security\InvestigationSummary;
use Illuminate\Support\Facades\DB;

/**
 * "Action needed" grouped by what to fix (docs/22 §5.6, D107): one group per
 * package (ecosystem and name) with every open "Action needed" finding of
 * it, because one update fixes all its advisories in every service. Groups
 * are ordered by priority: known exploited, then a production service
 * affected, then severity, then the number of services, then the package
 * name (stable across refetches). The sidebar badge, the attention list,
 * the hero, the finding's previous/next and the alert all count and order
 * these groups.
 */
final readonly class FixGroups
{
    /**
     * Groups of the team, optionally only for some service instances.
     *
     * @param  list<int>|null  $instances
     * @return list<FixGroupData>
     */
    public function handle(int $teamId, SecurityServiceRefs $refs, ?array $instances = null): array
    {
        $findings = $this->open($teamId, $instances);

        if ($findings === []) {
            return [];
        }

        $instanceIds = array_values(array_unique(array_map(static fn (SecurityFinding $f): int => $f->service_instance_id, $findings)));
        $refs->load($teamId, $instanceIds);
        $production = $this->productionEnvironments($findings);
        $advisories = $this->advisories($findings);
        $investigations = [];

        foreach (SecurityInvestigation::query()->where('team_id', $teamId)->whereKey(array_values(array_unique(array_filter(array_map(static fn (SecurityFinding $f): ?int => $f->investigation_id, $findings)))))->get() as $investigation) {
            $investigations[$investigation->id] = $investigation;
        }

        $byPackage = [];

        foreach ($findings as $finding) {
            if ($refs->get($finding->service_instance_id) instanceof SecurityServiceRefData) {
                $byPackage[$finding->ecosystem."\0".$finding->name][] = $finding;
            }
        }

        $groups = [];

        foreach ($byPackage as $members) {
            $groups[] = $this->group($members, $refs, $production, $advisories, $investigations);
        }

        usort($groups, static fn (FixGroupData $a, FixGroupData $b): int => [$b->knownExploited, $b->production, $b->severity->rank(), count($b->services), $a->package, $a->key]
            <=> [$a->knownExploited, $a->production, $a->severity->rank(), count($a->services), $b->package, $b->key]);

        return $groups;
    }

    /**
     * Number of things to fix: open "Action needed" packages of the team
     * (the sidebar badge and the attention list, docs/22 §9.1).
     */
    public static function count(int $teamId): int
    {
        return Cast::id(SecurityFinding::query()
            ->where('team_id', $teamId)
            ->actionNeeded()
            ->selectRaw('count(DISTINCT (ecosystem, name)) as n')
            ->value('n'));
    }

    /**
     * Open "Action needed" findings, most severe first.
     *
     * @param  list<int>|null  $instances
     * @return list<SecurityFinding>
     */
    private function open(int $teamId, ?array $instances): array
    {
        return array_values(SecurityFinding::query()
            ->where('team_id', $teamId)
            ->actionNeeded()
            ->when($instances !== null, static fn ($q) => $q->whereIn('service_instance_id', $instances ?? []))
            ->get()
            ->sort(static fn (SecurityFinding $a, SecurityFinding $b): int => [$b->known_exploited, $b->severity->rank(), (float) ($b->cvss_score ?? 0), $a->id]
                <=> [$a->known_exploited, $a->severity->rank(), (float) ($a->cvss_score ?? 0), $b->id])
            ->all());
    }

    /**
     * @param  list<SecurityFinding>  $members  most severe first
     * @param  array<int, true>  $production  environment ids
     * @param  array<string, array{title: string|null, cwes: list<string>}>  $advisories
     * @param  array<int, SecurityInvestigation>  $investigations
     */
    private function group(array $members, SecurityServiceRefs $refs, array $production, array $advisories, array $investigations): FixGroupData
    {
        $first = $members[0];
        $services = [];
        $byAdvisory = [];
        $cwes = [];
        $versions = [];
        $exploitedSince = null;
        $cvss = null;
        $severity = Severity::Unknown;

        foreach ($members as $finding) {
            $services[$finding->service_instance_id][] = $finding;
            $byAdvisory[$finding->group_key][] = $finding;
            $versions[$finding->version] = ($versions[$finding->version] ?? 0) + 1;

            foreach ($finding->advisory_ids as $id) {
                array_push($cwes, ...($advisories[$id]['cwes'] ?? []));
            }

            if ($finding->known_exploited_since !== null) {
                $since = $finding->known_exploited_since->toDateString();
                $exploitedSince = $exploitedSince === null ? $since : min($exploitedSince, $since);
            }

            if ($finding->severity->rank() > $severity->rank()) {
                $severity = $finding->severity;
            }

            if ($finding->cvss_score !== null) {
                $cvss = max($cvss ?? 0.0, (float) $finding->cvss_score);
            }
        }

        $rows = [];

        foreach ($services as $instanceId => $own) {
            $ref = $refs->get($instanceId);

            if ($ref instanceof SecurityServiceRefData) {
                $rows[] = $this->service($own, $ref, isset($production[$own[0]->environment_id]), $investigations);
            }
        }

        usort($rows, static fn (FixGroupServiceData $a, FixGroupServiceData $b): int => [$b->production, $b->knownExploited, $b->severity->rank(), $a->service->name, $a->service->projectName, $a->service->environmentName]
            <=> [$a->production, $a->knownExploited, $a->severity->rank(), $b->service->name, $b->service->projectName, $b->service->environmentName]);

        $list = [];

        foreach ($byAdvisory as $groupKey => $own) {
            $lead = $own[0];
            $title = null;

            foreach ($lead->advisory_ids as $id) {
                $title ??= $advisories[$id]['title'] ?? null;
            }

            $list[] = new FixGroupAdvisoryData(
                id: (string) $groupKey,
                title: $title,
                severity: $lead->severity,
                cvss: $lead->cvss_score === null ? null : (float) $lead->cvss_score,
                knownExploited: array_any($own, static fn (SecurityFinding $f): bool => $f->known_exploited),
                fixedVersion: FixGroup::highest(array_values(array_filter(array_map(static fn (SecurityFinding $f): ?string => $f->fixed_version, $own)))),
                services: count(array_unique(array_map(static fn (SecurityFinding $f): int => $f->service_instance_id, $own))),
                finding: $lead->public_id,
            );
        }

        arsort($versions);

        return new FixGroupData(
            key: FixGroup::key($first->ecosystem, $first->name),
            ecosystem: $first->ecosystem,
            package: $first->name,
            versions: array_map(strval(...), array_keys($versions)),
            fixedVersion: FixGroup::highest(array_values(array_filter(array_map(static fn (FixGroupServiceData $s): ?string => $s->fixedVersion, $rows)))),
            severity: $severity,
            cvss: $cvss,
            knownExploited: $first->known_exploited,
            knownExploitedSince: $exploitedSince,
            impact: FixGroup::impact(array_values(array_unique($cwes))),
            headline: $list[0]->title,
            production: array_any($rows, static fn (FixGroupServiceData $s): bool => $s->production),
            services: $rows,
            advisories: $list,
            lead: $rows[0]->lead,
        );
    }

    /**
     * @param  list<SecurityFinding>  $own  one instance's findings, most severe first
     * @param  array<int, SecurityInvestigation>  $investigations
     */
    private function service(array $own, SecurityServiceRefData $ref, bool $production, array $investigations): FixGroupServiceData
    {
        $latest = null;

        foreach ($own as $finding) {
            $investigation = $investigations[$finding->investigation_id ?? 0] ?? null;

            if ($investigation instanceof SecurityInvestigation && (! $latest instanceof SecurityInvestigation || $investigation->id > $latest->id)) {
                $latest = $investigation;
            }
        }

        return new FixGroupServiceData(
            service: $ref,
            production: $production,
            version: implode(', ', array_values(array_unique(array_map(static fn (SecurityFinding $f): string => $f->version, $own)))),
            fixedVersion: FixGroup::highest(array_values(array_filter(array_map(static fn (SecurityFinding $f): ?string => $f->fixed_version, $own)))),
            severity: $own[0]->severity,
            knownExploited: $own[0]->known_exploited,
            findings: array_map(static fn (SecurityFinding $f): string => $f->public_id, $own),
            lead: $own[0]->public_id,
            investigation: $latest instanceof SecurityInvestigation ? new FindingInvestigationData(
                $latest->public_id,
                $latest->status,
                $latest->started_at->toIso8601ZuluString(),
                $latest->finished_at?->toIso8601ZuluString(),
                InvestigationSummary::sentences($latest),
            ) : null,
        );
    }

    /**
     * @param  list<SecurityFinding>  $findings
     * @return array<int, true>
     */
    private function productionEnvironments(array $findings): array
    {
        $ids = array_values(array_unique(array_map(static fn (SecurityFinding $f): int => $f->environment_id, $findings)));

        return array_fill_keys(
            array_map(Cast::id(...), DB::table('environments')->whereIn('id', $ids)->where('kind', EnvironmentKind::Production->value)->pluck('id')->all()),
            true,
        );
    }

    /**
     * Summaries and CWE ids of the findings' advisories.
     *
     * @param  list<SecurityFinding>  $findings
     * @return array<string, array{title: string|null, cwes: list<string>}>
     */
    private function advisories(array $findings): array
    {
        $out = [];

        foreach (array_chunk(SecurityOverview::advisoryIds($findings), 1000) as $chunk) {
            foreach (DB::table('advisories')->whereIn('id', $chunk)->get(['id', 'summary', 'cwe_ids']) as $row) {
                $cwes = json_decode(Cast::string($row->cwe_ids ?? null), true);
                $out[Cast::string($row->id ?? null)] = [
                    'title' => is_string($row->summary ?? null) && $row->summary !== '' ? $row->summary : null,
                    'cwes' => is_array($cwes) ? array_values(array_filter($cwes, is_string(...))) : [],
                ];
            }
        }

        return $out;
    }
}
