import { useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { Histogram } from '@/components/charts/histogram';
import { StatTile } from '@/components/charts/stat-tile';
import { RangeSelect } from '@/components/charts/time-range-select';
import { TimeSeriesChart } from '@/components/charts/time-series-chart';
import { MetaLine } from '@/components/patterns/meta-line';
import { bucketEntries } from '@/features/log-explorer/facets';
import { LogTable } from '@/features/log-explorer/log-table';
import type { LogEntry } from '@/features/log-explorer/types';
import { histogramZoom, linesExtent } from '@/features/log-explorer/zoom';
import { NetworkIssues } from '@/features/servers/network-issues';
import { formatWindow, rangeWords } from '@/lib/observe-scope';
import type { RangeOf } from '@/lib/observe-scope';

/*
 * The observe pages' shared pieces (D109) with fixed sample data: one range
 * control, offline gaps in server charts, the log histogram's zoom, the log
 * table in a wide search and the merged network issues.
 */

const NOW = Date.UTC(2026, 10, 19, 12, 0, 0);
const MINUTE = 60_000;
const HOUR = 60 * MINUTE;
const DAY = 24 * HOUR;

function Panel({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex min-w-0 flex-col gap-3 rounded-[14px] border bg-card p-5 shadow-[0_1px_2px_rgb(0_0_0/0.04)] dark:shadow-none">
            <p className="type-label text-muted-foreground">{label}</p>
            {children}
        </div>
    );
}

/** A day of CPU in 12-minute buckets with no reports from 18:24 to 09:00. */
function dayWithGap(): { times: number[]; values: (number | null)[] } {
    const times = Array.from(
        { length: 120 },
        (_, i) => NOW - DAY + (i + 1) * 12 * MINUTE,
    );
    const values = times.map((t, i) => {
        const hour = new Date(t).getUTCHours();
        const offline = hour >= 18 || hour < 9;

        return offline && !(hour === 18 && new Date(t).getUTCMinutes() < 24)
            ? null
            : Math.round((38 + 14 * Math.sin(i / 6) + (i % 7)) * 10) / 10;
    });

    return { times, values };
}

/** 180 lines over the last two hours of a 30-day search. */
function recentLines(): LogEntry[] {
    return Array.from({ length: 180 }, (_, i): LogEntry => {
        const t = NOW - 2 * HOUR + Math.floor((i * 2 * HOUR) / 180);
        const service = ['web', 'api', 'worker'][i % 3];

        return {
            id: `z${i}`,
            time: new Date(t).toISOString(),
            level: i % 23 === 0 ? 'error' : 'info',
            service,
            environment: i % 2 === 0 ? 'production' : 'staging',
            replica: null,
            server: 'fra-1',
            deployment: '3b7d5e1',
            stream: 'stdout',
            message: `10.0.${i % 9}.${(i * 7) % 250} - - "GET /health HTTP/1.1" 200 2 "-" "launchroom-health/1"`,
        };
    });
}

export function ObserveStatesGallery() {
    const [logsRange, setLogsRange] = useState<RangeOf<'logs'>>('30d');
    const [range, setRange] = useState<RangeOf<'analytics'>>('24h');
    const [whole, setWhole] = useState(false);
    const day = useMemo(dayWithGap, []);
    const lines = useMemo(recentLines, []);
    const month = { from: NOW - 30 * DAY, to: NOW };
    const zoom = whole
        ? null
        : histogramZoom(
              linesExtent(lines.map((line) => Date.parse(line.time))),
              month,
          );
    const buckets = zoom
        ? bucketEntries(lines, zoom.from, zoom.to, zoom.count)
        : bucketEntries(lines, month.from, month.to, 60);

    return (
        <div className="flex flex-col gap-4">
            <Panel label="RangeSelect: the shared four, Logs adds 15 min">
                <div className="flex flex-wrap items-center gap-3">
                    <RangeSelect
                        page="logs"
                        value={logsRange}
                        onChange={setLogsRange}
                    />
                    <RangeSelect
                        page="analytics"
                        value={range}
                        onChange={setRange}
                    />
                </div>
            </Panel>

            <div className="grid gap-4 lg:grid-cols-[1fr_2fr]">
                <StatTile
                    label="CPU"
                    value="41"
                    unit="%"
                    action={
                        <span className="type-small text-muted-foreground">
                            now
                        </span>
                    }
                    hint="4.9 of 12 cores"
                    series={{
                        values: day.values,
                        times: day.times,
                        min: 0,
                        max: 100,
                        format: (v) => `${Math.round(v)}%`,
                        markGaps: true,
                    }}
                />
                <Panel label="Server chart with an offline stretch (hover the band)">
                    <TimeSeriesChart
                        title="CPU in use, last 24 hours"
                        markGaps
                        yMax={100}
                        height={170}
                        formatValue={(v) => `${Math.round(v)}%`}
                        series={[
                            {
                                key: 'cpu',
                                label: 'CPU',
                                points: day.values.map((v, i) => ({
                                    t: day.times[i],
                                    v,
                                })),
                            },
                        ]}
                    />
                </Panel>
            </div>

            <Panel label="Log histogram: lines only in the last two hours of 30 days">
                <Histogram buckets={buckets} label="Log lines" height={64} />
                <p className="type-small text-muted-foreground">
                    <MetaLine>
                        180 matching lines
                        {zoom && (
                            <span>
                                chart zoomed to {formatWindow(zoom)}, where the
                                lines are{' '}
                                <button
                                    type="button"
                                    onClick={() => setWhole(true)}
                                    className="rounded-sm text-foreground underline decoration-border-strong underline-offset-4 hover:decoration-foreground"
                                >
                                    Show the {rangeWords('30d')}
                                </button>
                            </span>
                        )}
                        {whole && (
                            <button
                                type="button"
                                onClick={() => setWhole(false)}
                                className="rounded-sm text-foreground underline decoration-border-strong underline-offset-4"
                            >
                                Zoom to the lines again
                            </button>
                        )}
                    </MetaLine>
                </p>
            </Panel>

            <Panel label="Log table in a wide search: the message takes the room, the source names the service">
                <div className="flex h-64 max-w-[640px] overflow-hidden rounded-lg border">
                    <LogTable
                        entries={lines.slice(0, 40)}
                        context="environment"
                        className="min-h-0 flex-1"
                    />
                </div>
            </Panel>

            <Panel label="Network issues: one, then several merged with a count">
                <NetworkIssues
                    canManageIngress
                    mesh={{
                        enabled: true,
                        mode: null,
                        builtInAvailable: true,
                        canManage: true,
                        loginServer: null,
                        error: null,
                        errorAt: null,
                        enabledAt: null,
                    }}
                    edgeOverview={{
                        edges: 2,
                        singlePointOfFailure: false,
                        servers: 3,
                        singleServer: false,
                        degradedStorage: ['control plane'],
                        guideUrl: '#',
                    }}
                />
                <NetworkIssues
                    canManageIngress
                    mesh={{
                        enabled: true,
                        mode: null,
                        builtInAvailable: true,
                        canManage: true,
                        loginServer: null,
                        error: 'headscale: connection refused (127.0.0.1:50443)',
                        errorAt: null,
                        enabledAt: null,
                    }}
                    edgeOverview={{
                        edges: 2,
                        singlePointOfFailure: false,
                        servers: 3,
                        singleServer: false,
                        degradedStorage: ['control plane'],
                        guideUrl: '#',
                    }}
                />
            </Panel>
        </div>
    );
}
