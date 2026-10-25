<?php

declare(strict_types=1);

namespace App\Queries\Security;

use App\Data\Security\ServiceFirewallData;
use App\Data\Security\WafExclusionData;
use App\Models\Security\SecurityFinding;
use App\Models\Security\SecurityInvestigation;
use App\Models\Security\WafExclusion;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Support\Metrics\Cast;
use App\Support\Security\CrsRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The service panel's Web firewall page (docs/22 §9.6): the mode and
 * paranoia, the last 7 days for this service, its exceptions, edges that
 * can't protect it and its latest events.
 */
final readonly class ServiceFirewall
{
    public function __construct(
        private WafOverview $waf,
    ) {}

    public function handle(ServiceInstance $instance, User $user): ServiceFirewallData
    {
        $instance->loadMissing(['service', 'environment.project']);
        $refs = new SecurityServiceRefs;
        $refs->load($instance->team_id, [$instance->id]);
        $ref = SecurityServiceRefs::ref($instance);
        $now = CarbonImmutable::now();
        $start = CarbonImmutable::createFromTimestampUTC(intdiv($now->getTimestamp(), 3600) * 3600 + 3600)->subDays(7);
        $series = array_fill(0, 168, 0);
        $blocked = 0;
        $detected = 0;

        foreach (DB::table('waf_samples')->where('service_instance_id', $instance->id)->where('resolution', 10)->where('at', '>=', $start->toIso8601String())->get(['at', 'blocked', 'detected']) as $row) {
            $i = intdiv(CarbonImmutable::parse(Cast::string($row->at ?? null))->getTimestamp() - $start->getTimestamp(), 3600);

            if ($i >= 0 && $i < 168) {
                $series[$i] += Cast::id($row->blocked ?? null) + Cast::id($row->detected ?? null);
            }

            $blocked += Cast::id($row->blocked ?? null);
            $detected += Cast::id($row->detected ?? null);
        }

        $gate = Gate::forUser($user);
        $networks = $gate->allows('viewClientNetworks', [SecurityFinding::class, $instance->team]);
        $watching = $this->waf->watching($instance->team_id, $refs, $instance->id);
        $edges = $instance->waf_mode->value === 'off' ? [] : $this->waf->edges($instance->team_id);
        $ruleSet = null;

        foreach ($instance->team->servers()->whereNotNull('waf_status')->get() as $server) {
            $ruleSet ??= is_string($server->waf_status['ruleSet'] ?? null) ? $server->waf_status['ruleSet'] : null;
        }

        return new ServiceFirewallData(
            mode: $instance->waf_mode->value,
            paranoia: $instance->waf_paranoia,
            inspectBodyKiB: $instance->waf_inspect_body_kib,
            routed: $instance->domains()->whereIn('kind', ['custom', 'generated'])->exists(),
            canManage: $gate->allows('create', [WafExclusion::class, $instance]),
            blocked: $blocked,
            detected: $detected,
            series: array_values($series),
            watching: $watching[0] ?? null,
            exclusions: array_values(WafExclusion::query()->where('service_instance_id', $instance->id)->with('creator')->orderBy('id')->get()->map(static fn (WafExclusion $x): WafExclusionData => new WafExclusionData(
                $x->public_id,
                $x->rule_id,
                CrsRules::message($x->rule_id),
                $x->path_prefix,
                $x->target,
                $x->note,
                $x->creator?->name,
                ($x->created_at ?? $now)->toIso8601ZuluString(),
            ))->values()->all()),
            edges: $edges,
            recent: $this->waf->events($instance->team_id, $refs, $networks, 10, $instance->id),
            ruleSet: $ruleSet,
            updateUrl: "{$ref->href}/firewall",
            exclusionsUrl: "{$ref->href}/firewall/exclusions",
            investigateUrl: "{$ref->href}/investigate",
            canInvestigate: $gate->allows('create', [SecurityInvestigation::class, $instance]),
        );
    }
}
