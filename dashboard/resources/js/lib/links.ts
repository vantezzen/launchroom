import { FileWarning, ScrollText } from 'lucide-react';
import type { DrillAction } from '@/components/charts/chart-drill';
import { link as deploymentLinkRoute } from '@/routes/deployments';
import {
    analyticsPage as analyticsRoute,
    logs as projectLogsRoute,
    resourcesPage as resourcesRoute,
} from '@/routes/projects';
import { show as serviceRoute } from '@/routes/projects/services';
import { show as serverRoute, tab as serverTabRoute } from '@/routes/servers';
import { formatWindow, scopeQuery, windowAround } from './observe-scope';
import type { ObserveWindow } from './observe-scope';

/**
 * Deep links on the frontend (docs/08 §1 "Observe scope"), the TypeScript
 * side of App\Support\Links: wayfinder routes plus the scope's query, so a
 * chart, a log line or a card opens the filtered view instead of a page
 * the reader then has to search.
 */

type Query = Record<string, string | undefined | null>;

function clean(query: Query): Record<string, string> {
    return Object.fromEntries(
        Object.entries(query).filter(
            (entry): entry is [string, string] =>
                typeof entry[1] === 'string' && entry[1] !== '',
        ),
    );
}

/**
 * The environment's log explorer: optionally one service (public id or
 * slug), a search and an absolute window.
 */
export function logsHref({
    project,
    environment,
    service,
    q,
    window,
    range,
}: {
    project: string;
    environment: string;
    service?: string | null;
    q?: string | null;
    window?: ObserveWindow | null;
    range?: string | null;
}): string {
    return projectLogsRoute([project, environment], {
        query: clean({
            ...scopeQuery({ service, window, range }),
            q,
        }),
    }).url;
}

/**
 * The project's Analytics tab (D75) in one of its views: Traffic (requests,
 * errors, blocked requests, paths) or Resources (CPU, memory, latency,
 * bandwidth), optionally for one service and a range.
 */
export function analyticsHref({
    project,
    environment,
    view = 'traffic',
    service,
    range,
}: {
    project: string;
    environment: string;
    view?: App.Enums.AnalyticsView;
    service?: string | null;
    range?: string | null;
}): string {
    const route = view === 'resources' ? resourcesRoute : analyticsRoute;

    return route([project, environment], {
        query: scopeQuery({ service, range }),
    }).url;
}

/**
 * A service's Analytics (the service panel's link, the node menu, ⌘K):
 * Traffic for a service with a domain, Resources for everything else.
 */
export function serviceAnalyticsHref({
    project,
    environment,
    service,
    hasDomain,
    range,
}: {
    project: string;
    environment: string;
    service: string;
    hasDomain: boolean;
    range?: string | null;
}): string {
    return analyticsHref({
        project,
        environment,
        service,
        range,
        view: hasDomain ? 'traffic' : 'resources',
    });
}

/** A service panel tab with a query (`?q=`, `?from=`, `?range=` …). */
export function serviceTabHref(
    project: string,
    environment: string,
    service: string,
    tab: string,
    query: Query = {},
): string {
    return serviceRoute(
        { project, environment, service, tab },
        { query: clean(query) },
    ).url;
}

/** A deployment by its id alone (`/d/{id}` redirects to its page). */
export function deploymentHref(deploymentId: string): string {
    return deploymentLinkRoute(deploymentId).url;
}

/** A server page, optionally a tab (`resources`, `network`, `settings`). */
export function serverHref(
    serverId: string,
    tab?: string,
    query: Query = {},
): string {
    return tab
        ? serverTabRoute([serverId, tab], { query: clean(query) }).url
        : serverRoute(serverId, { query: clean(query) }).url;
}

/**
 * The drill-down of a chart about one environment or service: the logs of
 * the selected bucket (at least ten minutes around it), and only its errors. `inPanel` keeps the reader in
 * the service panel (its Logs tab) instead of the project's Logs page.
 */
export function logsDrill({
    project,
    environment,
    service,
    inPanel = false,
    errorsLabel = 'Errors only',
}: {
    project: string;
    environment: string;
    service?: string | null;
    inPanel?: boolean;
    errorsLabel?: string;
}): (window: ObserveWindow) => DrillAction[] {
    return (bucket) => {
        // A one-minute point opens ten minutes around it, for context.
        const window = windowAround(bucket.from, bucket.to);
        const href = (q?: string) =>
            inPanel && service
                ? serviceTabHref(project, environment, service, 'logs', {
                      ...scopeQuery({ window }),
                      q,
                  })
                : logsHref({ project, environment, service, window, q });

        return [
            {
                label: `Logs from ${formatWindow(window)}`,
                href: href(),
                icon: ScrollText,
            },
            {
                label: errorsLabel,
                href: href('level:error'),
                icon: FileWarning,
            },
        ];
    };
}
