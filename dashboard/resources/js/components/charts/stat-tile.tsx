import { useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';
import { formatTooltipTime } from './format';
import { findGaps, gapAt, gapLabel } from './gaps';
import { Sparkline } from './sparkline';
import { useMeasuredHeight } from './use-measure';

export type StatDelta = {
    label: string;
    /** good/bad only when the direction has a clear meaning (errors down = good). */
    tone?: 'good' | 'bad' | 'neutral';
};

type StatTileProps = {
    label: string;
    /** Current value as display text ("34", "5.1"); null → "–". */
    value: string | null;
    /** Muted unit after the value ("%", "GB", "/ 8 GB"). */
    unit?: string;
    delta?: StatDelta;
    /** One quiet line under the value ("avg 29%"). */
    hint?: ReactNode;
    /** Trend; hidden when it has fewer than two points (design-system §9). */
    series?: {
        values: (number | null)[];
        /** Epoch ms per value, for the hover readout. */
        times?: number[];
        min?: number;
        max?: number;
        format?: (value: number) => string;
        /** Server metrics (needs `times`): stretches without reports show as bands. */
        markGaps?: boolean;
    };
    /** Optional action/link slot in the header row. */
    action?: ReactNode;
    className?: string;
};

const deltaTone = {
    good: 'bg-(--status-ok-soft) text-(--status-ok)',
    bad: 'bg-(--status-error-soft) text-status-error',
    neutral: 'bg-(--hover) text-muted-foreground',
} as const;

/**
 * Metric tile (design-system §5.3, §9): label, value in the `stat` role with
 * a muted unit, an optional delta chip and an area sparkline that fills the
 * tile's free height (48 to 120 px), so tiles in a row line their charts up. Hovering
 * or arrowing through the sparkline swaps the hint for that point's time and
 * value. Without series data the tile is just the number.
 */
export function StatTile({
    label,
    value,
    unit,
    delta,
    hint,
    series,
    action,
    className,
}: StatTileProps) {
    const [active, setActive] = useState<number | null>(null);
    const [chartRef, chartHeight] = useMeasuredHeight<HTMLDivElement>();
    // The line starts at the first report: a server that joined (or a
    // metric that started) partway through the range fills the tile instead
    // of sitting in a corner. No axes, so nothing claims the empty part.
    const first = Math.max(
        0,
        (series?.values ?? []).findIndex((value) => value !== null),
    );
    const points = useMemo(
        () => (series?.values ?? []).slice(first),
        [series, first],
    );
    const times = useMemo(() => series?.times?.slice(first), [series, first]);
    const activeValue = active !== null ? points[active] : null;
    const span =
        times && times.length > 1 ? times[times.length - 1] - times[0] : 0;
    const gaps = useMemo(
        () => (series?.markGaps && times ? findGaps(times, [points]) : []),
        [series, times, points],
    );
    const activeGap = active !== null ? gapAt(gaps, active) : null;

    const readout = activeGap
        ? gapLabel(activeGap)
        : active !== null && activeValue !== null && activeValue !== undefined
          ? `${times?.[active] ? formatTooltipTime(times[active], span) + ': ' : ''}${
                series?.format ? series.format(activeValue) : activeValue
            }`
          : null;

    return (
        <div
            className={cn(
                'flex min-w-0 flex-col gap-3 rounded-[14px] border bg-card p-5 shadow-[0_1px_2px_rgb(0_0_0/0.04)] dark:shadow-none',
                className,
            )}
        >
            <div className="flex items-center justify-between gap-2">
                <span className="type-label text-muted-foreground">
                    {label}
                </span>
                {action}
            </div>
            <div className="flex items-baseline gap-2">
                <span className="text-2xl leading-[30px] font-semibold tracking-[-0.02em] tabular-nums">
                    {value ?? '–'}
                </span>
                {unit && value !== null && (
                    <span className="type-small text-muted-foreground tabular-nums">
                        {unit}
                    </span>
                )}
                {delta && (
                    <span
                        className={cn(
                            'ml-auto rounded-full px-1.5 py-px type-small tabular-nums',
                            deltaTone[delta.tone ?? 'neutral'],
                        )}
                    >
                        {delta.label}
                    </span>
                )}
            </div>
            {series && (
                // Fills the room a taller neighbour in the row leaves; out of
                // the flow, so the chart never makes the tile taller itself.
                <div ref={chartRef} className="relative min-h-12 flex-1">
                    <Sparkline
                        values={points}
                        min={series.min}
                        max={series.max}
                        height={Math.max(
                            48,
                            Math.min(120, Math.floor(chartHeight)),
                        )}
                        area
                        gaps={gaps}
                        label={`${label} trend${gaps.length > 0 ? `. ${gaps.map(gapLabel).join('. ')}` : ''}`}
                        onActiveChange={setActive}
                        className="absolute inset-x-0 bottom-0"
                    />
                </div>
            )}
            {(readout || hint) && (
                <p
                    className="-mt-1 type-small text-muted-foreground tabular-nums"
                    aria-live="polite"
                >
                    {readout ?? hint}
                </p>
            )}
        </div>
    );
}
