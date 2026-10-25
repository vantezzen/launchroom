import { Link } from '@inertiajs/react';
import { useQuery } from '@tanstack/react-query';
import { ChartColumnStacked, ChevronRight } from 'lucide-react';
import { useMemo, useState } from 'react';
import { formatCompact } from '@/components/charts/format';
import { Sparkline } from '@/components/charts/sparkline';
import { StackedBars } from '@/components/charts/stacked-bars';
import { StatTile } from '@/components/charts/stat-tile';
import { TimeRangeSelect } from '@/components/charts/time-range-select';
import type { TimeRangeOption } from '@/components/charts/time-range-select';
import { EmptyState } from '@/components/patterns/empty-state';
import { MetaLine } from '@/components/patterns/meta-line';
import { PageHeader } from '@/components/patterns/page';
import { Section } from '@/components/patterns/section';
import { formatShare, projectSeries, share } from '@/features/analytics/shape';
import { delta, formatErrorRate } from '@/features/metrics/format';
import { requestJson } from '@/features/projects/http';
import { cn } from '@/lib/utils';
import { data as dataRoute } from '@/routes/analytics';
import { analyticsPage } from '@/routes/projects';

type Team = App.Data.Analytics.TeamAnalyticsData;
type Project = App.Data.Analytics.AnalyticsProjectData;
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

const card =
    'rounded-[14px] border bg-card shadow-[0_1px_2px_rgb(0_0_0/0.04)] dark:shadow-none';

/** The drill-down link of a project: its production Analytics tab. */
function projectHref(project: Project): string | null {
    return project.environmentId
        ? analyticsPage([project.id, project.environmentId]).url
        : null;
}

/**
 * Analytics (sidebar; docs/15 §5.4): requests of every project of the
 * team, all environments. Totals with the change against the period
 * before, requests per hour (per day for a month) stacked by project, one
 * row per project linking to its Analytics tab, the top paths across
 * projects and the split by server. Refetched every minute.
 */
export function TeamAnalytics({ teamName }: { teamName: string }) {
    const [range, setRange] = useState<Range>('7d');

    const { data, isError } = useQuery({
        queryKey: ['team-analytics', range],
        queryFn: ({ signal }) =>
            requestJson<Team>(
                'get',
                dataRoute({ query: { range } }).url,
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
    const unit = range === '30d' ? 'day' : 'hour';

    return (
        <>
            <PageHeader
                title="Analytics"
                description={`All projects and environments of ${teamName}, ${RANGE_WORDS[range]}`}
                actions={
                    <TimeRangeSelect<Range>
                        value={range}
                        onChange={setRange}
                        options={RANGES}
                    />
                }
            />

            {isError && !data ? (
                <EmptyState variant="framed">
                    Analytics could not be loaded. They refresh every minute.
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
                <EmptyState icon={ChartColumnStacked} variant="framed">
                    No requests in this range yet. Requests appear here a minute
                    after they reach one of your domains.
                </EmptyState>
            ) : (
                <Overview data={data} times={times} range={range} unit={unit} />
            )}
        </>
    );
}

function Overview({
    data,
    times,
    range,
    unit,
}: {
    data: Team;
    times: number[];
    range: Range;
    unit: 'hour' | 'day';
}) {
    const totals = data.totals;
    const series = projectSeries(data);
    const colors = new Map(series.map((s) => [s.key, s.color]));

    return (
        <div className="flex flex-col gap-8">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <StatTile
                    label="Requests"
                    value={formatCompact(totals.requests)}
                    delta={delta(totals.requests, totals.previousRequests)}
                    hint={`${data.projects.length} ${data.projects.length === 1 ? 'project' : 'projects'} with traffic`}
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
                    value={formatShare(
                        share(totals.status4xx, totals.requests),
                    )}
                    hint={`${formatCompact(totals.status4xx)} responses with 4xx`}
                />
            </div>

            <Section
                title={`Requests per ${unit}`}
                description={`By project, ${RANGE_WORDS[range]}`}
            >
                <div className={cn(card, 'p-5')}>
                    <StackedBars
                        title={`Requests per ${unit} by project, ${RANGE_WORDS[range]}`}
                        times={times}
                        bucketMs={data.bucketSeconds * 1000}
                        series={series}
                        height={240}
                    />
                </div>
            </Section>

            <Section
                title="Projects"
                description="Select one to see its paths and services"
            >
                <ul className={cn(card, 'divide-y')}>
                    {data.projects.map((project) => (
                        <ProjectRow
                            key={project.id}
                            project={project}
                            total={totals.requests}
                            color={colors.get(project.id) ?? null}
                        />
                    ))}
                </ul>
            </Section>

            {(data.paths.length > 0 || data.servers.length > 1) && (
                <div
                    className={cn(
                        'grid grid-cols-1 gap-8',
                        data.paths.length > 0 &&
                            data.servers.length > 1 &&
                            'lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]',
                    )}
                >
                    {data.paths.length > 0 && <TopPaths data={data} />}
                    {data.servers.length > 1 && (
                        <Section
                            title="Servers"
                            description="Which servers answered the requests"
                        >
                            <ul className={cn(card, 'divide-y')}>
                                {data.servers.map((server) => {
                                    const pct =
                                        share(
                                            server.requests,
                                            totals.requests,
                                        ) ?? 0;

                                    return (
                                        <li
                                            key={server.id}
                                            className="flex flex-col gap-1.5 px-4 py-3"
                                        >
                                            <span className="flex items-baseline gap-2">
                                                <span className="truncate type-body font-medium">
                                                    {server.name}
                                                    {server.removed && (
                                                        <span className="font-normal text-muted-foreground">
                                                            {' '}
                                                            (removed)
                                                        </span>
                                                    )}
                                                </span>
                                                <MetaLine className="ml-auto shrink-0 type-small text-muted-foreground tabular-nums">
                                                    {formatCompact(
                                                        server.requests,
                                                    )}
                                                    {formatShare(pct)}
                                                </MetaLine>
                                            </span>
                                            <span
                                                aria-hidden="true"
                                                className="h-1.5 overflow-hidden rounded-full bg-(--border)"
                                            >
                                                <span
                                                    className="block h-full rounded-full bg-(--signal)"
                                                    style={{
                                                        width: `${pct}%`,
                                                    }}
                                                />
                                            </span>
                                        </li>
                                    );
                                })}
                            </ul>
                        </Section>
                    )}
                </div>
            )}
        </div>
    );
}

function ProjectRow({
    project,
    total,
    color,
}: {
    project: Project;
    total: number;
    color: string | null;
}) {
    const href = projectHref(project);
    const change = delta(project.requests, project.previousRequests);
    const facts = [
        `${formatCompact(project.requests)} ${project.requests === 1 ? 'request' : 'requests'}`,
        formatShare(share(project.requests, total) ?? 0),
        change ? `${change.label} vs. previous period` : null,
        project.errorRatePct
            ? `${formatErrorRate(project.errorRatePct)} errors`
            : null,
    ].filter(Boolean);
    const content = (
        <>
            <span
                aria-hidden="true"
                className="size-2.5 shrink-0 rounded-[3px]"
                style={{
                    backgroundColor: color ?? 'var(--chart-other)',
                }}
            />
            <span className="flex min-w-0 flex-1 flex-col">
                <span className="truncate type-body font-medium">
                    {project.name}
                </span>
                <MetaLine className="truncate type-small text-muted-foreground tabular-nums">
                    {facts}
                </MetaLine>
            </span>
            <span className="hidden w-40 shrink-0 sm:block">
                <Sparkline
                    values={project.requestsSeries}
                    height={24}
                    min={0}
                    label={`${project.name} requests over time`}
                />
            </span>
            {href && (
                <ChevronRight
                    aria-hidden="true"
                    className="size-4 shrink-0 text-muted-foreground"
                />
            )}
        </>
    );

    return (
        <li>
            {href ? (
                <Link
                    href={href}
                    className="flex items-center gap-4 px-5 py-3.5 hover:bg-(--hover) focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-(--signal)"
                >
                    {content}
                </Link>
            ) : (
                <div className="flex items-center gap-4 px-5 py-3.5">
                    {content}
                </div>
            )}
        </li>
    );
}

function TopPaths({ data }: { data: Team }) {
    return (
        <Section
            title="Top paths"
            description="Across all projects. IDs are shown as :id."
        >
            <div className={cn(card, 'overflow-hidden')}>
                <table className="w-full table-fixed type-small tabular-nums">
                    <caption className="sr-only">
                        Most requested paths across all projects
                    </caption>
                    <thead className="text-left text-muted-foreground">
                        <tr className="border-b">
                            <th scope="col" className="px-4 py-2.5 font-medium">
                                Path
                            </th>
                            <th
                                scope="col"
                                className="w-24 px-4 py-2.5 text-right font-medium"
                            >
                                Requests
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
                        {data.paths.map((row) => {
                            const errors = share(row.status5xx, row.requests);

                            return (
                                <tr
                                    key={`${row.projectId}${row.path}`}
                                    className="border-b last:border-0"
                                >
                                    <th
                                        scope="row"
                                        className="px-4 py-2 text-left font-normal"
                                    >
                                        <span className="block truncate">
                                            <span className="text-muted-foreground">
                                                {row.projectName}
                                            </span>{' '}
                                            <span className="font-mono">
                                                {row.path}
                                            </span>
                                        </span>
                                    </th>
                                    <td className="px-4 py-2 text-right">
                                        {formatCompact(row.requests)}
                                    </td>
                                    <td
                                        className={cn(
                                            'px-4 py-2 text-right',
                                            errors
                                                ? 'text-status-error'
                                                : 'text-muted-foreground',
                                        )}
                                    >
                                        {formatShare(errors)}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
        </Section>
    );
}
