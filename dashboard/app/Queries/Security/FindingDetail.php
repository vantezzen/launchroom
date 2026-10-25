<?php

declare(strict_types=1);

namespace App\Queries\Security;

use App\Data\Security\AdvisoryDetailData;
use App\Data\Security\FindingCardData;
use App\Data\Security\FindingCheckData;
use App\Data\Security\FindingDetailData;
use App\Data\Security\InvestigationDetailData;
use App\Data\Security\InvestigationLineData;
use App\Data\Security\InvestigationLinkData;
use App\Data\Security\InvestigationReplicaData;
use App\Data\Security\InvestigationReportData;
use App\Enums\Security\SecurityFindingStatus;
use App\Models\Security\Advisory;
use App\Models\Security\SecurityFinding;
use App\Models\Security\SecurityInvestigation;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Support\Metrics\Cast;
use App\Support\Security\Lockfiles;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * A finding's detail (docs/22 §9.3): facts, the evaluated rules, the
 * latest investigation and its report, the advisory as plain text.
 * Client networks in the report only for owners and admins.
 */
final readonly class FindingDetail
{
    public function __construct(
        private SecurityOverview $overview,
    ) {}

    public function handle(SecurityFinding $finding, User $user, ?bool $networks = null): FindingDetailData
    {
        $refs = new SecurityServiceRefs;
        $refs->load($finding->team_id, [$finding->service_instance_id]);
        $investigation = SecurityInvestigation::query()
            ->where('service_instance_id', $finding->service_instance_id)
            ->where(static fn (Builder $q) => $q->where('finding_id', $finding->id)->orWhere('id', $finding->investigation_id))
            ->latest('started_at')
            ->with('starter')
            ->first();
        $titles = $this->overview->titles($finding->advisory_ids);
        $card = $this->overview->card($finding, $refs, $titles, $investigation);
        $instance = $refs->instance($finding->service_instance_id);
        abort_if(! $card instanceof FindingCardData || ! $instance instanceof ServiceInstance, 404);

        $gate = Gate::forUser($user);
        $networks ??= $gate->allows('viewClientNetworks', [SecurityFinding::class, $finding->team]);

        // More services of the team that run this version.
        $others = SecurityFinding::query()
            ->where('team_id', $finding->team_id)
            ->where('group_key', $finding->group_key)
            ->where('ecosystem', $finding->ecosystem)
            ->where('name', $finding->name)
            ->where('version', $finding->version)
            ->where('id', '!=', $finding->id)
            ->where('status', '!=', SecurityFindingStatus::Resolved->value)
            ->pluck('service_instance_id')
            ->all();
        $others = array_values(array_map(Cast::id(...), $others));
        $refs->load($finding->team_id, $others);

        $lockfile = Lockfiles::evidence($finding->ecosystem, $finding->kind, $finding->inventory->lockfiles ?? []);

        return new FindingDetailData(
            finding: $card,
            kind: $finding->kind,
            scope: $finding->scope,
            presence: $finding->presence,
            direct: $finding->direct,
            lockfile: $lockfile,
            otherServices: array_values(array_filter(array_map($refs->get(...), $others))),
            domains: array_values(array_map(Cast::string(...), DB::table('domains')->where('service_instance_id', $finding->service_instance_id)->whereIn('kind', ['custom', 'generated'])->orderBy('id')->pluck('hostname')->all())),
            checks: array_map(static fn (array $c): FindingCheckData => new FindingCheckData($c['rule'], $c['passed'], $c['label']), $finding->checks),
            dismissedReason: $finding->dismiss_reason?->label(),
            dismissedNote: $finding->dismiss_note,
            dismissedBy: $finding->dismisser?->name,
            dismissedAt: $finding->dismissed_at?->toIso8601ZuluString(),
            advisory: $this->advisory($finding),
            investigation: $investigation === null ? null : $this->investigation($investigation, $networks),
            wafMode: $instance->waf_mode->value,
            canDismiss: $gate->allows('dismiss', $finding),
            canInvestigate: $gate->allows('create', [SecurityInvestigation::class, $instance]),
            canChangeFirewall: $gate->allows('update', $instance),
            firewallUrl: "{$card->service->href}/firewall",
        );
    }

    public function investigation(SecurityInvestigation $investigation, bool $networks): InvestigationDetailData
    {
        $report = null;

        if (is_array($investigation->report)) {
            $report = InvestigationReportData::from($investigation->report);

            if (! $networks) {
                $report->found = array_map(static fn (InvestigationLineData $line): InvestigationLineData => new InvestigationLineData($line->level, $line->text), $report->found);
            }
        }

        return new InvestigationDetailData(
            id: $investigation->public_id,
            status: $investigation->status->value,
            automatic: $investigation->automatic,
            startedBy: $investigation->starter?->name,
            startedAt: $investigation->started_at->toIso8601ZuluString(),
            finishedAt: $investigation->finished_at?->toIso8601ZuluString(),
            replicas: array_map(static fn (array $r): InvestigationReplicaData => new InvestigationReplicaData($r['serverName'], $r['state'], $r['message'] ?? null), $investigation->replicas),
            report: $report,
        );
    }

    private function advisory(SecurityFinding $finding): ?AdvisoryDetailData
    {
        $records = Advisory::query()->whereKey($finding->advisory_ids)->get();

        if ($records->isEmpty()) {
            return null;
        }

        // The GHSA record reads best; else the first one.
        $main = $records->first(static fn (Advisory $a): bool => str_starts_with($a->id, 'GHSA-')) ?? $records->first();
        $ids = [];
        $references = [];
        $cwe = [];

        foreach ($records as $record) {
            $ids = [...$ids, $record->id, ...$record->aliases];
            $cwe = [...$cwe, ...$record->cwe_ids];

            foreach ($record->references as $reference) {
                $references[$reference['url']] ??= new InvestigationLinkData($this->referenceLabel($reference['type'], $reference['url']), $reference['url'], true);
            }
        }

        $sources = array_values(array_unique(array_map(static fn (Advisory $a): string => match (true) {
            str_starts_with($a->id, 'GHSA-') => 'GitHub Advisory Database',
            str_starts_with($a->id, 'PYSEC-') => 'PyPA Advisory Database',
            str_starts_with($a->id, 'GO-') => 'Go Vulnerability Database',
            str_starts_with($a->id, 'RUSTSEC-') => 'RustSec',
            str_starts_with($a->id, 'DEBIAN-'), str_starts_with($a->id, 'DSA-'), str_starts_with($a->id, 'DLA-') => 'Debian Security Tracker',
            str_starts_with($a->id, 'UBUNTU-'), str_starts_with($a->id, 'USN-') => 'Ubuntu Security Notices',
            str_starts_with($a->id, 'ALPINE-') => 'Alpine SecDB',
            str_starts_with($a->id, 'BIT-') => 'Bitnami Vulnerability Database',
            default => 'OSV',
        }, $records->all())));

        return new AdvisoryDetailData(
            ids: array_values(array_unique($ids)),
            summary: $main->summary,
            details: $main->details,
            published: $main->published?->toIso8601ZuluString(),
            modified: $main->modified?->toIso8601ZuluString(),
            cvssV3: $main->cvss['v3'] ?? null,
            cvssV4: $main->cvss['v4'] ?? null,
            cwe: array_values(array_unique($cwe)),
            references: array_slice(array_values($references), 0, 20),
            source: 'From '.implode(', ', $sources).' via osv.dev (CC-BY 4.0).',
        );
    }

    private function referenceLabel(string $type, string $url): string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        return match ($type) {
            'ADVISORY' => "Advisory on {$host}",
            'FIX' => "Fix on {$host}",
            'PACKAGE' => "Package on {$host}",
            'REPORT' => "Report on {$host}",
            default => $host,
        };
    }
}
