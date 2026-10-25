import { Table2 } from 'lucide-react';
import { useState } from 'react';
import type { KeyboardEvent } from 'react';
import { cn } from '@/lib/utils';
import {
    formatCompact,
    formatTick,
    formatTooltipTime,
    niceMax,
} from './format';
import { useMeasuredWidth } from './use-measure';

export type StackedSeries = {
    key: string;
    label: string;
    /** CSS colour (a token: `var(--signal)`, `var(--status-error)` …). */
    color: string;
    /** Stripes on the segment: the non-colour cue for problem classes. */
    hatched?: boolean;
    /** One count per bucket, aligned with `times`. */
    values: number[];
};

export type StackedBarsProps = {
    /** Bucket starts (epoch ms), oldest first. */
    times: number[];
    /** Bucket width in ms (axis end and readout). */
    bucketMs: number;
    /** Stacked bottom to top in this order; series without counts are hidden. */
    series: StackedSeries[];
    /** Accessible title, also the table caption. */
    title: string;
    height?: number;
    formatValue?: (value: number) => string;
    /** Show the "Table" toggle (full-size charts must have one). */
    table?: boolean;
    className?: string;
};

const PAD = { top: 8, right: 4, bottom: 22, left: 40 };

/**
 * Counts per time bucket, stacked by category (design-system §13.8), e.g.
 * requests per hour by status class. The legend names every class with its
 * total, the readout lists each class of the hovered bucket, and problem
 * classes can be hatched, so colour is never the only cue. Hover or arrow
 * keys read a bucket; "Table" shows the numbers. Renders nothing when every
 * count is zero.
 */
export function StackedBars({
    times,
    bucketMs,
    series,
    title,
    height = 220,
    formatValue = formatCompact,
    table = true,
    className,
}: StackedBarsProps) {
    const [ref, width] = useMeasuredWidth<HTMLDivElement>();
    const [active, setActive] = useState<number | null>(null);
    const [showTable, setShowTable] = useState(false);

    const visible = series.filter((s) => s.values.some((v) => v > 0));
    const totals = times.map((_, i) =>
        visible.reduce((sum, s) => sum + (s.values[i] ?? 0), 0),
    );

    if (times.length === 0 || visible.length === 0) {
        return null;
    }

    const top = niceMax(Math.max(...totals));
    const plotW = Math.max(0, width - PAD.left - PAD.right);
    const plotH = height - PAD.top - PAD.bottom;
    const slot = plotW / times.length;
    const gap = slot > 8 ? 2 : slot > 3 ? 1 : 0;
    const barW = Math.max(1, slot - gap);
    const t0 = times[0];
    const span = times[times.length - 1] + bucketMs - t0;
    const y = (v: number) => PAD.top + plotH - (v / top) * plotH;
    const xTicks = tickIndexes(
        times,
        bucketMs,
        Math.max(2, Math.min(7, Math.floor(plotW / 110))),
    );
    const patternId = `sb-${title.replace(/[^a-zA-Z0-9]/g, '')}`;

    const onKeyDown = (event: KeyboardEvent<SVGSVGElement>) => {
        const last = times.length - 1;
        const index = active ?? last;

        if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
            event.preventDefault();
            setActive(
                event.key === 'ArrowLeft'
                    ? Math.max(0, index - 1)
                    : Math.min(last, index + 1),
            );
        } else if (event.key === 'Home' || event.key === 'End') {
            event.preventDefault();
            setActive(event.key === 'Home' ? 0 : last);
        } else if (event.key === 'Escape') {
            setActive(null);
        }
    };

    const legendValue = (s: StackedSeries) =>
        active !== null
            ? (s.values[active] ?? 0)
            : s.values.reduce((sum, v) => sum + v, 0);

    return (
        <figure className={cn('flex flex-col gap-3', className)}>
            <div className="flex items-center gap-4">
                <ul className="flex flex-wrap gap-x-4 gap-y-1 type-small text-muted-foreground">
                    {visible.map((s) => (
                        <li
                            key={s.key}
                            className="inline-flex items-center gap-1.5"
                        >
                            <Swatch series={s} />
                            <span className="text-foreground">{s.label}</span>
                            <span className="tabular-nums">
                                {formatValue(legendValue(s))}
                            </span>
                        </li>
                    ))}
                </ul>
                <figcaption className="sr-only">{title}</figcaption>
                {table && (
                    <button
                        type="button"
                        onClick={() => setShowTable((value) => !value)}
                        aria-pressed={showTable}
                        className="ml-auto inline-flex h-7 shrink-0 items-center gap-1.5 rounded-md px-2 type-small text-muted-foreground hover:bg-(--hover) hover:text-foreground focus-visible:outline-2 focus-visible:outline-(--signal)"
                    >
                        <Table2 aria-hidden="true" className="size-3.5" />
                        {showTable ? 'Chart' : 'Table'}
                    </button>
                )}
            </div>

            {showTable ? (
                <div
                    className="overflow-auto rounded-lg border"
                    style={{ maxHeight: height + 40 }}
                >
                    <table className="w-full type-small tabular-nums">
                        <caption className="sr-only">{title}</caption>
                        <thead className="sticky top-0 bg-card text-left text-muted-foreground">
                            <tr className="border-b">
                                <th
                                    scope="col"
                                    className="px-3 py-2 font-medium"
                                >
                                    Time
                                </th>
                                {visible.map((s) => (
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
                                    <tr
                                        key={t}
                                        className="border-b last:border-0"
                                    >
                                        <th
                                            scope="row"
                                            className="px-3 py-1.5 text-left font-normal text-muted-foreground"
                                        >
                                            {formatTooltipTime(t, span)}
                                        </th>
                                        {visible.map((s) => (
                                            <td
                                                key={s.key}
                                                className="px-3 py-1.5 text-right"
                                            >
                                                {formatValue(
                                                    s.values[index] ?? 0,
                                                )}
                                            </td>
                                        ))}
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            ) : (
                <div ref={ref} className="relative w-full" style={{ height }}>
                    {width > 0 && (
                        <svg
                            width={width}
                            height={height}
                            className="block outline-none focus-visible:rounded-md focus-visible:ring-2 focus-visible:ring-(--signal)"
                            role="img"
                            aria-label={`${title}. Use the arrow keys to read values.`}
                            tabIndex={0}
                            onKeyDown={onKeyDown}
                            onBlur={() => setActive(null)}
                            onPointerLeave={() => setActive(null)}
                            onPointerMove={(event) => {
                                const rect =
                                    event.currentTarget.getBoundingClientRect();
                                const index = Math.floor(
                                    (event.clientX - rect.left - PAD.left) /
                                        Math.max(1, slot),
                                );
                                setActive(
                                    index < 0 || index >= times.length
                                        ? null
                                        : index,
                                );
                            }}
                        >
                            <defs>
                                <pattern
                                    id={patternId}
                                    width={4}
                                    height={4}
                                    patternUnits="userSpaceOnUse"
                                    patternTransform="rotate(45)"
                                >
                                    <rect
                                        width={1.5}
                                        height={4}
                                        style={{
                                            fill: 'var(--card)',
                                            opacity: 0.55,
                                        }}
                                    />
                                </pattern>
                            </defs>
                            {[0, 0.5, 1].map((f, index) => (
                                <g key={f}>
                                    <line
                                        x1={PAD.left}
                                        x2={width - PAD.right}
                                        y1={y(top * f)}
                                        y2={y(top * f)}
                                        strokeWidth={1}
                                        style={{ stroke: 'var(--border)' }}
                                        strokeDasharray={
                                            index === 0 ? undefined : '2 3'
                                        }
                                    />
                                    <text
                                        x={PAD.left - 6}
                                        y={y(top * f) + 4}
                                        textAnchor="end"
                                        className="fill-muted-foreground text-[11px] tabular-nums"
                                    >
                                        {formatValue(top * f)}
                                    </text>
                                </g>
                            ))}
                            {times.map((t, i) => {
                                let base = 0;
                                const bx = PAD.left + i * slot + gap / 2;

                                return (
                                    <g
                                        key={t}
                                        opacity={
                                            active !== null && active !== i
                                                ? 0.5
                                                : 1
                                        }
                                    >
                                        {visible.map((s) => {
                                            const v = s.values[i] ?? 0;

                                            if (v <= 0) {
                                                return null;
                                            }

                                            const y0 = y(base);
                                            base += v;
                                            const h = Math.max(1, y0 - y(base));

                                            return (
                                                <g key={s.key}>
                                                    <rect
                                                        x={bx}
                                                        y={y0 - h}
                                                        width={barW}
                                                        height={h}
                                                        style={{
                                                            fill: s.color,
                                                        }}
                                                    />
                                                    {s.hatched && h > 3 && (
                                                        <rect
                                                            x={bx}
                                                            y={y0 - h}
                                                            width={barW}
                                                            height={h}
                                                            fill={`url(#${patternId})`}
                                                        />
                                                    )}
                                                </g>
                                            );
                                        })}
                                    </g>
                                );
                            })}
                            {xTicks.map((index) => (
                                <text
                                    key={index}
                                    x={PAD.left + index * slot + slot / 2}
                                    y={height - 4}
                                    textAnchor={
                                        index * slot < 24
                                            ? 'start'
                                            : (times.length - index) * slot < 24
                                              ? 'end'
                                              : 'middle'
                                    }
                                    className="fill-muted-foreground text-[11px] tabular-nums"
                                >
                                    {formatTick(times[index], span)}
                                </text>
                            ))}
                        </svg>
                    )}
                    {active !== null && width > 0 && (
                        <div
                            aria-live="polite"
                            className="pointer-events-none absolute top-0 z-10 flex w-52 flex-col gap-1 rounded-md border bg-popover px-2.5 py-2 type-small whitespace-nowrap tabular-nums shadow-[0_8px_30px_rgb(0_0_0/0.10)] dark:border-(--border-strong)"
                            style={{
                                left: Math.max(
                                    0,
                                    PAD.left + (active + 1) * slot + 8 + 208 >
                                        width
                                        ? PAD.left + active * slot - 8 - 208
                                        : PAD.left + (active + 1) * slot + 8,
                                ),
                            }}
                        >
                            <span className="text-muted-foreground">
                                {formatTooltipTime(times[active], span)}
                            </span>
                            {[...visible].reverse().map((s) => (
                                <span
                                    key={s.key}
                                    className="flex items-center gap-1.5"
                                >
                                    <Swatch series={s} />
                                    <span>{s.label}</span>
                                    <span className="ml-auto pl-3">
                                        {formatValue(s.values[active] ?? 0)}
                                    </span>
                                </span>
                            ))}
                            <span className="flex border-t pt-1 font-medium">
                                Total
                                <span className="ml-auto pl-3">
                                    {formatValue(totals[active])}
                                </span>
                            </span>
                        </div>
                    )}
                </div>
            )}
        </figure>
    );
}

/**
 * Bucket indexes to label on the time axis: for hourly buckets over several
 * days the starts of local days (every n-th day so at most `max` labels),
 * otherwise evenly spaced buckets.
 */
export function tickIndexes(
    times: number[],
    bucketMs: number,
    max: number,
): number[] {
    if (times.length === 0) {
        return [];
    }

    const span = times[times.length - 1] + bucketMs - times[0];

    if (bucketMs < 86_400_000 && span > 2 * 86_400_000) {
        const days = times
            .map((t, i) => [t, i] as const)
            .filter(([t]) => {
                const d = new Date(t);

                return d.getHours() === 0 && d.getMinutes() === 0;
            })
            .map(([, i]) => i);
        const step = Math.max(1, Math.ceil(days.length / max));

        return days.filter((_, k) => k % step === 0);
    }

    const count = Math.min(max, times.length);

    if (count < 2) {
        return [0];
    }

    return Array.from(
        new Set(
            Array.from({ length: count }, (_, k) =>
                Math.round((k * (times.length - 1)) / (count - 1)),
            ),
        ),
    );
}

function Swatch({ series }: { series: StackedSeries }) {
    return (
        <span
            aria-hidden="true"
            className="size-2.5 shrink-0 rounded-[3px]"
            style={{
                backgroundColor: series.color,
                backgroundImage: series.hatched
                    ? 'repeating-linear-gradient(45deg, transparent 0 2px, color-mix(in oklab, var(--card) 55%, transparent) 2px 3.5px)'
                    : undefined,
            }}
        />
    );
}
