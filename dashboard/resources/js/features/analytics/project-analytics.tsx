import { Link } from '@inertiajs/react';
import { useQuery } from '@tanstack/react-query';
import { BarChart3 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { formatCompact } from '@/components/charts/format';
import { StackedBars } from '@/components/charts/stacked-bars';
import { StatTile } from '@/components/charts/stat-tile';
import { TimeRangeSelect } from '@/components/charts/time-range-select';
import type { TimeRangeOption } from '@/components/charts/time-range-select';
import { Logo } from '@/components/logo';
import { EmptyState } from '@/components/patterns/empty-state';
import { MetaLine } from '@/components/patterns/meta-line';
import { PageHeader } from '@/components/patterns/page';
import { Section } from '@/components/patterns/section';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    agentRows,
    formatShare,
    pathRows,
    referrerRows,
    share,
    statusSeries,
} from '@/features/analytics/shape';
import type { ShareRow } from '@/features/analytics/shape';
import { delta, formatErrorRate } from '@/features/metrics/format';
import { requestJson } from '@/features/projects/http';
import { cn } from '@/lib/utils';
import { index as teamAnalytics } from '@/routes/analytics';
import { analytics as analyticsRoute } from '@/routes/projects';

type Analytics = App.Data.Analytics.AnalyticsData;
type Split = App.Data.Analytics.AnalyticsSplitData;
type Service = App.Data.Metrics.MetricsServiceData;
type Range = '24h' | '7d' | '30d';

const RANGES: TimeRangeOption<Range>[] = [
    { value: '24h', label: 'Day' },
    { value: '7d', label: 'Week' },
    { value: '30d', label: 'Month' },
];

const RANGE_WORDS: Record<Range, string> = {
    '24h': 'last 24 hours',
    '7d': 'last 7 days',
    '30d': 'last 30 days',
};

const ALL = 'all';

const card =
    'rounded-[14px] border bg-card shadow-[0_1px_2px_rgb(0_0_0/0.04)] dark:shadow-none';

/**
 * Project → Analytics (docs/15 §5.4): how many requests the environment's
 * services served and of what kind. Requests per hour (per day for a month)
 * stacked by status class, totals with the change against the period
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
    const [range, setRange] = useState<Range>('7d');
    const [service, setServiceState] = useState<string>(() => {
        // The URL wins (history entries keep the props of the first visit).
        const fromUrl =
            typeof window === 'undefined'
                ? null
                : new URLSearchParams(window.location.search).get('service');
        const wanted = fromUrl ?? initialService;

        return services.some((s) => s.id === wanted) && wanted ? wanted : ALL;
    });
    const selected = service === ALL ? null : service;
    const setService = (next: string) => {
        setServiceState(next);
        syncServiceToUrl(next === ALL ? null : next);
    };

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
    const serviceName = services.find((s) => s.id === selected)?.name;
    const scope = serviceName ?? 'All services';
    const unit = range === '30d' ? 'day' : 'hour';

    return (
        <>
            <AnalyticsBreadcrumbs
                projectName={projectName}
                serviceName={serviceName ?? null}
                onProject={() => setService(ALL)}
            />
            <PageHeader
                title="Analytics"
                description={`${scope} in ${environmentName}, ${RANGE_WORDS[range]}`}
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        {services.length > 1 && (
                            <Select value={service} onValueChange={setService}>
                                <SelectTrigger
                                    size="sm"
                                    className="h-9 w-auto min-w-40 gap-2"
                                    aria-label="Service"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL}>
                                        All services
                                    </SelectItem>
                                    {services.map((s) => (
                                        <SelectItem key={s.id} value={s.id}>
                                            {s.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        )}
                        <TimeRangeSelect<Range>
                            value={range}
                            onChange={setRange}
                            options={RANGES}
                        />
                    </div>
                }
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
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        {[0, 1, 2].map((i) => (
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
                />
            )}
        </>
    );
}

/** Keeps `?service=` in the address bar in step with the service select. */
function syncServiceToUrl(service: string | null) {
    if (typeof window === 'undefined') {
        return;
    }

    const url = new URL(window.location.href);

    if (service) {
        url.searchParams.set('service', service);
    } else {
        url.searchParams.delete('service');
    }

    window.history.replaceState(window.history.state, '', url);
}

/**
 * Where this view sits in the drill-down: Analytics (every project) /
 * project / service. The project crumb clears the service filter.
 */
function AnalyticsBreadcrumbs({
    projectName,
    serviceName,
    onProject,
}: {
    projectName: string;
    serviceName: string | null;
    onProject: () => void;
}) {
    const crumb =
        'rounded-sm text-muted-foreground transition-colors duration-fast hover:text-foreground focus-visible:outline-2 focus-visible:outline-(--signal)';

    return (
        <nav aria-label="Analytics" className="-mb-2">
            <ol className="flex min-w-0 items-center gap-1.5 type-small">
                <li>
                    <Link href={teamAnalytics()} className={crumb}>
                        All projects
                    </Link>
                </li>
                <li aria-hidden="true" className="text-faint">
                    /
                </li>
                <li className="min-w-0 truncate">
                    {serviceName ? (
                        <button
                            type="button"
                            onClick={onProject}
                            className={crumb}
                        >
                            {projectName}
                        </button>
                    ) : (
                        <span aria-current="page" className="text-foreground">
                            {projectName}
                        </span>
                    )}
                </li>
                {serviceName && (
                    <>
                        <li aria-hidden="true" className="text-faint">
                            /
                        </li>
                        <li className="min-w-0 truncate">
                            <span
                                aria-current="page"
                                className="text-foreground"
                            >
                                {serviceName}
                            </span>
                        </li>
                    </>
                )}
            </ol>
        </nav>
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
}: {
    data: Analytics;
    times: number[];
    range: Range;
    unit: 'hour' | 'day';
    showServices: boolean;
    services: Service[];
    onPickService: (id: string) => void;
}) {
    const totals = data.totals;
    const clientErrors = share(totals.status4xx, totals.requests);
    const peak = Math.max(
        ...times.map(
            (_, i) =>
                data.status2xx[i] +
                data.status3xx[i] +
                data.status4xx[i] +
                data.status5xx[i] +
                data.statusOther[i],
        ),
    );

    return (
        <div className="flex flex-col gap-8">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
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
            </div>

            <Section
                title={`Requests per ${unit}`}
                description={`By status, ${RANGE_WORDS[range]}`}
            >
                <div className={cn(card, 'p-5')}>
                    <StackedBars
                        title={`Requests per ${unit}, ${RANGE_WORDS[range]}`}
                        times={times}
                        bucketMs={data.bucketSeconds * 1000}
                        series={statusSeries(data)}
                        height={240}
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
