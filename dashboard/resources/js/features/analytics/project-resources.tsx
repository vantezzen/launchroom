import { useQuery } from '@tanstack/react-query';
import { ChevronRight } from 'lucide-react';
import { useMemo, useState } from 'react';
import { formatBytes } from '@/components/charts/format';
import { Sparkline } from '@/components/charts/sparkline';
import { TimeSeriesChart } from '@/components/charts/time-series-chart';
import type { TimeSeries } from '@/components/charts/time-series-chart';
import { Logo } from '@/components/logo';
import { EmptyState } from '@/components/patterns/empty-state';
import { MetaLine } from '@/components/patterns/meta-line';
import { Section } from '@/components/patterns/section';
import {
    AnalyticsHeader,
    useAnalyticsService,
} from '@/features/analytics/analytics-header';
import {
    delta,
    deployMarkers,
    formatBandwidth,
    formatCores,
    formatMemory,
    formatMs,
    points,
} from '@/features/metrics/format';
import { MetricCard } from '@/features/metrics/metric-card';
import type { MetricCardRow } from '@/features/metrics/metric-card';
import { requestJson } from '@/features/projects/http';
import { useObserveScope } from '@/hooks/use-observe-scope';
import { logsDrill } from '@/lib/links';
import {
    RANGE_SECONDS,
    RANGE_SETS,
    rangeOptions,
    rangeWords,
} from '@/lib/observe-scope';
import { metrics as metricsRoute } from '@/routes/projects';

type Metrics = App.Data.Metrics.ProjectMetricsData;
type Usage = App.Data.Metrics.ServiceUsageSeriesData;
type Service = App.Data.Metrics.MetricsServiceData;
type MetricKey = 'cpu' | 'memory' | 'latency' | 'bandwidth';

const RANGES = rangeOptions(RANGE_SETS.resources);

const TOP = 3;

const card =
    'rounded-[14px] border bg-card shadow-[0_1px_2px_rgb(0_0_0/0.04)] dark:shadow-none';

const average = (values: (number | null)[]): number | null => {
    const present = values.filter((v): v is number => v !== null);

    return present.length === 0
        ? null
        : present.reduce((sum, v) => sum + v, 0) / present.length;
};

/** Sums several aligned series; null where every input is null. */
function sumSeries(series: (number | null)[][]): (number | null)[] {
    const length = Math.max(0, ...series.map((s) => s.length));

    return Array.from({ length }, (_, i) => {
        const vs = series.map((s) => s[i] ?? null);

        return vs.every((v) => v === null)
            ? null
            : vs.reduce<number>((sum, v) => sum + (v ?? 0), 0);
    });
}

/**
 * Project → Analytics → Resources (docs/15 §2.3, D75), in the spirit of a
 * CDN overview: metric cards for CPU and memory, and for services with
 * public traffic latency and bandwidth, each with the range value, a delta
 * against the period before and the top services as stacked sparklines;
 * the selected card's chart with the deploy markers; then one row per
 * service that filters the view to it. Filtered to one service, CPU and
 * memory draw one line per replica. Requests and errors live in Traffic.
 * Refetched every minute (samples are per minute).
 */
export function ProjectResources({
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
    // The observe scope (lib/observe-scope.ts): range and service live in
    // the URL, shared with Traffic and Logs.
    const scope = useObserveScope(RANGE_SETS.resources);
    const { range, setRange, snapped } = scope;
    const [focus, setFocus] = useAnalyticsService(
        scope,
        services,
        initialService,
    );
    const [picked, setPicked] = useState<MetricKey | null>(null);

    const { data, isError } = useQuery({
        queryKey: ['project-metrics', project, environment, focus?.id, range],
        queryFn: ({ signal }) =>
            requestJson<Metrics>(
                'get',
                metricsRoute(project, {
                    query: {
                        environment,
                        range,
                        ...(focus ? { service: focus.id } : {}),
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
    const markers = useMemo(
        () => deployMarkers(data?.deploys, focus?.id),
        [data, focus],
    );
    const drill = useMemo(
        () =>
            logsDrill({
                project,
                environment,
                service: focus?.id ?? null,
            }),
        [project, environment, focus],
    );
    const logos = useMemo(
        () => new Map(services.map((s) => [s.id, s.logo])),
        [services],
    );
    const traffic = data?.totals?.requests ?? null;
    const totals = data?.totals ?? null;
    const usage = data?.services ?? [];
    const withTraffic = usage
        .filter((s) => s.requests !== null)
        .sort(
            (a, b) =>
                (b.requests?.totals.requests ?? 0) -
                (a.requests?.totals.requests ?? 0),
        );
    const hasTraffic = Boolean(traffic && traffic.totals.requests > 0);
    const selected: MetricKey =
        picked && (hasTraffic || picked === 'cpu' || picked === 'memory')
            ? picked
            : 'cpu';
    const seconds = RANGE_SECONDS[range];

    const rows = (
        list: Usage[],
        pick: (s: Usage) => (number | null)[],
        format: (s: Usage) => string,
        other?: { values: (number | null)[]; value: string },
    ): MetricCardRow[] => {
        const top = list.slice(0, TOP).map((s) => ({
            key: s.serviceId,
            label: s.serviceName,
            logo: logos.get(s.serviceId),
            values: pick(s),
            value: format(s),
        }));

        return list.length > TOP && other
            ? [
                  ...top,
                  {
                      key: '__other',
                      label: `Other (${list.length - TOP})`,
                      ...other,
                  },
              ]
            : top;
    };

    const byCpu = [...usage].sort(
        (a, b) => (average(b.cpuCores) ?? -1) - (average(a.cpuCores) ?? -1),
    );
    const byMem = [...usage].sort(
        (a, b) => (average(b.memMb) ?? -1) - (average(a.memMb) ?? -1),
    );
    const rest = (list: Usage[]) => list.slice(TOP);

    const cards: {
        key: MetricKey;
        label: string;
        value: string | null;
        unit?: string;
        rows: MetricCardRow[];
        delta?: ReturnType<typeof delta>;
        hint?: string;
    }[] = [
        {
            key: 'cpu',
            label: 'CPU',
            value: totals?.cpuAvg != null ? formatCores(totals.cpuAvg) : null,
            unit: totals?.cpuAvg != null ? 'cores, avg' : undefined,
            delta: delta(totals?.cpuAvg, totals?.previousCpuAvg),
            hint: 'No usage recorded in this range.',
            rows: rows(
                byCpu.filter((s) => s.cpuCores.length > 0),
                (s) => s.cpuCores,
                (s) => {
                    const v = average(s.cpuCores);

                    return v === null ? '–' : formatCores(v);
                },
                {
                    values: sumSeries(rest(byCpu).map((s) => s.cpuCores)),
                    value: formatCores(
                        rest(byCpu).reduce(
                            (sum, s) => sum + (average(s.cpuCores) ?? 0),
                            0,
                        ),
                    ),
                },
            ),
        },
        {
            key: 'memory',
            label: 'Memory',
            value: totals?.memAvg != null ? formatMemory(totals.memAvg) : null,
            unit: totals?.memAvg != null ? 'avg' : undefined,
            delta: delta(totals?.memAvg, totals?.previousMemAvg),
            hint: 'No usage recorded in this range.',
            rows: rows(
                byMem.filter((s) => s.memMb.length > 0),
                (s) => s.memMb,
                (s) => {
                    const v = average(s.memMb);

                    return v === null ? '–' : formatMemory(v);
                },
                {
                    values: sumSeries(rest(byMem).map((s) => s.memMb)),
                    value: formatMemory(
                        rest(byMem).reduce(
                            (sum, s) => sum + (average(s.memMb) ?? 0),
                            0,
                        ),
                    ),
                },
            ),
        },
        ...(hasTraffic && traffic
            ? [
                  {
                      key: 'latency' as const,
                      label: 'Latency p95',
                      value:
                          traffic.totals.p95Ms != null
                              ? formatMs(traffic.totals.p95Ms)
                              : null,
                      delta: delta(
                          traffic.totals.p95Ms,
                          traffic.previous?.p95Ms,
                          'lowerIsBetter',
                          5,
                      ),
                      rows: rows(
                          withTraffic,
                          (s) => s.requests?.p95Ms ?? [],
                          (s) =>
                              s.requests?.totals.p95Ms != null
                                  ? formatMs(s.requests.totals.p95Ms)
                                  : '–',
                      ),
                  },
                  {
                      key: 'bandwidth' as const,
                      label: 'Bandwidth',
                      value: formatBytes(traffic.totals.bytesOut),
                      unit: formatBandwidth(traffic.totals.bytesOut / seconds),
                      delta: delta(
                          traffic.totals.bytesOut,
                          traffic.previous?.bytesOut,
                      ),
                      rows: rows(
                          [...withTraffic].sort(
                              (a, b) =>
                                  (b.requests?.totals.bytesOut ?? 0) -
                                  (a.requests?.totals.bytesOut ?? 0),
                          ),
                          (s) => s.requests?.bytesOutPerSecond ?? [],
                          (s) => formatBytes(s.requests?.totals.bytesOut ?? 0),
                      ),
                  },
              ]
            : []),
    ];

    // Filtered to one service, CPU and memory show its replicas.
    const replicas =
        focus && usage[0] && usage[0].replicas.length > 1
            ? usage[0].replicas
            : [];
    const usageSeries = (
        pick: 'cpuCores' | 'memMb',
        label: string,
    ): TimeSeries[] =>
        replicas.length > 0
            ? replicas.map((replica, index) => ({
                  key: `r${replica.replica}`,
                  label: `Replica ${replica.replica + 1}`,
                  slot: index + 1,
                  points: points(replica[pick], times),
              }))
            : [
                  {
                      key: pick,
                      label,
                      points: points(totals?.[pick] ?? [], times),
                  },
              ];

    const chart = (): {
        series: TimeSeries[];
        format: (v: number) => string;
    } => {
        switch (selected) {
            case 'cpu':
                return {
                    series: usageSeries('cpuCores', 'CPU'),
                    format: formatCores,
                };
            case 'memory':
                return {
                    series: usageSeries('memMb', 'Memory'),
                    format: formatMemory,
                };
            case 'latency':
                return {
                    series: [
                        {
                            key: 'p50',
                            label: 'p50',
                            slot: 1,
                            points: points(traffic?.p50Ms ?? [], times),
                        },
                        {
                            key: 'p95',
                            label: 'p95',
                            slot: 2,
                            points: points(traffic?.p95Ms ?? [], times),
                        },
                    ],
                    format: formatMs,
                };
            case 'bandwidth':
                return {
                    series: [
                        {
                            key: 'out',
                            label: 'Sent',
                            points: points(
                                traffic?.bytesOutPerSecond ?? [],
                                times,
                            ),
                        },
                    ],
                    format: formatBandwidth,
                };
        }
    };

    const active = cards.find((c) => c.key === selected) ?? cards[0];
    const big = chart();

    return (
        <>
            <AnalyticsHeader
                project={project}
                projectName={projectName}
                environment={environment}
                environmentName={environmentName}
                view="resources"
                services={services}
                service={focus}
                onService={setFocus}
                range={range}
                ranges={RANGES}
                onRange={setRange}
                snapped={snapped}
            />

            {isError && !data ? (
                <EmptyState variant="framed">
                    Resources could not be loaded. The page tries again every
                    minute.
                </EmptyState>
            ) : !data ? (
                <div
                    aria-hidden="true"
                    className="grid animate-skeleton-in grid-cols-1 gap-4 sm:grid-cols-2"
                >
                    {[0, 1].map((i) => (
                        <div
                            key={i}
                            className="h-[188px] rounded-[14px] border bg-card"
                        />
                    ))}
                </div>
            ) : (
                <>
                    <div
                        className={
                            cards.length > 2
                                ? 'grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4'
                                : 'grid grid-cols-1 gap-4 sm:grid-cols-2'
                        }
                    >
                        {cards.map((c) => (
                            <MetricCard
                                key={c.key}
                                label={c.label}
                                value={c.value}
                                unit={c.unit}
                                delta={c.delta}
                                rows={c.rows}
                                min={0}
                                hint={c.hint}
                                selected={selected === c.key}
                                onSelect={() => setPicked(c.key)}
                            />
                        ))}
                    </div>

                    <Section
                        title={active.label}
                        description={`${focus?.name ?? 'All services'}, ${rangeWords(range)}`}
                    >
                        <div className={`${card} p-5`}>
                            <TimeSeriesChart
                                title={`${active.label}, ${rangeWords(range)}`}
                                series={big.series}
                                formatValue={big.format}
                                markers={markers}
                                drill={drill}
                                height={240}
                                empty={
                                    <p className="py-16 text-center type-body text-muted-foreground">
                                        Nothing recorded in this range yet.
                                        Usage arrives about a minute after a
                                        service starts.
                                    </p>
                                }
                            />
                        </div>
                    </Section>

                    {!focus && (
                        <ServiceRows
                            services={services}
                            usage={usage}
                            onPick={(id) => setFocus(id)}
                        />
                    )}
                </>
            )}
        </>
    );
}

function ServiceRows({
    services,
    usage,
    onPick,
}: {
    services: Service[];
    usage: Usage[];
    onPick: (id: string) => void;
}) {
    const byId = new Map(usage.map((u) => [u.serviceId, u]));

    if (services.length < 2) {
        return null;
    }

    return (
        <Section
            title="Services"
            description="Select one to see only its usage"
        >
            <ul className={`${card} divide-y`}>
                {services.map((service) => {
                    const u = byId.get(service.id);
                    const cpu = u ? average(u.cpuCores) : null;
                    const mem = u ? average(u.memMb) : null;
                    const p95 = u?.requests?.totals.p95Ms ?? null;
                    const facts = [
                        cpu !== null ? `CPU ${formatCores(cpu)}` : null,
                        mem !== null ? formatMemory(mem) : null,
                        p95 !== null ? `p95 ${formatMs(p95)}` : null,
                    ];
                    const hasFacts = facts.some(Boolean);

                    return (
                        <li key={service.id}>
                            <button
                                type="button"
                                onClick={() => onPick(service.id)}
                                className="grid w-full grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-4 px-5 py-3.5 text-left hover:bg-(--hover) focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-(--signal) sm:grid-cols-[auto_minmax(0,1fr)_160px_auto]"
                            >
                                <Logo
                                    kind={service.logo}
                                    name={service.name}
                                    size={32}
                                    decorative
                                />
                                <span className="flex min-w-0 flex-col">
                                    <span className="truncate type-body font-medium">
                                        {service.name}
                                    </span>
                                    <span className="truncate type-small text-muted-foreground tabular-nums">
                                        {hasFacts ? (
                                            <MetaLine>{facts}</MetaLine>
                                        ) : (
                                            'No usage in this range'
                                        )}
                                    </span>
                                </span>
                                <span className="hidden sm:block">
                                    <Sparkline
                                        values={u?.cpuCores ?? []}
                                        height={24}
                                        min={0}
                                        label={`${service.name} CPU trend`}
                                    />
                                </span>
                                <ChevronRight
                                    aria-hidden="true"
                                    className="size-4 text-muted-foreground"
                                />
                            </button>
                        </li>
                    );
                })}
            </ul>
        </Section>
    );
}
