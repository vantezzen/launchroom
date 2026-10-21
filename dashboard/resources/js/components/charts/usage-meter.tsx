import { cn } from '@/lib/utils';
import { foldToOther, MAX_SERIES, SIGNAL } from './palette';
import type { Breakdown, ColouredBreakdown } from './palette';

function percent(value: number, total: number): number {
    return total > 0 ? (value / total) * 100 : 0;
}

function formatShare(value: number, total: number): string {
    const share = percent(value, total);

    return share > 0 && share < 1 ? '<1%' : `${Math.round(share)}%`;
}

export type UsageMeterProps = {
    /** Who uses what. One item without a slot → drawn in the signal colour. */
    items: Breakdown[];
    /** Capacity; the remainder is drawn as the empty track ("free"). */
    total: number;
    /** Accessible name, e.g. "Memory used by project". */
    label: string;
    /** Value formatter for the legend ("2.1 GB"); defaults to the share. */
    format?: (value: number) => string;
    /** Coloured items before folding into grey "other" (default 4). */
    max?: number;
    legend?: boolean;
    height?: number;
    className?: string;
};

/**
 * Segmented usage bar (design-system §5.3; maintainer decision §5 #2):
 * top few items in their own colour, the rest folded into grey, 2 px gaps,
 * free space as the empty track. Legend with values below for comparisons.
 */
export function UsageMeter({
    items,
    total,
    label,
    format,
    max = MAX_SERIES,
    legend = true,
    height = 8,
    className,
}: UsageMeterProps) {
    const single =
        items.length === 1 && items[0].slot == null && !items[0].other;
    const segments: ColouredBreakdown[] = single
        ? [{ ...items[0], color: SIGNAL, other: false }]
        : foldToOther(items, max);
    const used = segments.reduce((sum, item) => sum + item.value, 0);
    const describe = segments
        .map(
            (item) =>
                `${item.label} ${format ? format(item.value) : formatShare(item.value, total)}`,
        )
        .join(', ');

    return (
        <div className={cn('flex flex-col gap-2.5', className)}>
            <div
                role="img"
                aria-label={`${label}: ${describe || 'nothing'}; ${formatShare(used, total)} of capacity`}
                className="flex w-full gap-0.5 overflow-hidden rounded-full bg-(--border)"
                style={{ height }}
            >
                {segments.map((item) => (
                    <div
                        key={item.key}
                        className="h-full first:rounded-l-full last:rounded-r-full"
                        style={{
                            width: `${Math.min(100, percent(item.value, total))}%`,
                            minWidth: item.value > 0 ? 3 : 0,
                            backgroundColor: item.color,
                        }}
                    />
                ))}
            </div>
            {legend && !single && segments.length > 0 && (
                <ul className="flex flex-wrap gap-x-4 gap-y-1 type-small text-muted-foreground">
                    {segments.map((item) => (
                        <li
                            key={item.key}
                            className="inline-flex items-center gap-1.5"
                        >
                            <span
                                aria-hidden="true"
                                className="size-2 rounded-[2px]"
                                style={{ backgroundColor: item.color }}
                            />
                            <span className="text-foreground">
                                {item.label}
                            </span>
                            <span className="tabular-nums">
                                {format
                                    ? format(item.value)
                                    : formatShare(item.value, total)}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

/**
 * Single-value meter ("Disk 41%"): signal fill on the track. Turns amber /
 * red only past explicit thresholds, because then the colour means something.
 */
export function Meter({
    value,
    max = 100,
    label,
    warnAt,
    criticalAt,
    height = 6,
    className,
}: {
    value: number;
    max?: number;
    label: string;
    warnAt?: number;
    criticalAt?: number;
    height?: number;
    className?: string;
}) {
    const share = Math.min(100, Math.max(0, percent(value, max)));
    const color =
        criticalAt !== undefined && share >= criticalAt
            ? 'var(--status-error)'
            : warnAt !== undefined && share >= warnAt
              ? 'var(--status-progress)'
              : SIGNAL;

    return (
        <div
            role="meter"
            aria-label={label}
            aria-valuemin={0}
            aria-valuemax={max}
            aria-valuenow={value}
            className={cn(
                'w-full overflow-hidden rounded-full bg-(--border)',
                className,
            )}
            style={{ height }}
        >
            <div
                className="h-full rounded-full motion-safe:transition-[width] motion-safe:duration-[180ms]"
                style={{ width: `${share}%`, backgroundColor: color }}
            />
        </div>
    );
}
