import { useState } from 'react';
import { TimeRangeSelect } from '@/components/charts/time-range-select';
import type { TimeRange } from '@/components/charts/time-range-select';
import { TimeSeriesChart } from '@/components/charts/time-series-chart';
import { formatMb } from '@/features/servers/format';
import { useFleetMetrics } from '@/features/servers/use-server-metrics';
import { cn } from '@/lib/utils';

type Metric = 'cpu' | 'memory' | 'disk';

type Capacity = {
    cpus: number;
    memoryMb: number;
    diskMb: number;
};

const RANGE_WORDS: Record<TimeRange, string> = {
    '1h': 'last hour',
    '24h': 'last day',
    '7d': 'last week',
    '30d': 'last 30 days',
};

function cores(value: number): string {
    return value < 10 ? value.toFixed(1) : String(Math.round(value));
}

/**
 * The whole system as one machine (Servers page overview): CPU, memory
 * and disk in use as a share of what every server together offers, over
 * time. One metric at a time in the signal colour (design-system §3.6);
 * the three tabs carry the current value and capacity. Without samples it
 * still shows the capacity, never an empty chart.
 */
export function FleetLoad({
    capacity,
    serverCount,
}: {
    /** Specs summed over active servers: the fallback before metrics arrive. */
    capacity: Capacity;
    serverCount: number;
}) {
    const [range, setRange] = useState<TimeRange>('1h');
    const [metric, setMetric] = useState<Metric>('cpu');
    const { data } = useFleetMetrics(range);
    const totals = data?.totals;
    const now = totals?.current ?? null;
    const times = (data?.timestamps ?? []).map((t) => new Date(t).getTime());

    const tabs: {
        key: Metric;
        label: string;
        value: string;
        detail: string;
        series: (number | null)[];
    }[] = [
        {
            key: 'cpu',
            label: 'CPU',
            value: now?.cpuPct != null ? `${Math.round(now.cpuPct)}%` : '–',
            detail: now
                ? `${cores(now.cpuUsedCores)} of ${now.cpuCores} cores`
                : `${capacity.cpus} cores`,
            series: totals?.cpuPct ?? [],
        },
        {
            key: 'memory',
            label: 'Memory',
            value: now?.memPct != null ? `${Math.round(now.memPct)}%` : '–',
            detail:
                now && now.memTotalMb > 0
                    ? `${formatMb(now.memUsedMb)} of ${formatMb(now.memTotalMb)}`
                    : (formatMb(capacity.memoryMb) ?? ''),
            series: totals?.memPct ?? [],
        },
        {
            key: 'disk',
            label: 'Disk',
            value: now?.diskPct != null ? `${Math.round(now.diskPct)}%` : '–',
            detail:
                now && now.diskTotalMb > 0
                    ? `${formatMb(now.diskUsedMb)} of ${formatMb(now.diskTotalMb)}`
                    : (formatMb(capacity.diskMb) ?? ''),
            series: totals?.diskPct ?? [],
        },
    ];
    const active = tabs.find((tab) => tab.key === metric) ?? tabs[0];
    const servers =
        serverCount === 1 ? 'your server' : `all ${serverCount} servers`;

    return (
        <section
            aria-labelledby="fleet-load-title"
            className="flex h-full min-w-0 flex-col gap-6 rounded-xl border bg-card p-6 shadow-card"
        >
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex min-w-0 flex-col gap-0.5">
                    <h2 id="fleet-load-title" className="type-heading">
                        System load
                    </h2>
                    <p className="type-small text-muted-foreground">
                        How much of {servers} is in use.
                    </p>
                </div>
                <TimeRangeSelect value={range} onChange={setRange} />
            </div>

            <div
                role="tablist"
                aria-label="Resource"
                className="grid grid-cols-3 gap-2"
            >
                {tabs.map((tab) => {
                    const selected = tab.key === metric;

                    return (
                        <button
                            key={tab.key}
                            type="button"
                            role="tab"
                            aria-selected={selected}
                            onClick={() => setMetric(tab.key)}
                            className={cn(
                                'flex min-w-0 flex-col gap-0.5 rounded-lg px-2.5 py-2.5 text-left transition-colors duration-fast focus-visible:ring-2 focus-visible:ring-signal focus-visible:outline-none sm:px-3',
                                selected
                                    ? 'bg-hover'
                                    : 'text-muted-foreground hover:bg-hover/60',
                            )}
                        >
                            <span className="type-small text-muted-foreground">
                                {tab.label}
                            </span>
                            <span
                                className={cn(
                                    'type-stat tabular-nums',
                                    !selected && 'text-foreground/80',
                                )}
                            >
                                {tab.value}
                            </span>
                            <span className="type-small text-pretty text-muted-foreground tabular-nums">
                                {tab.detail}
                            </span>
                        </button>
                    );
                })}
            </div>

            <div role="tabpanel" aria-label={active.label} className="mt-auto">
                <TimeSeriesChart
                    key={`${metric}-${range}`}
                    title={`${active.label} in use, ${RANGE_WORDS[range]}`}
                    height={170}
                    yMax={100}
                    formatValue={(v) => `${Math.round(v)}%`}
                    series={[
                        {
                            key: metric,
                            label: active.label,
                            points: active.series.map((v, i) => ({
                                t: times[i] ?? 0,
                                v,
                            })),
                        },
                    ]}
                    empty={
                        <p className="rounded-lg bg-recessed px-4 py-8 text-center type-small text-muted-foreground">
                            Usage shows up here a minute after your servers
                            report.
                        </p>
                    }
                />
            </div>
        </section>
    );
}
