import { Link } from '@inertiajs/react';
import { useQuery } from '@tanstack/react-query';
import { BarChart3, ScrollText, TriangleAlert } from 'lucide-react';
import { useMemo } from 'react';
import { formatCompact } from '@/components/charts/format';
import { StackedBars } from '@/components/charts/stacked-bars';
import { StatTile } from '@/components/charts/stat-tile';
import type { ChartDrill } from '@/components/charts/chart-drill';
import { Logo } from '@/components/logo';
import { EmptyState } from '@/components/patterns/empty-state';
import { MetaLine } from '@/components/patterns/meta-line';
import { Section } from '@/components/patterns/section';
import {
    AnalyticsHeader,
    useAnalyticsService,
} from '@/features/analytics/analytics-header';
import {
    agentRows,
    formatShare,
    pathRows,
    referrerRows,
    share,
    statusSeries,
} from '@/features/analytics/shape';
import type { ShareRow } from '@/features/analytics/shape';
import {
    delta,
    deployMarkers,
    formatErrorRate,
} from '@/features/metrics/format';
import { requestJson } from '@/features/projects/http';
import { useObserveScope } from '@/hooks/use-observe-scope';
import {
    formatWindow,
    RANGE_SETS,
    rangeOptions,
    rangeWords,
    withScope,
} from '@/lib/observe-scope';
import { cn } from '@/lib/utils';
import {
    analytics as analyticsRoute,
    logs as logsPage,
} from '@/routes/projects';
import { index as securityRoute } from '@/routes/security';

type Analytics = App.Data.Analytics.AnalyticsData;
type Split = App.Data.Analytics.AnalyticsSplitData;
type Service = App.Data.Metrics.MetricsServiceData;
type Range = (typeof RANGE_SETS.analytics)[number];

const RANGES = rangeOptions(RANGE_SETS.analytics);

const card =
    'rounded-[14px] border bg-card shadow-[0_1px_2px_rgb(0_0_0/0.04)] dark:shadow-none';

/**
 * Project → Analytics → Traffic (docs/15 §5.4, D75): how many requests the
 * environment's services served and of what kind. Requests per hour (per
 * day for a month) stacked by status class, totals with the change against the period
 * before, the top paths with their error shares, referrer hosts, client
 * classes, and the split by server and service. Refetched every minute.
 */
export function ProjectAnalytics({
    project,
    projectName,
    environment,
    environmentName,
    services,
    initialService,
}: {
    project: string;
    projectName: string;
    environment: string;
    environmentName: string;
    services: Service[];
    initialService: string | null;
}) {
    // The observe scope (lib/observe-scope.ts): range and service in the
    // URL, shared with Resources and Logs. The URL wins over the props
    // (history entries keep the props of the first visit).
    const scope = useObserveScope(RANGE_SETS.analytics);
    const { range, setRange, snapped } = scope;
    const [focus, setService] = useAnalyticsService(
        scope,
        services,
        initialService,
    );
    const selected = focus?.id ?? null;

    const { data, isError } = useQuery({
        queryKey: ['project-analytics', project, environment, selected, range],
        queryFn: ({ signal }) =>
            requestJson<Analytics>(
                'get',
                analyticsRoute(project, {
                    query: {
                        environment,
                        range,
                        ...(selected ? { service: selected } : {}),
                    },
                }).url,
                undefined,
                signal,
            ),
        refetchInterval: 60_000,
        retry: false,
        staleTime: 30_000,
        placeholderData: (previous) => previous,
    });

    const times = useMemo(
        () => (data?.timestamps ?? []).map((t) => Date.parse(t)),
        [data],
    );
    const serviceName = focus?.name;
    const unit = range === '30d' ? 'day' : 'hour';

    return (
        <>
            <AnalyticsHeader
                project={project}
                projectName={projectName}
                environment={environment}
                environmentName={environmentName}
                view="traffic"
                services={services}
                service={focus}
                onService={setService}
                range={range}
                ranges={RANGES}
                onRange={setRange}
                snapped={snapped}
            />

            {isError && !data ? (
                <EmptyState variant="framed">
                    Analytics could not be loaded. The page tries again every
                    minute.
                </EmptyState>
            ) : !data ? (
                <div
                    aria-hidden="true"
                    className="flex animate-skeleton-in flex-col gap-4"
                >
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        {[0, 1, 2, 3].map((i) => (
                            <div key={i} className={cn(card, 'h-[112px]')} />
                        ))}
                    </div>
                    <div className={cn(card, 'h-[300px]')} />
                </div>
            ) : data.totals.requests === 0 ? (
                <EmptyState icon={BarChart3} variant="framed">
                    {range === '24h'
                        ? 'No requests in the last 24 hours. Requests appear here a minute after they reach one of your domains.'
                        : 'No requests in this range yet. Requests appear here a minute after they reach one of your domains.'}
                </EmptyState>
            ) : (
                <Report
                    data={data}
                    times={times}
                    range={range}
                    unit={unit}
                    showServices={selected === null}
                    services={services}
                    onPickService={setService}
                    securityHref={
                        securityRoute({
                            query: selected
                                ? { service: selected }
                                : { project },
                        }).url + '#firewall'
                    }
                    drill={(span) => {
                        const logs = logsPage([project, environment]).url;
                        const name = serviceName
                            ? `service:${/\s/.test(serviceName) ? `"${serviceName}"` : serviceName} `
                            : '';

                        return [
                            {
                                label: `Logs from ${formatWindow(span)}`,
                                href: withScope(logs, {
                                    service: selected,
                                    window: span,
                                }),
                                icon: ScrollText,
                            },
                            {
                                label: `Errors from ${formatWindow(span)}`,
                                href: withScope(
                                    `${logs}?q=${encodeURIComponent(`${name}level:error`)}`,
                                    { window: span },
                                ),
                                icon: TriangleAlert,
                            },
                        ];
                    }}
                />
            )}
        </>
    );
}

function Report({
    data,
    times,
    range,
    unit,
    showServices,
    services,
    onPickService,
    securityHref,
    drill,
}: {
    data: Analytics;
    times: number[];
    range: Range;
    unit: 'hour' | 'day';
    showServices: boolean;
    services: Service[];
    onPickService: (id: string) => void;
    /** The Security page scoped like this view, at its firewall section. */
    securityHref: string;
    drill: ChartDrill;
}) {
    const totals = data.totals;
    const clientErrors = share(totals.status4xx, totals.requests);
    const blocked = share(totals.blocked, totals.requests);
    const peak = Math.max(
        ...times.map(
            (_, i) =>
                data.status2xx[i] +
                data.status3xx[i] +
                data.status4xx[i] +
                data.status5xx[i] +
                data.blocked[i] +
                data.statusOther[i],
        ),
    );

    return (
        <div className="flex flex-col gap-8">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatTile
                    label="Requests"
                    value={formatCompact(totals.requests)}
                    delta={delta(totals.requests, totals.previousRequests)}
                    hint={`Peak ${formatCompact(peak)} per ${unit}`}
                />
                <StatTile
                    label="Server errors"
                    value={
                        totals.errorRatePct !== null
                            ? formatErrorRate(totals.errorRatePct)
                            : null
                    }
                    delta={delta(
                        totals.errorRatePct,
                        totals.previousErrorRatePct,
                        'lowerIsBetter',
                        0.1,
                    )}
                    hint={`${formatCompact(totals.status5xx)} responses with 5xx`}
                />
                <StatTile
                    label="Client errors"
                    value={formatShare(clientErrors)}
                    hint={`${formatCompact(totals.status4xx)} responses with 4xx`}
                />
                <StatTile
                    label="Blocked"
                    value={formatCompact(totals.blocked)}
                    delta={delta(totals.blocked, totals.previousBlocked)}
                    hint={
                        totals.blocked > 0
                            ? `${formatShare(blocked)} of requests, refused by the firewall`
                            : 'Nothing refused by the firewall'
                    }
                    action={
                        <Link
                            href={securityHref}
                            className="type-small text-muted-foreground hover:text-foreground"
                        >
                            Security
                        </Link>
                    }
                />
            </div>

            <Section
                title={`Requests per ${unit}`}
                description={`By status, ${rangeWords(range)}`}
            >
                <div className={cn(card, 'p-5')}>
                    <StackedBars
                        title={`Requests per ${unit}, ${rangeWords(range)}`}
                        times={times}
                        bucketMs={data.bucketSeconds * 1000}
                        series={statusSeries(data)}
                        height={240}
                        markers={deployMarkers(data.deploys)}
                        drill={drill}
                    />
                </div>
            </Section>

            {data.pathRequests === 0 ? (
                <TopPaths data={data} />
            ) : (
                <div className="grid grid-cols-1 gap-8 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                    <TopPaths data={data} />
                    <div className="flex flex-col gap-8">
                        <ShareList
                            title="Referrers"
                            description="Sites that linked here"
                            rows={referrerRows(data)}
                            empty="No requests came from other sites."
                            mono
                        />
                        <ShareList
                            title="Clients"
                            description="Based on the user agent only"
                            rows={agentRows(data)}
                            empty="No client information yet."
                        />
                    </div>
                </div>
            )}

            {data.servers.length > 1 && (
                <SplitList
                    title="Servers"
                    description="Which servers answered the requests"
                    rows={data.servers}
                    total={totals.requests}
                />
            )}

            {showServices && data.services.length > 1 && (
                <SplitList
                    title="Services"
                    description="Select one to see its paths"
                    rows={data.services}
                    total={totals.requests}
                    services={services}
                    onPick={onPickService}
                />
            )}
        </div>
    );
}

function TopPaths({ data }: { data: Analytics }) {
    const rows = pathRows(data);
    const waiting = data.pathRequests === 0;

    return (
        <Section
            title="Top paths"
            description="IDs are shown as :id. Query strings are not stored."
        >
            {waiting ? (
                <EmptyState variant="framed">
                    {data.serversWithoutPaths.length > 0
                        ? `Update ${data.serversWithoutPaths.join(', ')} to see the most requested paths.`
                        : 'The most requested paths appear here within 10 minutes of the first request.'}
                </EmptyState>
            ) : (
                <div className={cn(card, 'overflow-hidden')}>
                    <table className="w-full table-fixed type-small tabular-nums">
                        <caption className="sr-only">
                            Most requested paths with their share of requests
                            and error rates
                        </caption>
                        <thead className="text-left text-muted-foreground">
                            <tr className="border-b">
                                <th
                                    scope="col"
                                    className="px-4 py-2.5 font-medium"
                                >
                                    Path
                                </th>
                                <th
                                    scope="col"
                                    className="w-24 px-4 py-2.5 text-right font-medium sm:w-36"
                                >
                                    Requests
                                </th>
                                <th
                                    scope="col"
                                    className="hidden w-20 px-4 py-2.5 text-right font-medium sm:table-cell"
                                >
                                    4xx
                                </th>
                                <th
                                    scope="col"
                                    className="w-20 px-4 py-2.5 text-right font-medium"
                                >
                                    5xx
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row) => (
                                <tr
                                    key={row.key}
                                    className="border-b last:border-0"
                                >
                                    <th
                                        scope="row"
                                        className="relative px-4 py-2 text-left font-normal"
                                    >
                                        <span
                                            aria-hidden="true"
                                            className="absolute inset-y-1 left-0 rounded-r-sm bg-(--signal-soft)"
                                            style={{
                                                width: `${Math.max(row.sharePct, 0.5)}%`,
                                            }}
                                        />
                                        <span
                                            className={cn(
                                                'relative block truncate',
                                                row.other
                                                    ? 'text-muted-foreground'
                                                    : 'font-mono',
                                            )}
                                            title={row.path}
                                        >
                                            {row.path}
                                        </span>
                                        {row.blocked > 0 && (
                                            <span className="relative mt-0.5 block text-muted-foreground">
                                                {formatCompact(row.blocked)}{' '}
                                                blocked by the firewall
                                            </span>
                                        )}
                                    </th>
                                    <td className="px-4 py-2 text-right">
                                        {formatCompact(row.requests)}
                                        <span className="ml-1.5 hidden text-muted-foreground sm:inline">
                                            {formatShare(row.sharePct)}
                                        </span>
                                    </td>
                                    <td className="hidden px-4 py-2 text-right text-muted-foreground sm:table-cell">
                                        {formatShare(row.clientErrorPct)}
                                    </td>
                                    <td
                                        className={cn(
                                            'px-4 py-2 text-right',
                                            row.serverErrorPct
                                                ? 'text-status-error'
                                                : 'text-muted-foreground',
                                        )}
                                    >
                                        {formatShare(row.serverErrorPct)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </Section>
    );
}

function ShareList({
    title,
    description,
    rows,
    empty,
    mono = false,
}: {
    title: string;
    description: string;
    rows: ShareRow[];
    empty: string;
    mono?: boolean;
}) {
    return (
        <Section title={title} description={description}>
            {rows.length === 0 ? (
                <p className="type-small text-muted-foreground">{empty}</p>
            ) : (
                <ul className={cn(card, 'divide-y')}>
                    {rows.map((row) => (
                        <li
                            key={row.key}
                            className="relative flex items-center gap-3 px-4 py-2 type-small tabular-nums"
                        >
                            <span
                                aria-hidden="true"
                                className="absolute inset-y-1 left-0 rounded-r-sm bg-(--signal-soft)"
                                style={{
                                    width: `${Math.max(row.sharePct, 0.5)}%`,
                                }}
                            />
                            <span
                                className={cn(
                                    'relative min-w-0 flex-1 truncate',
                                    mono && row.key !== '__other'
                                        ? 'font-mono'
                                        : undefined,
                                    row.key === '__other'
                                        ? 'text-muted-foreground'
                                        : undefined,
                                )}
                                title={row.label}
                            >
                                {row.label}
                            </span>
                            <span className="relative">
                                {formatCompact(row.value)}
                            </span>
                            <span className="relative w-12 text-right text-muted-foreground">
                                {formatShare(row.sharePct)}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </Section>
    );
}

function SplitList({
    title,
    description,
    rows,
    total,
    services,
    onPick,
}: {
    title: string;
    description: string;
    rows: Split[];
    total: number;
    services?: Service[];
    onPick?: (id: string) => void;
}) {
    const logos = new Map((services ?? []).map((s) => [s.id, s.logo]));

    return (
        <Section title={title} description={description}>
            <ul className={cn(card, 'divide-y')}>
                {rows.map((row) => {
                    const pct = share(row.requests, total) ?? 0;
                    const content = (
                        <>
                            {logos.has(row.id) && (
                                <Logo
                                    kind={logos.get(row.id)!}
                                    name={row.name}
                                    size={32}
                                    decorative
                                />
                            )}
                            <span className="flex min-w-0 flex-1 flex-col gap-1.5">
                                <span className="flex items-baseline gap-2">
                                    <span className="truncate type-body font-medium">
                                        {row.name}
                                        {row.removed && (
                                            <span className="font-normal text-muted-foreground">
                                                {' '}
                                                (removed)
                                            </span>
                                        )}
                                    </span>
                                    <MetaLine className="ml-auto shrink-0 type-small text-muted-foreground tabular-nums">
                                        {formatCompact(row.requests)}
                                        {formatShare(pct)}
                                        {row.errorRatePct
                                            ? `${formatErrorRate(row.errorRatePct)} errors`
                                            : null}
                                    </MetaLine>
                                </span>
                                <span
                                    aria-hidden="true"
                                    className="h-1.5 overflow-hidden rounded-full bg-(--border)"
                                >
                                    <span
                                        className="block h-full rounded-full bg-(--signal)"
                                        style={{ width: `${pct}%` }}
                                    />
                                </span>
                            </span>
                        </>
                    );

                    return (
                        <li key={row.id}>
                            {onPick ? (
                                <button
                                    type="button"
                                    onClick={() => onPick(row.id)}
                                    className="flex w-full items-center gap-4 px-5 py-3.5 text-left hover:bg-(--hover) focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-(--signal)"
                                >
                                    {content}
                                </button>
                            ) : (
                                <div className="flex items-center gap-4 px-5 py-3.5">
                                    {content}
                                </div>
                            )}
                        </li>
                    );
                })}
            </ul>
        </Section>
    );
}
