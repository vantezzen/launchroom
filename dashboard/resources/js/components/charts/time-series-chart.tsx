import { Table2 } from 'lucide-react';
import { useId, useMemo, useState } from 'react';
import type { KeyboardEvent, PointerEvent, ReactNode } from 'react';
import { cn } from '@/lib/utils';
import { formatTick, formatTooltipTime, niceMax } from './format';
import { colorForSlot, MAX_SERIES, OTHER, SIGNAL } from './palette';
import { useMeasuredWidth } from './use-measure';

export type TimePoint = { t: number; v: number | null };

export type TimeSeries = {
    key: string;
    label: string;
    points: TimePoint[];
    /** Entity palette slot; ignored for a single series (signal colour). */
    slot?: number | null;
};

export type ChartMarker = {
    t: number;
    /** Short label at the top of the marker, e.g. the short SHA. */
    label: string;
    tone?: 'signal' | 'error';
    /** Hover text, e.g. "web · 3b7d5e1 · failed". */
    detail?: string;
};

export type TimeSeriesChartProps = {
    series: TimeSeries[];
    /** Accessible title, also the table caption. */
    title: string;
    height?: number;
    /** Fixed y maximum (100 for percentages); defaults to a nice max of the data. */
    yMax?: number;
    formatValue?: (value: number) => string;
    /** Deploy markers (design-system §9): signal ticks with the short SHA. */
    markers?: ChartMarker[];
    /** Show the "Table" toggle (full-size charts must have one). */
    table?: boolean;
    /** Shown instead of the chart when there's nothing to plot. */
    empty?: ReactNode;
    className?: string;
};

const PAD = { top: 18, right: 8, bottom: 22, left: 44 };

/**
 * Full-size time chart (design-system §9): one y-axis with recessive labels,
 * hairline gridlines at 0, half and max, signal area for one series, palette lines with a
 * legend (values at the crosshair) for 2–4 series (more fold into "other").
 * Crosshair tooltip by pointer and keyboard (focus, ←/→, Home/End), deploy
 * markers, and a table view toggle as the text alternative.
 *
 * Hand-written SVG rather than Recharts: same look as the sparklines, no
 * extra dependency, and full control of focus/keyboard behaviour.
 */
export function TimeSeriesChart({
    series: input,
    title,
    height = 200,
    yMax,
    formatValue = (value) => value.toFixed(1),
    markers = [],
    table = true,
    empty = null,
    className,
}: TimeSeriesChartProps) {
    const id = useId();
    const [ref, width] = useMeasuredWidth<HTMLDivElement>();
    const [active, setActive] = useState<number | null>(null);
    const [showTable, setShowTable] = useState(false);

    const series = useMemo(() => foldSeries(input), [input]);
    const times = series[0]?.points.map((point) => point.t) ?? [];
    const hasData = series.some(
        (s) => s.points.filter((point) => point.v !== null).length > 1,
    );

    const values = series.flatMap((s) =>
        s.points.flatMap((point) => (point.v === null ? [] : [point.v])),
    );
    const top = yMax ?? niceMax(Math.max(0, ...values) * 1.1);
    const t0 = times[0] ?? 0;
    const t1 = times[times.length - 1] ?? 1;
    const span = Math.max(1, t1 - t0);
    const plotW = Math.max(0, width - PAD.left - PAD.right);
    const plotH = height - PAD.top - PAD.bottom;
    const x = (t: number) => PAD.left + ((t - t0) / span) * plotW;
    const y = (v: number) => PAD.top + (1 - Math.min(v, top) / top) * plotH;
    const single = series.length === 1;

    if (!hasData) {
        return <>{empty}</>;
    }

    const move = (index: number | null) => setActive(index);

    const onPointerMove = (event: PointerEvent<SVGSVGElement>) => {
        const rect = event.currentTarget.getBoundingClientRect();
        const t = t0 + ((event.clientX - rect.left - PAD.left) / plotW) * span;
        move(nearestIndex(times, t));
    };

    const onKeyDown = (event: KeyboardEvent<SVGSVGElement>) => {
        const last = times.length - 1;
        const current = active ?? last;
        const next: Record<string, number> = {
            ArrowLeft: Math.max(0, current - 1),
            ArrowRight: Math.min(last, current + 1),
            Home: 0,
            End: last,
        };

        if (event.key in next) {
            event.preventDefault();
            move(next[event.key]);
        } else if (event.key === 'Escape') {
            move(null);
        }
    };

    const ticks = [0, 0.5, 1].map((f) => top * f);
    const xTicks = tickTimes(t0, t1, Math.max(2, Math.floor(plotW / 110)));
    const activeT = active !== null ? times[active] : null;
    const tooltipLeft =
        activeT !== null
            ? Math.min(Math.max(x(activeT) + 12, 0), width - 168)
            : 0;
    const flip = activeT !== null && x(activeT) + 180 > width;

    return (
        <figure className={cn('flex flex-col gap-3', className)}>
            <div className="flex items-center gap-4">
                {!single && (
                    <ul className="flex flex-wrap gap-x-4 gap-y-1 type-small text-muted-foreground">
                        {series.map((s) => {
                            const point =
                                active !== null
                                    ? s.points[active]
                                    : lastValue(s.points);

                            return (
                                <li
                                    key={s.key}
                                    className="inline-flex items-center gap-1.5"
                                >
                                    <span
                                        aria-hidden="true"
                                        className="h-0.5 w-3 rounded-full"
                                        style={{ backgroundColor: s.color }}
                                    />
                                    <span className="text-foreground">
                                        {s.label}
                                    </span>
                                    <span className="tabular-nums">
                                        {point?.v != null
                                            ? formatValue(point.v)
                                            : '–'}
                                    </span>
                                </li>
                            );
                        })}
                    </ul>
                )}
                <figcaption className="sr-only">{title}</figcaption>
                {table && (
                    <button
                        type="button"
                        onClick={() => setShowTable((value) => !value)}
                        aria-pressed={showTable}
                        className="ml-auto inline-flex h-7 items-center gap-1.5 rounded-md px-2 type-small text-muted-foreground hover:bg-(--hover) hover:text-foreground focus-visible:outline-2 focus-visible:outline-(--signal)"
                    >
                        <Table2 aria-hidden="true" className="size-3.5" />
                        {showTable ? 'Chart' : 'Table'}
                    </button>
                )}
            </div>

            {showTable ? (
                <DataTable
                    series={series}
                    times={times}
                    span={span}
                    title={title}
                    formatValue={formatValue}
                    height={height}
                />
            ) : (
                <div ref={ref} className="relative w-full" style={{ height }}>
                    {width > 0 && (
                        <svg
                            width={width}
                            height={height}
                            className="block overflow-visible outline-none focus-visible:rounded-md focus-visible:ring-2 focus-visible:ring-(--signal)"
                            role="img"
                            aria-label={`${title}. Use the arrow keys to read values.`}
                            tabIndex={0}
                            onPointerMove={onPointerMove}
                            onPointerLeave={() => move(null)}
                            onBlur={() => move(null)}
                            onKeyDown={onKeyDown}
                        >
                            <defs>
                                <linearGradient
                                    id={`${cssId(id)}-fill`}
                                    x1="0"
                                    x2="0"
                                    y1="0"
                                    y2="1"
                                >
                                    <stop
                                        offset="0%"
                                        style={{
                                            stopColor: SIGNAL,
                                            stopOpacity: 0.22,
                                        }}
                                    />
                                    <stop
                                        offset="100%"
                                        style={{
                                            stopColor: SIGNAL,
                                            stopOpacity: 0,
                                        }}
                                    />
                                </linearGradient>
                            </defs>

                            {ticks.map((tick, index) => (
                                <g key={tick}>
                                    <line
                                        x1={PAD.left}
                                        x2={width - PAD.right}
                                        y1={y(tick)}
                                        y2={y(tick)}
                                        strokeWidth={1}
                                        style={{ stroke: 'var(--border)' }}
                                        strokeDasharray={
                                            index === 0 ? undefined : '2 3'
                                        }
                                    />
                                    <text
                                        x={PAD.left - 8}
                                        y={y(tick)}
                                        dy="0.32em"
                                        textAnchor="end"
                                        className="fill-muted-foreground text-[11px] tabular-nums"
                                    >
                                        {formatValue(tick)}
                                    </text>
                                </g>
                            ))}

                            {xTicks.map((tick) => (
                                <text
                                    key={tick}
                                    x={x(tick)}
                                    y={height - 4}
                                    textAnchor="middle"
                                    className="fill-muted-foreground text-[11px] tabular-nums"
                                >
                                    {formatTick(tick, span)}
                                </text>
                            ))}

                            {labelledMarkers(
                                markers.filter(
                                    (marker) =>
                                        marker.t >= t0 && marker.t <= t1,
                                ),
                                x,
                            ).map(({ marker, showLabel }) => (
                                <g key={`${marker.t}-${marker.label}`}>
                                    {marker.detail && (
                                        <title>{marker.detail}</title>
                                    )}
                                    <line
                                        x1={x(marker.t)}
                                        x2={x(marker.t)}
                                        y1={PAD.top - 4}
                                        y2={PAD.top + plotH}
                                        strokeWidth={1}
                                        strokeDasharray="3 3"
                                        style={{
                                            stroke:
                                                marker.tone === 'error'
                                                    ? 'var(--status-error)'
                                                    : SIGNAL,
                                        }}
                                    />
                                    {showLabel && (
                                        <text
                                            x={x(marker.t) + 4}
                                            y={PAD.top - 6}
                                            className="font-mono text-[10px]"
                                            style={{
                                                fill:
                                                    marker.tone === 'error'
                                                        ? 'var(--status-error)'
                                                        : SIGNAL,
                                            }}
                                        >
                                            {marker.label}
                                        </text>
                                    )}
                                </g>
                            ))}

                            {single && (
                                <path
                                    d={areaD(
                                        series[0].points,
                                        x,
                                        y,
                                        PAD.top + plotH,
                                    )}
                                    fill={`url(#${cssId(id)}-fill)`}
                                />
                            )}
                            {series.map((s) => (
                                <path
                                    key={s.key}
                                    d={lineD(s.points, x, y)}
                                    fill="none"
                                    strokeWidth={1.75}
                                    strokeLinejoin="round"
                                    strokeLinecap="round"
                                    style={{ stroke: s.color }}
                                />
                            ))}

                            {activeT !== null && (
                                <g>
                                    <line
                                        x1={x(activeT)}
                                        x2={x(activeT)}
                                        y1={PAD.top}
                                        y2={PAD.top + plotH}
                                        strokeWidth={1}
                                        style={{
                                            stroke: 'var(--border-strong)',
                                        }}
                                    />
                                    {series.map((s) => {
                                        const v = s.points[active!]?.v;

                                        return v == null ? null : (
                                            <circle
                                                key={s.key}
                                                cx={x(activeT)}
                                                cy={y(v)}
                                                r={3.5}
                                                strokeWidth={1.75}
                                                style={{
                                                    fill: 'var(--card)',
                                                    stroke: s.color,
                                                }}
                                            />
                                        );
                                    })}
                                </g>
                            )}
                        </svg>
                    )}

                    {activeT !== null && (
                        <div
                            aria-live="polite"
                            className="pointer-events-none absolute top-2 z-10 w-40 rounded-lg border bg-popover px-3 py-2 shadow-[0_8px_30px_rgb(0_0_0/0.10),0_1px_3px_rgb(0_0_0/0.06)] dark:border-(--border-strong) dark:shadow-[0_8px_30px_rgb(0_0_0/0.6)]"
                            style={{
                                left: flip
                                    ? Math.max(0, x(activeT) - 172)
                                    : tooltipLeft,
                            }}
                        >
                            <p className="type-small text-muted-foreground tabular-nums">
                                {formatTooltipTime(activeT, span)}
                            </p>
                            {series.map((s) => (
                                <p
                                    key={s.key}
                                    className="flex items-center gap-1.5 type-small"
                                >
                                    <span
                                        aria-hidden="true"
                                        className="size-2 rounded-[2px]"
                                        style={{ backgroundColor: s.color }}
                                    />
                                    <span className="truncate">{s.label}</span>
                                    <span className="ml-auto font-medium tabular-nums">
                                        {s.points[active!]?.v != null
                                            ? formatValue(s.points[active!].v!)
                                            : '–'}
                                    </span>
                                </p>
                            ))}
                        </div>
                    )}
                </div>
            )}
        </figure>
    );
}

type ColouredSeries = TimeSeries & { color: string };

/**
 * Deploys close together (one deploy of several services) would print their
 * SHAs on top of each other: only a marker at least 64 px right of the last
 * labelled one gets a label; the others keep their tick and hover title.
 */
function labelledMarkers(
    markers: ChartMarker[],
    x: (t: number) => number,
): { marker: ChartMarker; showLabel: boolean }[] {
    let last = -Infinity;

    return [...markers]
        .sort((a, b) => a.t - b.t)
        .map((marker) => {
            const showLabel = x(marker.t) - last >= 64;

            if (showLabel) {
                last = x(marker.t);
            }

            return { marker, showLabel };
        });
}

/** Single series → signal; up to 4 in palette colours; the rest summed into grey "other". */
function foldSeries(input: TimeSeries[]): ColouredSeries[] {
    const withData = input.filter((s) => s.points.length > 0);

    if (withData.length === 1) {
        return [{ ...withData[0], color: SIGNAL }];
    }

    const ranked = [...withData].sort(
        (a, b) => total(b.points) - total(a.points),
    );
    const kept = ranked.slice(0, MAX_SERIES);
    const rest = ranked.slice(MAX_SERIES);
    const out: ColouredSeries[] = kept.map((s, index) => ({
        ...s,
        color: colorForSlot(s.slot ?? index + 1),
    }));

    if (rest.length > 0) {
        out.push({
            key: '__other',
            label: `Other (${rest.length})`,
            color: OTHER,
            points: rest[0].points.map((point, index) => {
                const vs = rest.map((s) => s.points[index]?.v);

                return {
                    t: point.t,
                    v: vs.every((v) => v == null)
                        ? null
                        : vs.reduce<number>((sum, v) => sum + (v ?? 0), 0),
                };
            }),
        });
    }

    return out;
}

function total(points: TimePoint[]): number {
    return points.reduce((sum, point) => sum + (point.v ?? 0), 0);
}

function lastValue(points: TimePoint[]): TimePoint | undefined {
    for (let index = points.length - 1; index >= 0; index--) {
        if (points[index].v !== null) {
            return points[index];
        }
    }

    return undefined;
}

function nearestIndex(times: number[], t: number): number {
    let best = 0;

    for (let index = 1; index < times.length; index++) {
        if (Math.abs(times[index] - t) < Math.abs(times[best] - t)) {
            best = index;
        }
    }

    return best;
}

function tickTimes(t0: number, t1: number, count: number): number[] {
    const span = t1 - t0;
    const steps = [
        60_000, 300_000, 900_000, 1_800_000, 3_600_000, 7_200_000, 21_600_000,
        43_200_000, 86_400_000, 172_800_000, 604_800_000,
    ];
    const step =
        steps.find((s) => span / s <= count) ?? steps[steps.length - 1];
    const first = Math.ceil(t0 / step) * step;
    const out: number[] = [];

    for (let t = first; t <= t1; t += step) {
        out.push(t);
    }

    return out;
}

function lineD(
    points: TimePoint[],
    x: (t: number) => number,
    y: (v: number) => number,
): string {
    let d = '';
    let pen = false;

    for (const point of points) {
        if (point.v === null) {
            pen = false;
            continue;
        }

        d += `${pen ? 'L' : 'M'}${x(point.t).toFixed(1)} ${y(point.v).toFixed(1)}`;
        pen = true;
    }

    return d;
}

function areaD(
    points: TimePoint[],
    x: (t: number) => number,
    y: (v: number) => number,
    base: number,
): string {
    const runs: { t: number; v: number }[][] = [[]];

    for (const point of points) {
        if (point.v === null) {
            runs.push([]);
        } else {
            runs[runs.length - 1].push({ t: point.t, v: point.v });
        }
    }

    return runs
        .filter((run) => run.length > 1)
        .map(
            (run) =>
                `M${x(run[0].t).toFixed(1)} ${base}` +
                run
                    .map(
                        (point) =>
                            `L${x(point.t).toFixed(1)} ${y(point.v).toFixed(1)}`,
                    )
                    .join('') +
                `L${x(run[run.length - 1].t).toFixed(1)} ${base}Z`,
        )
        .join('');
}

function cssId(id: string): string {
    return `tsc-${id.replace(/[^a-zA-Z0-9_-]/g, '')}`;
}

function DataTable({
    series,
    times,
    span,
    title,
    formatValue,
    height,
}: {
    series: ColouredSeries[];
    times: number[];
    span: number;
    title: string;
    formatValue: (value: number) => string;
    height: number;
}) {
    return (
        <div
            className="overflow-auto rounded-lg border"
            style={{ maxHeight: height + 40 }}
        >
            <table className="w-full type-small tabular-nums">
                <caption className="sr-only">{title}</caption>
                <thead className="sticky top-0 bg-card text-left text-muted-foreground">
                    <tr className="border-b">
                        <th scope="col" className="px-3 py-2 font-medium">
                            Time
                        </th>
                        {series.map((s) => (
                            <th
                                key={s.key}
                                scope="col"
                                className="px-3 py-2 text-right font-medium"
                            >
                                {s.label}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {[...times].reverse().map((t, reversed) => {
                        const index = times.length - 1 - reversed;

                        return (
                            <tr key={t} className="border-b last:border-0">
                                <th
                                    scope="row"
                                    className="px-3 py-1.5 text-left font-normal text-muted-foreground"
                                >
                                    {formatTooltipTime(t, span)}
                                </th>
                                {series.map((s) => (
                                    <td
                                        key={s.key}
                                        className="px-3 py-1.5 text-right"
                                    >
                                        {s.points[index]?.v != null
                                            ? formatValue(s.points[index].v!)
                                            : '–'}
                                    </td>
                                ))}
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}
