import { Link } from '@inertiajs/react';
import { useQuery } from '@tanstack/react-query';
import { ChevronRight, Globe } from 'lucide-react';
import { useMemo, useState } from 'react';
import { formatBytes, formatCompact } from '@/components/charts/format';
import { Sparkline } from '@/components/charts/sparkline';
import { TimeRangeSelect } from '@/components/charts/time-range-select';
import type { TimeRangeOption } from '@/components/charts/time-range-select';
import { TimeSeriesChart } from '@/components/charts/time-series-chart';
import type { TimeSeries } from '@/components/charts/time-series-chart';
import { Logo } from '@/components/logo';
import { EmptyState } from '@/components/patterns/empty-state';
import { MetaLine } from '@/components/patterns/meta-line';
import { PageHeader } from '@/components/patterns/page';
import { Section } from '@/components/patterns/section';
import {
    delta,
    deployMarkers,
    formatBandwidth,
    formatCores,
    formatErrorRate,
    formatMemory,
    formatMs,
    formatRate,
    points,
} from '@/features/metrics/format';
import { MetricCard } from '@/features/metrics/metric-card';
import type { MetricCardRow } from '@/features/metrics/metric-card';
import { requestJson } from '@/features/projects/http';
import { metrics as metricsRoute } from '@/routes/projects';
import { show as servicePanel } from '@/routes/projects/services';

type Metrics = App.Data.Metrics.ProjectMetricsData;
type Usage = App.Data.Metrics.ServiceUsageSeriesData;
type Service = App.Data.Metrics.MetricsServiceData;
type Range = '1h' | '24h' | '7d';
type MetricKey =
    | 'requests'
    | 'errors'
    | 'latency'
    | 'bandwidth'
    | 'cpu'
    | 'memory';

const RANGES: TimeRangeOption<Range>[] = [
    { value: '1h', label: 'Hour' },
    { value: '24h', label: 'Day' },
    { value: '7d', label: 'Week' },
];

const RANGE_WORDS: Record<Range, string> = {
    '1h': 'last hour',
    '24h': 'last 24 hours',
    '7d': 'last 7 days',
};

const RANGE_SECONDS: Record<Range, number> = {
    '1h': 3600,
    '24h': 86_400,
    '7d': 604_800,
};

const TOP = 3;

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
 * Project → Metrics (docs/15 §2.3), in the spirit of a CDN overview: six
 * metric cards (traffic first, then usage), each with the range value, a
 * delta against the period before and the top services as stacked
 * sparklines; the selected card's environment-wide chart with every
 * service's deploy markers; then one row per service linking to its panel.
 * Refetched every minute (samples are per minute).
 */
export function ProjectMetrics({
    project,
    environment,
    environmentName,
    services,
}: {
    project: string;
    environment: string;
    environmentName: string;
    services: Service[];
}) {
    const [range, setRange] = useState<Range>('24h');
    const [picked, setPicked] = useState<MetricKey | null>(null);

    const { data, isError } = useQuery({
        queryKey: ['project-metrics', project, environment, range],
        queryFn: ({ signal }) =>
            requestJson<Metrics>(
                'get',
                metricsRoute(project, { query: { environment, range } }).url,
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
    const markers = useMemo(() => deployMarkers(data?.deploys), [data]);
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
    const selected: MetricKey = picked ?? (traffic ? 'requests' : 'cpu');
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
        min?: number;
        traffic: boolean;
    }[] = [
        {
            key: 'requests',
            label: 'Requests',
            value: traffic ? formatCompact(traffic.totals.requests) : null,
            unit: traffic
                ? `${formatRate(traffic.totals.requestsPerSecond)}/s`
                : undefined,
            delta: delta(traffic?.totals.requests, traffic?.previous?.requests),
            rows: rows(
                withTraffic,
                (s) => s.requests?.requestsPerSecond ?? [],
                (s) =>
                    `${formatRate(s.requests?.totals.requestsPerSecond ?? 0)}/s`,
                {
                    values: sumSeries(
                        rest(withTraffic).map(
                            (s) => s.requests?.requestsPerSecond ?? [],
                        ),
                    ),
                    value: `${formatRate(rest(withTraffic).reduce((sum, s) => sum + (s.requests?.totals.requestsPerSecond ?? 0), 0))}/s`,
                },
            ),
            min: 0,
            traffic: true,
        },
        {
            key: 'errors',
            label: 'Error rate',
            value:
                traffic?.totals.errorRatePct != null
                    ? formatErrorRate(traffic.totals.errorRatePct)
                    : null,
            delta: delta(
                traffic?.totals.errorRatePct,
                traffic?.previous?.errorRatePct,
                'lowerIsBetter',
                0.1,
            ),
            rows: rows(
                [...withTraffic].sort(
                    (a, b) =>
                        (b.requests?.totals.status5xx ?? 0) -
                        (a.requests?.totals.status5xx ?? 0),
                ),
                (s) => s.requests?.errorRatePct ?? [],
                (s) =>
                    s.requests?.totals.errorRatePct != null
                        ? formatErrorRate(s.requests.totals.errorRatePct)
                        : '–',
            ),
            min: 0,
            traffic: true,
        },
        {
            key: 'latency',
            label: 'Latency p95',
            value:
                traffic?.totals.p95Ms != null
                    ? formatMs(traffic.totals.p95Ms)
                    : null,
            delta: delta(
                traffic?.totals.p95Ms,
                traffic?.previous?.p95Ms,
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
            min: 0,
            traffic: true,
        },
        {
            key: 'bandwidth',
            label: 'Bandwidth',
            value: traffic ? formatBytes(traffic.totals.bytesOut) : null,
            unit: traffic
                ? formatBandwidth(traffic.totals.bytesOut / seconds)
                : undefined,
            delta: delta(traffic?.totals.bytesOut, traffic?.previous?.bytesOut),
            rows: rows(
                [...withTraffic].sort(
                    (a, b) =>
                        (b.requests?.totals.bytesOut ?? 0) -
                        (a.requests?.totals.bytesOut ?? 0),
                ),
                (s) => s.requests?.bytesOutPerSecond ?? [],
                (s) => formatBytes(s.requests?.totals.bytesOut ?? 0),
            ),
            min: 0,
            traffic: true,
        },
        {
            key: 'cpu',
            label: 'CPU',
            value: totals?.cpuAvg != null ? formatCores(totals.cpuAvg) : null,
            unit: totals?.cpuAvg != null ? 'cores, avg' : undefined,
            delta: delta(totals?.cpuAvg, totals?.previousCpuAvg),
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
            min: 0,
            traffic: false,
        },
        {
            key: 'memory',
            label: 'Memory',
            value: totals?.memAvg != null ? formatMemory(totals.memAvg) : null,
            unit: totals?.memAvg != null ? 'avg' : undefined,
            delta: delta(totals?.memAvg, totals?.previousMemAvg),
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
            min: 0,
            traffic: false,
        },
    ];

    const chart = (): {
        series: TimeSeries[];
        format: (v: number) => string;
        yMax?: number;
    } => {
        switch (selected) {
            case 'requests':
                return {
                    series: [
                        {
                            key: 'rps',
                            label: 'Requests/s',
                            points: points(
                                traffic?.requestsPerSecond ?? [],
                                times,
                            ),
                        },
                    ],
                    format: formatRate,
                };
            case 'errors':
                return {
                    series: [
                        {
                            key: 'errors',
                            label: 'Server errors',
                            points: points(traffic?.errorRatePct ?? [], times),
                        },
                    ],
                    format: formatErrorRate,
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
            case 'cpu':
                return {
                    series: [
                        {
                            key: 'cpu',
                            label: 'CPU',
                            points: points(totals?.cpuCores ?? [], times),
                        },
                    ],
                    format: formatCores,
                };
            case 'memory':
                return {
                    series: [
                        {
                            key: 'mem',
                            label: 'Memory',
                            points: points(totals?.memMb ?? [], times),
                        },
                    ],
                    format: formatMemory,
                };
        }
    };

    const active = cards.find((card) => card.key === selected)!;
    const big = chart();
    const trafficCards = cards.filter((card) => card.traffic);
    const usageCards = cards.filter((card) => !card.traffic);

    return (
        <>
            <PageHeader
                title="Metrics"
                description={`${services.length} ${services.length === 1 ? 'service' : 'services'} in ${environmentName}, ${RANGE_WORDS[range]}`}
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
                    Metrics could not be loaded. The page tries again every
                    minute.
                </EmptyState>
            ) : !data ? (
                <div
                    aria-hidden="true"
                    className="grid animate-skeleton-in grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3"
                >
                    {cards.map((card) => (
                        <div
                            key={card.key}
                            className="h-[188px] rounded-[14px] border bg-card"
                        />
                    ))}
                </div>
            ) : (
                <>
                    <div className="flex flex-col gap-4">
                        {traffic ? (
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                {trafficCards.map((card) => (
                                    <MetricCard
                                        key={card.key}
                                        label={card.label}
                                        value={card.value}
                                        unit={card.unit}
                                        delta={card.delta}
                                        rows={card.rows}
                                        min={card.min}
                                        selected={selected === card.key}
                                        onSelect={() => setPicked(card.key)}
                                    />
                                ))}
                            </div>
                        ) : (
                            <EmptyState icon={Globe} variant="framed">
                                Public traffic appears here a minute after the
                                first request reaches one of your domains.
                            </EmptyState>
                        )}
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            {usageCards.map((card) => (
                                <MetricCard
                                    key={card.key}
                                    label={card.label}
                                    value={card.value}
                                    unit={card.unit}
                                    delta={card.delta}
                                    rows={card.rows}
                                    min={card.min}
                                    hint="No usage recorded in this range."
                                    selected={selected === card.key}
                                    onSelect={() => setPicked(card.key)}
                                />
                            ))}
                        </div>
                    </div>

                    <Section
                        title={active.label}
                        description={`All services, ${RANGE_WORDS[range]}`}
                    >
                        <div className="rounded-[14px] border bg-card p-5 shadow-[0_1px_2px_rgb(0_0_0/0.04)] dark:shadow-none">
                            <TimeSeriesChart
                                title={`${active.label}, ${RANGE_WORDS[range]}`}
                                series={big.series}
                                formatValue={big.format}
                                yMax={big.yMax}
                                markers={markers}
                                height={240}
                                empty={
                                    <p className="py-16 text-center type-body text-muted-foreground">
                                        Nothing recorded in this range yet.
                                    </p>
                                }
                            />
                        </div>
                    </Section>

                    <ServiceRows
                        project={project}
                        environment={environment}
                        services={services}
                        usage={usage}
                    />
                </>
            )}
        </>
    );
}

function ServiceRows({
    project,
    environment,
    services,
    usage,
}: {
    project: string;
    environment: string;
    services: Service[];
    usage: Usage[];
}) {
    const byId = new Map(usage.map((u) => [u.serviceId, u]));

    if (services.length === 0) {
        return null;
    }

    return (
        <Section title="Services">
            <ul className="divide-y rounded-[14px] border bg-card shadow-[0_1px_2px_rgb(0_0_0/0.04)] dark:shadow-none">
                {services.map((service) => {
                    const u = byId.get(service.id);
                    const traffic = u?.requests ?? null;
                    const cpu = u ? average(u.cpuCores) : null;
                    const mem = u ? average(u.memMb) : null;
                    const facts = traffic
                        ? [
                              `${formatRate(traffic.totals.requestsPerSecond)} req/s`,
                              traffic.totals.p95Ms != null
                                  ? `p95 ${formatMs(traffic.totals.p95Ms)}`
                                  : null,
                              traffic.totals.status5xx > 0 &&
                              traffic.totals.errorRatePct != null
                                  ? `${formatErrorRate(traffic.totals.errorRatePct)} errors`
                                  : null,
                          ]
                        : [
                              cpu !== null ? `CPU ${formatCores(cpu)}` : null,
                              mem !== null ? formatMemory(mem) : null,
                          ];
                    const hasFacts = facts.some(Boolean);
                    const trend = traffic
                        ? traffic.requestsPerSecond
                        : (u?.cpuCores ?? []);

                    return (
                        <li key={service.id}>
                            <Link
                                href={
                                    servicePanel([
                                        project,
                                        environment,
                                        service.id,
                                        'metrics',
                                    ]).url
                                }
                                className="grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-4 px-5 py-3.5 hover:bg-(--hover) focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-(--signal) sm:grid-cols-[auto_minmax(0,1fr)_160px_auto]"
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
                                            'No metrics in this range'
                                        )}
                                    </span>
                                </span>
                                <span className="hidden sm:block">
                                    <Sparkline
                                        values={trend}
                                        height={24}
                                        min={0}
                                        label={`${service.name} ${traffic ? 'requests' : 'CPU'} trend`}
                                    />
                                </span>
                                <ChevronRight
                                    aria-hidden="true"
                                    className="size-4 text-muted-foreground"
                                />
                            </Link>
                        </li>
                    );
                })}
            </ul>
        </Section>
    );
}
