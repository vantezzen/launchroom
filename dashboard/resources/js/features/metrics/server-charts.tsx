import { ChartLine } from 'lucide-react';
import { useMemo } from 'react';
import type { ReactNode } from 'react';
import { formatBytes } from '@/components/charts/format';
import { TimeSeriesChart } from '@/components/charts/time-series-chart';
import type { TimeSeries } from '@/components/charts/time-series-chart';
import { EmptyState } from '@/components/patterns/empty-state';
import { Section } from '@/components/patterns/section';
import { Skeleton } from '@/components/ui/skeleton';
import {
    deployMarkers,
    formatBandwidth,
    formatRate,
    points,
} from '@/features/metrics/format';

type Metrics = App.Data.Metrics.ServerMetricsData;

const RANGE_WORDS: Record<string, string> = {
    '1h': 'last hour',
    '24h': 'last 24 hours',
    '7d': 'last 7 days',
};

function last(values: (number | null)[]): number | null {
    for (let i = values.length - 1; i >= 0; i--) {
        if (values[i] !== null) {
            return values[i];
        }
    }

    return null;
}

function ChartCard({
    title,
    now,
    children,
}: {
    title: string;
    now: string | null;
    children: ReactNode;
}) {
    return (
        <div className="flex min-w-0 flex-col gap-3 rounded-[14px] border bg-card p-5 shadow-[0_1px_2px_rgb(0_0_0/0.04)] dark:shadow-none">
            <div className="flex items-baseline justify-between gap-3">
                <h3 className="type-heading">{title}</h3>
                {now && (
                    <span className="type-small text-muted-foreground tabular-nums">
                        {now}
                    </span>
                )}
            </div>
            {children}
        </div>
    );
}

/**
 * Server page → Metrics (docs/15 §2.4): full-size charts of CPU, memory,
 * disk and network over the page's range, and public traffic for edges,
 * each with the deploy markers of the services that ran here. Charts
 * without data are left out (design-system §9); without any, the section
 * doesn't render.
 */
export function ServerCharts({
    metrics,
    range,
    actions,
}: {
    metrics: Metrics | undefined;
    range: string;
    /** The range select (server page → Metrics tab). */
    actions?: ReactNode;
}) {
    const times = useMemo(
        () => (metrics?.timestamps ?? []).map((t) => Date.parse(t)),
        [metrics],
    );
    const markers = useMemo(() => deployMarkers(metrics?.deploys), [metrics]);

    const description = RANGE_WORDS[range] ?? undefined;

    if (!metrics) {
        return (
            <Section
                title="Metrics"
                description={description}
                actions={actions}
            >
                <div
                    aria-busy="true"
                    aria-label="Loading metrics"
                    className="grid grid-cols-1 gap-4 lg:grid-cols-2"
                >
                    {[0, 1, 2, 3].map((card) => (
                        <Skeleton
                            key={card}
                            className="h-[252px] rounded-[14px]"
                        />
                    ))}
                </div>
            </Section>
        );
    }

    const host = metrics.host;
    const percent = (v: number) => `${Math.round(v)}%`;
    const one = (
        key: string,
        label: string,
        values: (number | null)[],
    ): TimeSeries[] => [{ key, label, points: points(values, times) }];
    const charts: {
        key: string;
        title: string;
        now: string | null;
        node: ReactNode;
    }[] = [];

    if (host.cpuPct.length > 0) {
        const now = last(host.cpuPct);
        charts.push({
            key: 'cpu',
            title: 'CPU',
            now: now === null ? null : `${Math.round(now)}% now`,
            node: (
                <TimeSeriesChart
                    title="CPU in use"
                    series={one('cpu', 'CPU', host.cpuPct)}
                    yMax={100}
                    formatValue={percent}
                    markers={markers}
                    height={180}
                />
            ),
        });
    }

    if (host.memPct.length > 0) {
        const now = last(host.memUsedMb);
        charts.push({
            key: 'memory',
            title: 'Memory',
            now: now === null ? null : `${formatBytes(now * 1024 * 1024)} now`,
            node: (
                <TimeSeriesChart
                    title="Memory in use"
                    series={one('mem', 'Memory', host.memPct)}
                    yMax={100}
                    formatValue={percent}
                    markers={markers}
                    height={180}
                />
            ),
        });
    }

    if (host.diskPct.length > 0) {
        const now = last(host.diskPct);
        charts.push({
            key: 'disk',
            title: 'Disk',
            now: now === null ? null : `${Math.round(now)}% used`,
            node: (
                <TimeSeriesChart
                    title="Disk used"
                    series={one('disk', 'Disk', host.diskPct)}
                    yMax={100}
                    formatValue={percent}
                    markers={markers}
                    height={180}
                />
            ),
        });
    }

    if (host.netRxBps.length > 0 || host.netTxBps.length > 0) {
        const rx = last(host.netRxBps);
        const tx = last(host.netTxBps);
        charts.push({
            key: 'network',
            title: 'Network',
            now:
                rx === null && tx === null
                    ? null
                    : `↓ ${formatBandwidth(rx ?? 0)}  ↑ ${formatBandwidth(tx ?? 0)}`,
            node: (
                <TimeSeriesChart
                    title="Network traffic"
                    series={[
                        {
                            key: 'rx',
                            label: 'Received',
                            slot: 1,
                            points: points(host.netRxBps, times),
                        },
                        {
                            key: 'tx',
                            label: 'Sent',
                            slot: 2,
                            points: points(host.netTxBps, times),
                        },
                    ]}
                    formatValue={formatBandwidth}
                    markers={markers}
                    height={180}
                />
            ),
        });
    }

    if (metrics.traffic) {
        charts.push({
            key: 'traffic',
            title: 'Requests',
            now: `${formatRate(metrics.traffic.totals.requestsPerSecond)} req/s avg`,
            node: (
                <TimeSeriesChart
                    title="Requests per second through this server"
                    series={one(
                        'rps',
                        'Requests/s',
                        metrics.traffic.requestsPerSecond,
                    )}
                    formatValue={formatRate}
                    markers={markers}
                    height={180}
                />
            ),
        });
    }

    if (charts.length === 0) {
        return (
            <Section
                title="Metrics"
                description={description}
                actions={actions}
            >
                <EmptyState icon={ChartLine} title="No metrics yet">
                    Charts appear a few minutes after this server starts
                    reporting.
                </EmptyState>
            </Section>
        );
    }

    return (
        <Section title="Metrics" description={description} actions={actions}>
            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                {charts.map((chart) => (
                    <ChartCard
                        key={chart.key}
                        title={chart.title}
                        now={chart.now}
                    >
                        {chart.node}
                    </ChartCard>
                ))}
            </div>
        </Section>
    );
}
