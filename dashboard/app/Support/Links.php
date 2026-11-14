<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\AnalyticsView;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\Security\SecurityFinding;
use App\Models\Server;
use App\Models\ServiceInstance;
use Carbon\CarbonInterface;

/**
 * Deep links into the dashboard (docs/08 §1 "Observe scope", docs/15 §3.4):
 * one place that knows the URL of each view and its filters, so alerts,
 * activity, investigation steps and the palette land on the filtered view
 * instead of a page the reader then has to search. Paths are relative
 * (`/p/…`); notification senders make them absolute.
 *
 * Every method takes models the caller already resolved inside the team;
 * nothing here looks anything up by user input. The time window of a logs
 * link uses the explorer's `from`/`to` (epoch seconds, docs/08 §12).
 */
final class Links
{
    /**
     * A service panel tab: `deployments`, `logs`, `settings` ….
     *
     * @param  array<string, string|int|null>  $query
     */
    public static function service(ServiceInstance $instance, ?string $tab = null, array $query = []): string
    {
        $instance->loadMissing(['service', 'environment.project']);

        return self::path('projects.services.show', [
            'project' => $instance->environment->project->public_id,
            'environment' => $instance->environment->public_id,
            'service' => $instance->service->public_id,
            'tab' => $tab,
        ], $query);
    }

    /**
     * A service's settings page, optionally scrolled to an element.
     */
    public static function serviceSettings(ServiceInstance $instance, string $section, ?string $fragment = null): string
    {
        $instance->loadMissing(['service', 'environment.project']);

        return self::path('projects.services.section', [
            'project' => $instance->environment->project->public_id,
            'environment' => $instance->environment->public_id,
            'service' => $instance->service->public_id,
            'section' => $section,
        ]).($fragment === null ? '' : '#'.rawurlencode($fragment));
    }

    /**
     * The service's runtime logs (its panel's Logs tab) with a search and,
     * optionally, an absolute window.
     */
    public static function serviceLogs(ServiceInstance $instance, ?string $query = null, ?CarbonInterface $from = null, ?CarbonInterface $to = null): string
    {
        return self::service($instance, 'logs', [
            'q' => $query,
            ...self::window($from, $to),
        ]);
    }

    /**
     * The service's view of the project's Analytics tab (D75): Traffic when
     * it has a domain, else Resources, filtered to the service.
     */
    public static function serviceAnalytics(ServiceInstance $instance, ?string $range = null): string
    {
        $instance->loadMissing('environment.project');

        return self::analytics(
            $instance->environment,
            $instance,
            $range,
            $instance->domains()->exists() ? AnalyticsView::Traffic : AnalyticsView::Resources,
        );
    }

    /**
     * The environment's log explorer, optionally narrowed to a service.
     */
    public static function logs(Environment $environment, ?ServiceInstance $instance = null, ?string $query = null, ?CarbonInterface $from = null, ?CarbonInterface $to = null): string
    {
        $environment->loadMissing('project');
        $instance?->loadMissing('service');

        return self::path('projects.logs', [
            'project' => $environment->project->public_id,
            'environment' => $environment->public_id,
        ], [
            'service' => $instance?->service->public_id,
            'q' => $query,
            ...self::window($from, $to),
        ]);
    }

    /**
     * The environment's Analytics tab in one of its views (Traffic: request
     * analytics; Resources: CPU, memory, latency and bandwidth), optionally
     * for one service.
     */
    public static function analytics(Environment $environment, ?ServiceInstance $instance = null, ?string $range = null, AnalyticsView $view = AnalyticsView::Traffic): string
    {
        $environment->loadMissing('project');
        $instance?->loadMissing('service');

        return self::path($view === AnalyticsView::Resources ? 'projects.resources-page' : 'projects.analytics-page', [
            'project' => $environment->project->public_id,
            'environment' => $environment->public_id,
        ], ['service' => $instance?->service->public_id, 'range' => $range]);
    }

    public static function deployment(Deployment $deployment): string
    {
        $deployment->loadMissing('environment.project');

        return self::path('deployments.show', [
            'project' => $deployment->environment->project->public_id,
            'environment' => $deployment->environment->public_id,
            'deployment' => $deployment->public_id,
        ]);
    }

    /**
     * A server page tab (`resources`, `network`, `settings`; null = Overview),
     * optionally scrolled to a section.
     *
     * @param  array<string, string|int|null>  $query
     */
    public static function server(Server $server, ?string $tab = null, array $query = [], ?string $fragment = null): string
    {
        $path = $tab === null
            ? self::path('servers.show', ['server' => $server->public_id], $query)
            : self::path('servers.tab', ['server' => $server->public_id, 'tab' => $tab], $query);

        return $path.($fragment === null ? '' : '#'.rawurlencode($fragment));
    }

    /**
     * The Security page, scoped to a service (its instance in one
     * environment) or a project when given, optionally at a section.
     */
    public static function security(?ServiceInstance $instance = null, ?string $fragment = null, ?string $range = null): string
    {
        $instance?->loadMissing('service');

        return self::path('security.index', [], [
            'service' => $instance?->service->public_id,
            'range' => $range,
        ]).($fragment === null ? '' : '#'.rawurlencode($fragment));
    }

    public static function finding(SecurityFinding $finding): string
    {
        return self::path('security.findings.show', ['finding' => $finding->public_id]);
    }

    /**
     * `from`/`to` query parameters (epoch seconds) of an absolute window.
     *
     * @return array<string, int|null>
     */
    private static function window(?CarbonInterface $from, ?CarbonInterface $to): array
    {
        if (! $from instanceof CarbonInterface) {
            return [];
        }

        $to ??= now();

        return ['from' => $from->getTimestamp(), 'to' => max($to->getTimestamp(), $from->getTimestamp() + 60)];
    }

    /**
     * @param  array<string, string|null>  $parameters
     * @param  array<string, string|int|null>  $query
     */
    private static function path(string $name, array $parameters, array $query = []): string
    {
        $query = array_filter($query, static fn (string|int|null $value): bool => $value !== null && $value !== '');

        return route($name, array_filter($parameters, static fn (?string $value): bool => $value !== null), false)
            .($query === [] ? '' : '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }
}
