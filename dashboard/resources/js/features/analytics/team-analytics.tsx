import { Link } from '@inertiajs/react';
import { useQuery } from '@tanstack/react-query';
import { ChartColumnStacked, ChevronRight } from 'lucide-react';
import { useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { Sparkline } from '@/components/charts/sparkline';
import { StackedBars } from '@/components/charts/stacked-bars';
import { StatTile } from '@/components/charts/stat-tile';
import { TimeRangeSelect } from '@/components/charts/time-range-select';
import { EmptyState } from '@/components/patterns/empty-state';
import { MetaLine } from '@/components/patterns/meta-line';
import { PageHeader } from '@/components/patterns/page';
import { Section } from '@/components/patterns/section';
import { projectSeries, share } from '@/features/analytics/shape';
import type { TeamMeasure } from '@/features/analytics/shape';
import { delta } from '@/features/metrics/format';
import { requestJson } from '@/lib/http';
import { analyticsHref } from '@/lib/links';
import { useObserveScope } from '@/hooks/use-observe-scope';
import { RANGE_SETS, rangeOptions, rangeWords } from '@/lib/observe-scope';
import { cn } from '@/lib/utils';
import { data as dataRoute } from '@/routes/analytics';
import { index as securityRoute } from '@/routes/security';
import { formatCompact, formatShare } from '@/lib/format';

type Team = App.Data.Analytics.TeamAnalyticsData;
type Project = App.Data.Analytics.AnalyticsProjectData;
type Range = (typeof RANGE_SETS.analytics)[number];

const RANGES = rangeOptions(RANGE_SETS.analytics);

const card =
    'rounded-[14px] border bg-card shadow-[0_1px_2px_rgb(0_0_0/0.04)] dark:shadow-none';

const MEASURE_LABEL: Record<TeamMeasure, string> = {
    requests: 'Requests',
    serverErrors: 'Server errors',
    clientErrors: 'Client errors',
};

/** A tile that picks what the chart below shows (the MetricCard selected state). */
function Selectable({
    selected,
    onSelect,
    children,
}: {
    selected: boolean;
    onSelect: () => void;
    children: ReactNode;
}) {
    return (
        <button
            type="button"
            aria-pressed={selected}
            onClick={onSelect}
            className={cn(
                'flex min-w-0 rounded-[14px] text-left focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--signal) [&>*]:flex-1 hover:[&>*]:border-(--border-strong)',
                selected &&
                    '[&>*]:border-foreground [&>*]:ring-[0.5px] [&>*]:ring-foreground',
            )}
        >
            {children}
        </button>
    );
}

/** The drill-down link of a project: its production Analytics tab, same range. */
function projectHref(project: Project, range: Range): string | null {
    return project.environmentId
        ? analyticsHref({
              project: project.id,
              environment: project.environmentId,
              range,
          })
        : null;
}

/**
 * Analytics (sidebar; docs/15 §5.4): requests of every project of the
 * team, all environments. Totals with the change against the period
 * before (Requests, Server errors and Client errors pick what the chart
 * shows), that measure per hour (per day for a month) stacked by project,
 * in counts or as shares of each bar, one row per project linking to its
 * Analytics tab, the top paths across projects with a bar for their share
 * and the split by server. Refetched every minute.
 */
export function TeamAnalytics({ teamName }: { teamName: string }) {
    const { range, setRange, snapped } = useObserveScope(RANGE_SETS.analytics);

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
                description={`All projects and environments of ${teamName}, ${rangeWords(range)}.${snapped ? ` ${snapped}` : ''}`}
                actions={
                    <TimeRangeSelect
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
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        {[0, 1, 2, 3].map((i) => (
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
    const [measure, setMeasure] = useState<TeamMeasure>('requests');
    const [mode, setMode] = useState<'absolute' | 'share'>('absolute');
    // Project colours follow the requests ranking, whatever the chart shows.
    const colors = new Map(projectSeries(data).map((s) => [s.key, s.color]));
    const series = projectSeries(data, undefined, measure).map((s) => ({
        ...s,
        color: colors.get(s.key) ?? s.color,
    }));
    const measureLabel = MEASURE_LABEL[measure];

    return (
        <div className="flex flex-col gap-8">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Selectable
                    selected={measure === 'requests'}
                    onSelect={() => setMeasure('requests')}
                >
                    <StatTile
                        label="Requests"
                        value={formatCompact(totals.requests)}
                        unit="total"
                        delta={delta(totals.requests, totals.previousRequests)}
                        hint={`${data.projects.length} ${data.projects.length === 1 ? 'project' : 'projects'} with traffic`}
                    />
                </Selectable>
                <Selectable
                    selected={measure === 'serverErrors'}
                    onSelect={() => setMeasure('serverErrors')}
                >
                    <StatTile
                        label="Server errors"
                        value={
                            totals.errorRatePct !== null
                                ? formatShare(totals.errorRatePct)
                                : null
                        }
                        unit="of requests"
                        delta={delta(
                            totals.errorRatePct,
                            totals.previousErrorRatePct,
                            'lowerIsBetter',
                            0.1,
                        )}
                        hint={`${formatCompact(totals.status5xx)} responses with 5xx`}
                    />
                </Selectable>
                <Selectable
                    selected={measure === 'clientErrors'}
                    onSelect={() => setMeasure('clientErrors')}
                >
                    <StatTile
                        label="Client errors"
                        value={formatShare(
                            share(totals.status4xx, totals.requests),
                        )}
                        unit="of requests"
                        hint={`${formatCompact(totals.status4xx)} responses with 4xx`}
                    />
                </Selectable>
                <StatTile
                    label="Blocked"
                    value={formatCompact(totals.blocked)}
                    unit="total"
                    delta={delta(totals.blocked, totals.previousBlocked)}
                    hint={
                        totals.blocked > 0
                            ? `${formatShare(share(totals.blocked, totals.requests))} of requests, refused by the firewall`
                            : 'Nothing refused by the firewall'
                    }
                    action={
                        <Link
                            href={`${securityRoute().url}#firewall`}
                            className="type-small text-muted-foreground hover:text-foreground"
                        >
                            Security
                        </Link>
                    }
                />
            </div>

            <Section
                title={`${measureLabel} per ${unit}`}
                description={`By project, ${rangeWords(range)}.`}
                actions={
                    <TimeRangeSelect<'absolute' | 'share'>
                        value={mode}
                        onChange={setMode}
                        options={[
                            { value: 'absolute', label: 'Absolute' },
                            { value: 'share', label: 'Share' },
                        ]}
                        label="Scale"
                    />
                }
            >
                <div className={cn(card, 'p-5')}>
                    {series.length > 0 ? (
                        <StackedBars
                            title={`${measureLabel} per ${unit} by project, ${rangeWords(range)}`}
                            times={times}
                            bucketMs={data.bucketSeconds * 1000}
                            series={series}
                            height={240}
                            share={mode === 'share'}
                        />
                    ) : (
                        <p className="py-16 text-center type-body text-muted-foreground">
                            No {measureLabel.toLowerCase()} in this range.
                        </p>
                    )}
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
                            range={range}
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
    range,
    total,
    color,
}: {
    project: Project;
    range: Range;
    total: number;
    color: string | null;
}) {
    const href = projectHref(project, range);
    const change = delta(project.requests, project.previousRequests);
    const facts = [
        `${formatCompact(project.requests)} ${project.requests === 1 ? 'request' : 'requests'}`,
        formatShare(share(project.requests, total) ?? 0),
        change ? `${change.label} vs. previous period` : null,
        project.errorRatePct
            ? `${formatShare(project.errorRatePct)} errors`
            : null,
        project.blocked > 0
            ? `${formatCompact(project.blocked)} blocked`
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
    const most = Math.max(1, ...data.paths.map((row) => row.requests));

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
                                        className="relative px-4 py-2 text-left font-normal"
                                    >
                                        <span
                                            aria-hidden="true"
                                            className="absolute inset-y-1 left-1 rounded-md bg-foreground/[0.06]"
                                            style={{
                                                width: `calc(${(row.requests / most) * 100}% - 0.5rem)`,
                                            }}
                                        />
                                        <span className="relative block truncate">
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
