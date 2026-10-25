import { useState } from 'react';
import type { KeyboardEvent } from 'react';
import { cn } from '@/lib/utils';
import { formatCompact, formatTick, formatTooltipTime } from './format';
import type { ChartMarker } from './time-series-chart';
import { useMeasuredWidth } from './use-measure';

export type HistogramBucket = {
    /** Epoch ms, inclusive. */
    start: number;
    /** Epoch ms, exclusive. */
    end: number;
    total: number;
    /** Part of `total` that are errors: stacked red at the base. */
    errors?: number;
};

export type HistogramProps = {
    buckets: HistogramBucket[];
    /** Accessible name ("Log lines per minute"). */
    label: string;
    markers?: ChartMarker[];
    height?: number;
    /** Click/Enter on a bucket (e.g. narrow the log range to it). */
    onSelect?: (bucket: HistogramBucket) => void;
    /** Highlighted bucket range (e.g. the selected log line's minute). */
    highlight?: number | null;
    unit?: string;
    className?: string;
};

const AXIS = 18;

/**
 * Log explorer timeline (design-system §5.5): one bar per
 * bucket in a quiet neutral, errors stacked in red, deploy markers as signal
 * ticks with their label, a recessive time axis. Hover or arrow keys show a
 * one-line readout; Enter/click selects the bucket. Renders nothing without
 * buckets; all-zero buckets keep the axis so "no lines" reads as a fact.
 */
export function Histogram({
    buckets,
    label,
    markers = [],
    height = 72,
    onSelect,
    highlight = null,
    unit = 'lines',
    className,
}: HistogramProps) {
    const [ref, width] = useMeasuredWidth<HTMLDivElement>();
    const [active, setActive] = useState<number | null>(null);

    if (buckets.length === 0) {
        return null;
    }

    const t0 = buckets[0].start;
    const t1 = buckets[buckets.length - 1].end;
    const span = Math.max(1, t1 - t0);
    const max = Math.max(1, ...buckets.map((bucket) => bucket.total));
    const plotH = height - AXIS;
    const slot = width / buckets.length;
    const gap = slot > 6 ? 2 : slot > 3 ? 1 : 0;
    const barW = Math.max(1, slot - gap);
    const x = (t: number) => ((t - t0) / span) * width;
    const totalLines = buckets.reduce((sum, bucket) => sum + bucket.total, 0);
    const totalErrors = buckets.reduce(
        (sum, bucket) => sum + (bucket.errors ?? 0),
        0,
    );

    const ticks = Array.from(
        { length: 5 },
        (_, index) => t0 + (span * index) / 4,
    );
    const current = active !== null ? buckets[active] : null;

    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        const last = buckets.length - 1;
        const index = active ?? last;

        if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
            event.preventDefault();
            setActive(
                event.key === 'ArrowLeft'
                    ? Math.max(0, index - 1)
                    : Math.min(last, index + 1),
            );
        } else if (
            (event.key === 'Enter' || event.key === ' ') &&
            onSelect &&
            active !== null
        ) {
            event.preventDefault();
            onSelect(buckets[active]);
        } else if (event.key === 'Escape') {
            setActive(null);
        }
    };

    return (
        <div className={cn('relative', className)}>
            <div
                ref={ref}
                role="img"
                aria-label={`${label}: ${totalLines} ${unit}${totalErrors ? `, ${totalErrors} errors` : ''}. Use the arrow keys to read buckets.`}
                tabIndex={0}
                className="relative w-full rounded-sm outline-none focus-visible:ring-2 focus-visible:ring-(--signal)"
                style={{ height }}
                onKeyDown={onKeyDown}
                onBlur={() => setActive(null)}
                onPointerLeave={() => setActive(null)}
                onPointerMove={(event) => {
                    const rect = event.currentTarget.getBoundingClientRect();
                    const index = Math.floor(
                        ((event.clientX - rect.left) / rect.width) *
                            buckets.length,
                    );
                    setActive(Math.min(buckets.length - 1, Math.max(0, index)));
                }}
                onClick={() => {
                    if (active !== null && onSelect) {
                        onSelect(buckets[active]);
                    }
                }}
            >
                {width > 0 && (
                    <svg
                        width={width}
                        height={height}
                        className="block"
                        aria-hidden="true"
                    >
                        <line
                            x1={0}
                            x2={width}
                            y1={plotH + 0.5}
                            y2={plotH + 0.5}
                            strokeWidth={1}
                            style={{ stroke: 'var(--border)' }}
                        />
                        {buckets.map((bucket, index) => {
                            const h =
                                bucket.total === 0
                                    ? 0
                                    : Math.max(
                                          2,
                                          (bucket.total / max) * (plotH - 14),
                                      );
                            const errH = bucket.errors
                                ? Math.max(
                                      2,
                                      (bucket.errors / max) * (plotH - 14),
                                  )
                                : 0;
                            const bx = index * slot + gap / 2;
                            const dim =
                                active !== null
                                    ? active !== index
                                    : highlight !== null &&
                                      (highlight < bucket.start ||
                                          highlight >= bucket.end);

                            return (
                                <g key={bucket.start} opacity={dim ? 0.55 : 1}>
                                    {h > 0 && (
                                        <rect
                                            x={bx}
                                            y={plotH - h}
                                            width={barW}
                                            height={h}
                                            rx={Math.min(1.5, barW / 2)}
                                            style={{
                                                fill: 'var(--chart-other)',
                                            }}
                                        />
                                    )}
                                    {errH > 0 && (
                                        <rect
                                            x={bx}
                                            y={plotH - errH}
                                            width={barW}
                                            height={errH}
                                            rx={Math.min(1.5, barW / 2)}
                                            style={{
                                                fill: 'var(--status-error)',
                                            }}
                                        />
                                    )}
                                </g>
                            );
                        })}
                        {markers
                            .filter(
                                (marker) => marker.t >= t0 && marker.t <= t1,
                            )
                            .map((marker) => (
                                <g key={`${marker.t}-${marker.label}`}>
                                    <line
                                        x1={x(marker.t)}
                                        x2={x(marker.t)}
                                        y1={0}
                                        y2={plotH}
                                        strokeWidth={1.5}
                                        style={{ stroke: 'var(--signal)' }}
                                    />
                                    <text
                                        x={x(marker.t) + 4}
                                        y={9}
                                        className="font-mono text-[10px]"
                                        style={{ fill: 'var(--signal)' }}
                                    >
                                        {marker.label}
                                    </text>
                                </g>
                            ))}
                        {ticks.map((tick, index) => (
                            <text
                                key={tick}
                                x={x(tick)}
                                y={height - 3}
                                textAnchor={
                                    index === 0
                                        ? 'start'
                                        : index === ticks.length - 1
                                          ? 'end'
                                          : 'middle'
                                }
                                className="fill-muted-foreground text-[11px] tabular-nums"
                            >
                                {formatTick(tick, span)}
                            </text>
                        ))}
                    </svg>
                )}
            </div>
            {current && (
                <div
                    aria-live="polite"
                    className="pointer-events-none absolute -top-1 z-10 -translate-y-full rounded-md border bg-popover px-2 py-1 type-small whitespace-nowrap tabular-nums shadow-[0_8px_30px_rgb(0_0_0/0.10)] dark:border-(--border-strong)"
                    style={{
                        left: Math.min(
                            Math.max(0, (active ?? 0) * slot - 60),
                            Math.max(0, width - 200),
                        ),
                    }}
                >
                    <span className="text-muted-foreground">
                        {formatTooltipTime(current.start, span)}:
                    </span>{' '}
                    {formatCompact(current.total)} {unit}
                    {current.errors ? (
                        <span className="text-status-error">
                            , {current.errors} errors
                        </span>
                    ) : null}
                </div>
            )}
        </div>
    );
}
