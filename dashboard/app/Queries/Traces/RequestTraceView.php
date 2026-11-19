<?php

declare(strict_types=1);

namespace App\Queries\Traces;

use App\Data\Security\SecurityServiceRefData;
use App\Data\Security\WafExclusionDraftData;
use App\Data\Security\WafRuleData;
use App\Data\Servers\DeviceData;
use App\Data\Traces\RequestTraceData;
use App\Data\Traces\TraceFirewallData;
use App\Data\Traces\TraceServerData;
use App\Enums\Security\WafCategory;
use App\Enums\Security\WafMode;
use App\Enums\Traces\TraceFailure;
use App\Enums\Traces\TraceFirewallOutcome;
use App\Enums\Traces\TraceOrigin;
use App\Enums\Traces\TraceStop;
use App\Models\Deployment;
use App\Models\Domain;
use App\Models\Security\WafExclusion;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Models\User;
use App\Queries\Security\SecurityServiceRefs;
use App\Queries\Security\WafOverview;
use App\Services\Instance\DashboardOrigin;
use App\Services\Previews\PreviewUrls;
use App\Support\Ingress\RouteId;
use App\Support\Links;
use App\Support\Security\CrsRules;
use App\Support\Traces\DecodedTrace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

/**
 * A decoded trace as the trace page shows it (docs/23 §6–7), or null when
 * the member may not see it: the edge must be a server of the team the
 * member can view, the service one they can view (View, firewall rules
 * included, as with firewall events); a route without a service is the
 * dashboard's own and needs servers.manage. Everything is looked up inside
 * the team; ids that no longer resolve (a removed upstream server, a
 * deleted domain) are left out rather than refusing the trace.
 */
final readonly class RequestTraceView
{
    /** Logs around the request: from this many seconds before to after it. */
    private const int LOG_WINDOW_SECONDS = 60;

    public function __construct(
        private DashboardOrigin $origin,
        private PreviewUrls $previewUrls,
    ) {}

    public function handle(DecodedTrace $trace, Team $team, User $user): ?RequestTraceData
    {
        $gate = Gate::forUser($user);
        $edge = $this->server($team, $trace->edge);

        if (! $edge instanceof Server || ! $gate->allows('view', $edge)) {
            return null;
        }

        $instance = null;

        if ($trace->service !== null) {
            $instance = ServiceInstance::query()
                ->where('team_id', $team->id)
                ->where('public_id', $trace->service)
                ->with(['service', 'environment.project', 'activeDeployment'])
                ->first();

            if (! $instance instanceof ServiceInstance || ! $gate->allows('view', $instance)) {
                return null;
            }
        }

        $dashboard = ! $instance instanceof ServiceInstance && $trace->route !== null;

        if ($dashboard && ! $gate->allows('viewSystemTraces', $team)) {
            return null;
        }

        $upstream = $this->upstreamServer($team, $trace, $edge);
        $ref = $instance instanceof ServiceInstance ? SecurityServiceRefs::ref($instance) : null;
        $firewall = $this->firewall($trace);
        $receivedAt = CarbonImmutable::createFromTimestampMsUTC($trace->receivedAtMs);

        return new RequestTraceData(
            receivedAt: $receivedAt->format('Y-m-d\TH:i:s.v\Z'),
            requestId: $trace->requestId,
            method: $trace->method,
            path: $trace->path,
            protocol: $trace->protocol,
            status: $trace->status,
            upstreamStatus: $trace->upstreamStatus !== $trace->status ? $trace->upstreamStatus : null,
            origin: $trace->origin,
            failure: $trace->failure,
            edgeTimeUs: $trace->edgeTimeUs,
            upstreamTimeUs: $trace->upstreamTimeUs,
            attempts: $trace->attempts,
            firewall: $firewall,
            edge: $this->serverData($edge),
            upstreamServer: $upstream instanceof Server ? $this->serverData($upstream) : null,
            sameServer: $upstream instanceof Server && $upstream->id === $edge->id,
            host: $dashboard ? $this->origin->canonicalHost() : $this->host($team, $trace->route),
            service: $ref,
            dashboard: $dashboard,
            endsAt: $this->endsAt($trace),
            requestClass: $this->requestClass($trace),
            summary: $this->summary($trace, $ref?->name, $dashboard),
            logsHref: $instance instanceof ServiceInstance
                ? Links::logs($instance->environment, $instance, $trace->requestId, $receivedAt->subSeconds(self::LOG_WINDOW_SECONDS), $receivedAt->addSeconds(self::LOG_WINDOW_SECONDS))
                : null,
            firewallHref: $instance instanceof ServiceInstance && $firewall instanceof TraceFirewallData ? Links::serviceSettings($instance, 'firewall') : null,
            allow: $instance instanceof ServiceInstance && $ref instanceof SecurityServiceRefData && $gate->allows('create', [WafExclusion::class, $instance])
                ? $this->allow($trace, $ref->href)
                : null,
        );
    }

    private function server(Team $team, ?string $publicId): ?Server
    {
        return $publicId === null ? null : $team->servers()->where('public_id', $publicId)->first();
    }

    /**
     * The server of the last attempt. A trace without one but with an
     * answer from the app went to a replica on the edge itself (local
     * upstreams carry no server, docs/23 §5).
     */
    private function upstreamServer(Team $team, DecodedTrace $trace, Server $edge): ?Server
    {
        if ($trace->upstreamServer !== null) {
            return $trace->upstreamServer === $edge->public_id ? $edge : $this->server($team, $trace->upstreamServer);
        }

        $reached = $trace->origin === TraceOrigin::App || $trace->upstreamStatus !== null || $trace->upstreamTimeUs !== null;

        return $reached ? $edge : null;
    }

    private function host(Team $team, ?string $route): ?string
    {
        $domainId = $route === null ? null : RouteId::domainPublicId($route);

        if ($route === null || $domainId === null) {
            return null;
        }

        $domain = Domain::query()->where('team_id', $team->id)->where('public_id', $domainId)->first();

        if ($domain instanceof Domain) {
            return $domain->hostname;
        }

        $deployment = Deployment::query()
            ->where('team_id', $team->id)
            ->where('public_id', RouteId::deploymentPublicId($route))
            ->with(['serviceInstance.service', 'environment.project'])
            ->first();

        return $deployment instanceof Deployment
            ? $this->previewUrls->deploymentHostname($deployment->serviceInstance->service, $deployment, $deployment->environment->project)
            : null;
    }

    private function firewall(DecodedTrace $trace): ?TraceFirewallData
    {
        if ($trace->firewallMode === null || ! $trace->firewallOutcome instanceof TraceFirewallOutcome) {
            return null;
        }

        return new TraceFirewallData(
            mode: $trace->firewallMode === 2 ? WafMode::Block : WafMode::Log,
            outcome: $trace->firewallOutcome,
            score: $trace->firewallScore ?? 0,
            rules: array_map(static fn (int $rule): WafRuleData => new WafRuleData($rule, CrsRules::message($rule), WafCategory::ofRule($rule)->value), $trace->firewallRules),
        );
    }

    /**
     * "Allow requests like this" (docs/22 §6.4) for a request the firewall
     * flagged: an exception per matched rule for the path's static prefix.
     * The trace has no matched targets, so the exceptions name none.
     */
    private function allow(DecodedTrace $trace, string $serviceHref): ?WafExclusionDraftData
    {
        if (! $trace->firewallOutcome instanceof TraceFirewallOutcome || $trace->firewallOutcome === TraceFirewallOutcome::Clean || $trace->firewallRules === []) {
            return null;
        }

        return WafOverview::draft($trace->firewallRules, $trace->path ?? '', [], $serviceHref);
    }

    private function serverData(Server $server): TraceServerData
    {
        return new TraceServerData(
            id: $server->public_id,
            name: $server->name,
            status: $server->status,
            device: DeviceData::fromServer($server),
            href: Links::server($server),
        );
    }

    private function endsAt(DecodedTrace $trace): TraceStop
    {
        return match ($trace->origin) {
            TraceOrigin::App => TraceStop::Service,
            TraceOrigin::Firewall => TraceStop::Firewall,
            TraceOrigin::UpstreamFailure => TraceStop::AppServer,
            default => TraceStop::Edge,
        };
    }

    private function requestClass(DecodedTrace $trace): string
    {
        if ($trace->origin === TraceOrigin::Firewall || $trace->firewallOutcome === TraceFirewallOutcome::Blocked) {
            return 'blocked';
        }

        return match (true) {
            $trace->status < 200 => 'upgrade',
            $trace->status < 300 => 'success',
            $trace->status < 400 => 'redirect',
            $trace->status < 500 => 'clientError',
            default => 'serverError',
        };
    }

    /**
     * One sentence of what happened, naming the service (or the dashboard
     * for its own routes) where the app is the subject.
     */
    private function summary(DecodedTrace $trace, ?string $service, bool $dashboard): string
    {
        // Subject at the start of a sentence, object inside one.
        [$subject, $object] = match (true) {
            $dashboard => ['The dashboard', 'the dashboard'],
            $service !== null => [$service, $service],
            default => ['The app', 'the app'],
        };
        $status = ' with '.$trace->status;
        $rules = count($trace->firewallRules);
        $matched = $rules === 0 ? '' : ' '.$rules.' '.($rules === 1 ? 'rule' : 'rules').' matched.';

        $sentence = match ($trace->origin) {
            TraceOrigin::App => $subject.' answered'.$status.'.'
                .($trace->firewallOutcome === TraceFirewallOutcome::Detected ? ' The firewall would have blocked it.' : ''),
            TraceOrigin::Firewall => ($trace->upstreamStatus !== null ? "The firewall blocked {$object}'s response." : 'The firewall blocked this request.').$matched,
            TraceOrigin::UpstreamFailure => match ($trace->failure) {
                TraceFailure::Refused => $dashboard ? 'The dashboard refused the connection.' : 'The app server refused the connection.',
                TraceFailure::Timeout => "{$subject} didn't answer in time.",
                TraceFailure::NoHealthyUpstream => 'No replica of '.($dashboard || $service === null ? 'the service' : $service).' was healthy.',
                TraceFailure::ClientClosed => "The visitor closed the connection before {$object} answered.",
                TraceFailure::Transport, null => "The edge couldn't reach {$object}.",
            },
            TraceOrigin::Stopped => ($service ?? 'The service').' is stopped, so the edge answered'.$status.'.',
            TraceOrigin::Redirect => 'The edge redirected this request.',
            TraceOrigin::NoRoute => 'No domain on this server matched the address.',
            TraceOrigin::AccessRefused => 'Sign-in protection refused this request.',
            TraceOrigin::Edge => 'The edge answered this request itself.',
        };

        if ($trace->origin === TraceOrigin::UpstreamFailure && $trace->attempts !== null && $trace->attempts > 1) {
            $sentence .= ' The edge tried '.$trace->attempts.' times.';
        }

        return $sentence;
    }
}
