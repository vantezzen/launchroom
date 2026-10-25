<?php

declare(strict_types=1);

namespace App\Actions\Security;

use App\Data\Protocol\V1\ContainerInspectionResult;
use App\Data\Services\GitSource;
use App\Data\Specs\VolumeSpec;
use App\Enums\Security\FindingLevel;
use App\Enums\Security\InvestigationStatus;
use App\Enums\Security\SecurityFindingStatus;
use App\Enums\Security\WafCategory;
use App\Enums\ServiceKind;
use App\Models\Security\SecurityFinding;
use App\Models\Security\SecurityInvestigation;
use App\Services\Crypto\ProjectKeyring;
use App\Support\DocsUrl;
use App\Support\Metrics\Cast;
use App\Support\Security\CweCategories;
use App\Support\Security\InvestigationInputs;
use App\Support\Security\InvestigationReport;
use App\Support\Variables\SecretTaint;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Writes an investigation's report once its inspections are in (or timed
 * out, docs/22 §7.4–7.5): the decrypted container inspections, launchroom's
 * own consoles and SSH sessions, firewall results in the advisory's attack
 * categories, requests and server errors per hour, how long the vulnerable
 * version has been live and what the service can reach (connections,
 * secrets by name, volumes, domains). The finding's alert is raised now,
 * with the result in words. Idempotent: a finished investigation is left
 * alone.
 */
final readonly class BuildInvestigationReport
{
    public function __construct(
        private ProjectKeyring $keyring,
        private RaiseVulnerabilityAlert $alert,
    ) {}

    public function handle(SecurityInvestigation $investigation): SecurityInvestigation
    {
        $investigation = DB::transaction(function () use ($investigation): SecurityInvestigation {
            $investigation = SecurityInvestigation::query()->whereKey($investigation->id)
                ->with(['project', 'serviceInstance.service', 'serviceInstance.environment', 'serviceInstance.activeDeployment.revision', 'finding'])
                ->lockForUpdate()->firstOrFail();

            if ($investigation->status !== InvestigationStatus::Running) {
                return $investigation;
            }

            $now = CarbonImmutable::now();
            $report = InvestigationReport::build($this->inputs($investigation, $now));
            // Partial whenever a replica couldn't be checked (docs/22 §7.5);
            // the firewall, requests and reach are still in the report.
            $investigation->forceFill(['report' => $report->toArray()]);
            $investigation->transitionTo($report->partial ? InvestigationStatus::Partial : InvestigationStatus::Completed);

            return $investigation;
        });

        // The alerts this automatic investigation held back (docs/22 §7.1).
        if ($investigation->automatic) {
            $held = SecurityFinding::query()
                ->where('investigation_id', $investigation->id)
                ->where('status', SecurityFindingStatus::Open->value)
                ->where('level', FindingLevel::ActionNeeded->value)
                ->whereNull('notified_at')
                ->get();

            foreach ($held as $finding) {
                $this->alert->handle($finding);
            }
        }

        return $investigation;
    }

    private function inputs(SecurityInvestigation $investigation, CarbonImmutable $now): InvestigationInputs
    {
        $instance = $investigation->serviceInstance;
        $service = $instance->service;
        $environment = $instance->environment;
        $finding = $investigation->finding;
        $since = $now->subDays(7);
        $base = "/p/{$investigation->project->public_id}/{$environment->public_id}/s/{$service->public_id}";

        // Container inspections, decrypted for the report only.
        $results = [];

        if (is_string($investigation->inspections)) {
            $decoded = json_decode($this->keyring->decryptInspections($investigation->project, $investigation->public_id, $investigation->inspections), true);

            foreach (is_array($decoded) ? $decoded : [] as $workload => $raw) {
                try {
                    $results[(string) $workload] = is_array($raw) ? ContainerInspectionResult::fromWire($raw) : null;
                } catch (ValidationException) {
                    $results[(string) $workload] = null;
                }
            }
        }

        $replicas = array_map(static fn (array $r): array => [
            'server' => $r['serverName'],
            'state' => $r['state'],
            'message' => $r['message'] ?? null,
            'result' => $r['state'] === 'done' ? ($results[$r['workload']] ?? null) : null,
        ], $investigation->replicas);

        // Advisory → attack categories and references.
        $advisories = $finding === null ? [] : DB::table('advisories')->whereIn('id', $finding->advisory_ids)->get(['cwe_ids', 'references'])->all();
        $cwes = [];
        $references = [];

        foreach ($advisories as $row) {
            $cwes = [...$cwes, ...array_filter((array) json_decode(Cast::string($row->cwe_ids ?? null), true), is_string(...))];

            foreach ((array) json_decode(Cast::string($row->references ?? null), true) as $reference) {
                if (is_array($reference) && is_string($reference['url'] ?? null)) {
                    $references[] = $reference['url'];
                }
            }
        }

        $categories = $finding === null ? [] : CweCategories::for(array_values(array_unique($cwes)));
        $categoryValues = array_map(static fn (WafCategory $c): string => $c->value, $categories);

        // Firewall: events and totals in the advisory's categories.
        $events = [];

        foreach (DB::table('waf_events')->where('service_instance_id', $instance->id)->where('at', '>=', $since->toIso8601String())->orderBy('at')->limit(2000)->get(['at', 'action', 'categories', 'client_network']) as $row) {
            $events[] = [
                'at' => CarbonImmutable::parse(Cast::string($row->at ?? null)),
                'action' => Cast::string($row->action ?? null),
                'categories' => array_values(array_filter((array) json_decode(Cast::string($row->categories ?? null), true), is_string(...))),
                'network' => Cast::string($row->client_network ?? null),
            ];
        }

        $totals = ['blocked' => 0, 'detected' => 0];

        foreach (DB::table('waf_samples')->where('service_instance_id', $instance->id)->where('resolution', RollupWafSamples::TEN_MINUTES)->where('at', '>=', $since->toIso8601String())->get(['blocked', 'detected', 'categories']) as $row) {
            if ($categoryValues === []) {
                $totals['blocked'] += Cast::id($row->blocked ?? null);
                $totals['detected'] += Cast::id($row->detected ?? null);

                continue;
            }

            // A request with rules of several categories counts in each: take the largest match.
            $b = 0;
            $d = 0;

            foreach ((array) json_decode(Cast::string($row->categories ?? null), true) as $c) {
                if (is_array($c) && in_array($c['c'] ?? null, $categoryValues, true)) {
                    $b = max($b, Cast::id($c['b'] ?? null));
                    $d = max($d, Cast::id($c['d'] ?? null));
                }
            }

            $totals['blocked'] += $b;
            $totals['detected'] += $d;
        }

        // Requests per hour.
        $hours = [];

        foreach (DB::table('request_metric_samples')
            ->selectRaw("date_trunc('hour', at) as hour, sum(requests) as requests, sum(status_5xx) as errors")
            ->where('service_instance_id', $instance->id)->where('resolution', 10)->where('at', '>=', $since->toIso8601String())
            ->groupBy('hour')->orderBy('hour')->get() as $row) {
            $hours[] = ['at' => CarbonImmutable::parse(Cast::string($row->hour ?? null)), 'requests' => Cast::id($row->requests ?? null), 'errors' => Cast::id($row->errors ?? null)];
        }

        // Sessions of launchroom's own consoles and SSH.
        $sessions = [];

        foreach (DB::table('interactive_sessions as s')->join('users as u', 'u.id', '=', 's.user_id')
            ->where('s.service_instance_id', $instance->id)->where('s.kind', 'console')->whereNotNull('s.started_at')->where('s.started_at', '>=', $since->toIso8601String())
            ->oldest('s.started_at')->limit(20)->get(['u.name', 's.started_at', 's.ended_at']) as $row) {
            $sessions[] = ['kind' => 'console', 'user' => Cast::string($row->name ?? null), 'from' => CarbonImmutable::parse(Cast::string($row->started_at ?? null)), 'to' => is_string($row->ended_at ?? null) ? CarbonImmutable::parse($row->ended_at) : null];
        }

        foreach (DB::table('remote_accesses as a')->join('users as u', 'u.id', '=', 'a.user_id')
            ->where('a.service_instance_id', $instance->id)->where('a.kind', 'ssh')->whereNotNull('a.listening_at')->where('a.listening_at', '>=', $since->toIso8601String())
            ->oldest('a.listening_at')->limit(20)->get(['u.name', 'a.listening_at', 'a.ended_at']) as $row) {
            $sessions[] = ['kind' => 'ssh', 'user' => Cast::string($row->name ?? null), 'from' => CarbonImmutable::parse(Cast::string($row->listening_at ?? null)), 'to' => is_string($row->ended_at ?? null) ? CarbonImmutable::parse($row->ended_at) : null];
        }

        // What the service can reach (the canvas graph, names only). A
        // revision whose spec no longer reads leaves this part out instead of
        // failing the whole report.
        $revision = $instance->activeDeployment?->revision;
        $spec = $revision === null ? null : rescue(static fn () => $revision->spec);
        $serviceSpec = $spec?->service($service->public_id);
        $connections = [];
        $hrefs = [
            'deployments' => "{$base}/deployments",
            'variables' => "{$base}/variables",
            'source' => "{$base}/settings/source",
            'docs:responding' => DocsUrl::to('security/responding-to-a-vulnerability'),
        ];

        foreach ($spec->connections ?? [] as $connection) {
            if ($connection->fromServiceId !== $service->public_id) {
                continue;
            }

            $target = $spec?->service($connection->toServiceId);

            if ($target !== null) {
                $database = $target->kind === ServiceKind::Database;
                $connections[] = ['name' => $target->name, 'database' => $database, 'engine' => $database ? (is_string($target->source['engine'] ?? null) ? $target->source['engine'] : null) : null];

                if ($database) {
                    $hrefs['backups:'.$target->name] = "/p/{$investigation->project->public_id}/{$environment->public_id}/s/{$target->id}/backups?before=".rawurlencode($now->toIso8601ZuluString());
                }
            }
        }

        $source = $service->source;

        if ($source instanceof GitSource && $source->isGitHub()) {
            $hrefs['repository'] = 'https://github.com/'.$source->fullName;
        }

        // How long the vulnerable version has been live.
        $liveSince = null;

        if ($finding !== null) {
            $first = DB::table('deployments as d')
                ->join('inventory_components as c', 'c.inventory_id', '=', 'd.inventory_id')
                ->where('d.service_instance_id', $instance->id)
                ->where('c.name', $finding->name)->where('c.version', $finding->version)->where('c.ecosystem', $finding->ecosystem)
                ->whereNotNull('d.finished_at')
                ->min('d.finished_at');
            $liveSince = is_string($first) ? CarbonImmutable::parse($first) : null;
        }

        $lockfiles = [];
        $inventory = $finding?->inventory;

        foreach ($inventory->lockfiles ?? [] as $lockfile) {
            $lockfiles[] = $lockfile['kind'];
        }

        return new InvestigationInputs(
            service: $service->name,
            now: $now,
            replicas: $replicas,
            sessions: $sessions,
            wafMode: $instance->waf_mode->value,
            categories: $categories,
            events: $events,
            wafTotals: $totals,
            hours: $hours,
            liveSince: $liveSince,
            connections: $connections,
            secrets: $spec === null || $serviceSpec === null ? [] : SecretTaint::fromSpec($spec)->taintedKeys($serviceSpec->slug),
            volumes: $serviceSpec === null ? [] : array_values(array_map(static fn (VolumeSpec $v): string => $v->mountPath, $serviceSpec->volumes)),
            domains: array_values(array_map(Cast::string(...), DB::table('domains')->where('service_instance_id', $instance->id)->whereIn('kind', ['custom', 'generated'])->pluck('hostname')->all())),
            finding: $finding === null ? null : [
                'name' => $finding->name,
                'version' => $finding->version,
                'ecosystem' => $finding->ecosystem,
                'fixed' => $finding->fixed_version,
                'groupKey' => $finding->group_key,
                'built' => $inventory?->source->value === 'built',
                'lockfiles' => array_values(array_unique($lockfiles)),
                'references' => array_values(array_unique($references)),
            ],
            hrefs: $hrefs,
        );
    }
}
