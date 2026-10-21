import { ChartLine } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { formatCompact } from '@/components/charts/format';
import { StatTile } from '@/components/charts/stat-tile';
import { TimeRangeSelect } from '@/components/charts/time-range-select';
import type { TimeRangeOption } from '@/components/charts/time-range-select';
import { TimeSeriesChart } from '@/components/charts/time-series-chart';
import type { TimeSeries } from '@/components/charts/time-series-chart';
import { EmptyState } from '@/components/patterns/empty-state';
import { ChannelEvents } from '@/features/deployments/realtime';
import {
    delta,
    deployMarkers,
    formatCores,
    formatErrorRate,
    formatMemory,
    formatMs,
    formatRate,
    points,
} from '@/features/metrics/format';
import { requestJson } from '@/features/projects/http';
import { metrics as metricsRoute } from '@/routes/projects';

type Metrics = App.Data.Metrics.ProjectMetricsData;
type Range = '1h' | '24h' | '7d';

const RANGES: TimeRangeOption<Range>[] = [
    { value: '1h', label: 'Hour' },
    { value: '24h', label: 'Day' },
    { value: '7d', label: 'Week' },
];

const RANGE_WORDS: Record<Range, string> = {
    '1h': 'the last hour',
    '24h': 'the last day',
    '7d': 'the last week',
};

/**
 * Service panel → Metrics tab (docs/15 §2.2). Public traffic first when the
 * service has any (requests, latency and errors through the edges, as range
 * values with a delta against the period before), then usage (CPU and memory
 * now, one chart each, one line per replica when there are several). Every
 * chart carries this service's deploy markers. Workers and databases simply
 * have no traffic section. Without samples it says when metrics arrive.
 */
export function ServiceMetrics({
    project,
    environment,
    service,
    teamId,
}: {
    project: string;
    environment: string;
    service: string;
    /** Team public id: refetch on `server.metrics` nudges (optional). */
    teamId?: string;
}) {
    const [range, setRange] = useState<Range>('1h');
    const [data, setData] = useState<Metrics | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [tick, setTick] = useState(0);
    const url = metricsRoute(project, {
        query: { environment, service, range },
    }).url;

    useEffect(() => {
        const controller = new AbortController();

        requestJson<Metrics>('get', url, undefined, controller.signal)
            .then((result) => {
                setData(result);
                setError(null);
            })
            .catch((reason: unknown) => {
                if (!controller.signal.aborted) {
                    setError(
                        reason instanceof Error
                            ? reason.message
                            : 'Metrics could not be loaded.',
                    );
                }
            });

        return () => controller.abort();
    }, [url, tick]);

    const usage = data?.services[0] ?? null;
    const traffic = usage?.requests ?? null;
    const times = useMemo(
        () => (data?.timestamps ?? []).map((t) => Date.parse(t)),
        [data],
    );
    const markers = useMemo(
        () => deployMarkers(data?.deploys, service),
        [data, service],
    );

    const usageSeries = (pick: 'cpuCores' | 'memMb'): TimeSeries[] => {
        if (!usage) {
            return [];
        }

        const replicas = usage.replicas.length > 1 ? usage.replicas : [];

        if (replicas.length === 0) {
            return [
                {
                    key: pick,
                    label: pick === 'cpuCores' ? 'CPU' : 'Memory',
                    points: points(usage[pick], times),
                },
            ];
        }

        return replicas.map((replica, index) => ({
            key: `r${replica.replica}`,
            label: `Replica ${replica.replica + 1}`,
            slot: index + 1,
            points: points(replica[pick], times),
        }));
    };

    const hasUsage = Boolean(
        usage &&
        (usage.memMb.some((v) => v !== null) ||
            usage.cpuCores.some((v) => v !== null)),
    );
    const hasErrors = Boolean(traffic && traffic.totals.status5xx > 0);

    return (
        <div className="flex flex-col gap-6">
            {teamId && (
                <ChannelEvents
                    channel={`servers.${teamId}`}
                    event=".server.metrics"
                    pollMs={60_000}
                    onEvent={() => setTick((n) => n + 1)}
                />
            )}
            <div className="flex items-center justify-between gap-3">
                <span className="type-small text-muted-foreground">
                    {usage && usage.replicas.length > 1
                        ? `${usage.replicas.length} replicas · ${RANGE_WORDS[range]}`
                        : `All replicas · ${RANGE_WORDS[range]}`}
                </span>
                <TimeRangeSelect<Range>
                    value={range}
                    onChange={setRange}
                    options={RANGES}
                />
            </div>

            {error ? (
                <EmptyState icon={ChartLine} variant="plain">
                    {error}
                </EmptyState>
            ) : data && !hasUsage && !traffic ? (
                <EmptyState icon={ChartLine} variant="plain">
                    No usage recorded in this range yet. Metrics arrive about a
                    minute after the service starts.
                </EmptyState>
            ) : (
                <>
                    {traffic && (
                        <section
                            aria-label="Traffic"
                            className="flex flex-col gap-4"
                        >
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                <StatTile
                                    label="Requests"
                                    value={formatRate(
                                        traffic.totals.requestsPerSecond,
                                    )}
                                    unit="/s"
                                    delta={delta(
                                        traffic.totals.requests,
                                        traffic.previous?.requests,
                                    )}
                                    hint={`${formatCompact(traffic.totals.requests)} requests`}
                                />
                                <StatTile
                                    label="Errors"
                                    value={
                                        traffic.totals.errorRatePct != null
                                            ? formatErrorRate(
                                                  traffic.totals.errorRatePct,
                                              )
                                            : null
                                    }
                                    delta={delta(
                                        traffic.totals.errorRatePct,
                                        traffic.previous?.errorRatePct,
                                        'lowerIsBetter',
                                        0.1,
                                    )}
                                    hint={
                                        traffic.totals.status5xx > 0
                                            ? `${formatCompact(traffic.totals.status5xx)} server errors`
                                            : 'No server errors'
                                    }
                                />
                                <StatTile
                                    label="Latency p95"
                                    value={
                                        traffic.totals.p95Ms != null
                                            ? formatMs(traffic.totals.p95Ms)
                                            : null
                                    }
                                    delta={delta(
                                        traffic.totals.p95Ms,
                                        traffic.previous?.p95Ms,
                                        'lowerIsBetter',
                                        5,
                                    )}
                                    hint={
                                        traffic.totals.p50Ms != null
                                            ? `p50 ${formatMs(traffic.totals.p50Ms)}`
                                            : undefined
                                    }
                                />
                            </div>
                            <TimeSeriesChart
                                title="Requests per second"
                                series={[
                                    {
                                        key: 'rps',
                                        label: 'Requests/s',
                                        points: points(
                                            traffic.requestsPerSecond,
                                            times,
                                        ),
                                    },
                                ]}
                                formatValue={formatRate}
                                markers={markers}
                                height={160}
                            />
                            <TimeSeriesChart
                                title="Latency"
                                series={[
                                    {
                                        key: 'p50',
                                        label: 'p50',
                                        slot: 1,
                                        points: points(traffic.p50Ms, times),
                                    },
                                    {
                                        key: 'p95',
                                        label: 'p95',
                                        slot: 2,
                                        points: points(traffic.p95Ms, times),
                                    },
                                ]}
                                formatValue={formatMs}
                                markers={markers}
                                height={160}
                            />
                            {hasErrors && (
                                <TimeSeriesChart
                                    title="Server errors"
                                    series={[
                                        {
                                            key: 'errors',
                                            label: 'Server errors',
                                            points: points(
                                                traffic.errorRatePct,
                                                times,
                                            ),
                                        },
                                    ]}
                                    formatValue={formatErrorRate}
                                    markers={markers}
                                    height={120}
                                />
                            )}
                        </section>
                    )}

                    {hasUsage && (
                        <section
                            aria-label="Usage"
                            className="flex flex-col gap-4"
                        >
                            {traffic && <h3 className="type-heading">Usage</h3>}
                            <div className="grid grid-cols-2 gap-3">
                                <StatTile
                                    label="CPU"
                                    value={
                                        usage?.currentCpuCores != null
                                            ? formatCores(usage.currentCpuCores)
                                            : null
                                    }
                                    hint="cores in use now"
                                />
                                <StatTile
                                    label="Memory"
                                    value={
                                        usage?.currentMemMb != null
                                            ? formatMemory(usage.currentMemMb)
                                            : null
                                    }
                                    hint="in use now"
                                />
                            </div>
                            {data && (
                                <>
                                    <TimeSeriesChart
                                        title="CPU"
                                        series={usageSeries('cpuCores')}
                                        formatValue={formatCores}
                                        markers={markers}
                                        height={160}
                                    />
                                    <TimeSeriesChart
                                        title="Memory"
                                        series={usageSeries('memMb')}
                                        formatValue={formatMemory}
                                        markers={markers}
                                        height={160}
                                    />
                                </>
                            )}
                        </section>
                    )}
                </>
            )}
        </div>
    );
}
