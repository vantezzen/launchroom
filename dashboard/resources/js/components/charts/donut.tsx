import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';
import { foldToOther, MAX_SERIES, SIGNAL } from './palette';
import type { Breakdown, ColouredBreakdown } from './palette';

export type DonutProps = {
    items: Breakdown[];
    /** Capacity; the unfilled arc is the track. Defaults to the sum of items. */
    total?: number;
    /** Accessible name ("Backup storage by kind"). */
    label: string;
    /** Big centre text ("23.8 GB") and its caption ("Used"). */
    centerValue?: ReactNode;
    centerLabel?: ReactNode;
    format?: (value: number) => string;
    size?: number;
    thickness?: number;
    max?: number;
    legend?: boolean;
    className?: string;
};

/**
 * Ring chart (design-system §5.3): total in the
 * centre, legend with values beside it for ≥ 2 items; at most 4 coloured
 * segments + grey "other". One item without a slot is a signal ring.
 */
export function Donut({
    items,
    total,
    label,
    centerValue,
    centerLabel,
    format = (value) => String(value),
    size = 132,
    thickness = 12,
    max = MAX_SERIES,
    legend = true,
    className,
}: DonutProps) {
    const single =
        items.length === 1 && items[0].slot == null && !items[0].other;
    const segments: ColouredBreakdown[] = single
        ? [{ ...items[0], color: SIGNAL, other: false }]
        : foldToOther(items, max);
    const sum = segments.reduce((acc, item) => acc + item.value, 0);
    const capacity = Math.max(total ?? sum, sum, 1e-9);
    const radius = (size - thickness) / 2;
    const circumference = 2 * Math.PI * radius;
    const gap = segments.length > 1 ? 3 : 0;

    let offset = 0;
    const arcs = segments.map((item) => {
        const length = (item.value / capacity) * circumference;
        const arc = {
            ...item,
            dash: Math.max(0.5, length - gap),
            offset,
        };
        offset += length;

        return arc;
    });

    return (
        <div className={cn('flex items-center gap-6', className)}>
            <div
                className="relative shrink-0"
                style={{ width: size, height: size }}
            >
                <svg
                    width={size}
                    height={size}
                    viewBox={`0 0 ${size} ${size}`}
                    role="img"
                    aria-label={`${label}: ${segments
                        .map((item) => `${item.label} ${format(item.value)}`)
                        .join(', ')}`}
                    className="-rotate-90"
                >
                    <circle
                        cx={size / 2}
                        cy={size / 2}
                        r={radius}
                        fill="none"
                        strokeWidth={thickness}
                        style={{ stroke: 'var(--border)' }}
                    />
                    {arcs.map((arc) => (
                        <circle
                            key={arc.key}
                            cx={size / 2}
                            cy={size / 2}
                            r={radius}
                            fill="none"
                            strokeWidth={thickness}
                            strokeDasharray={`${arc.dash} ${circumference}`}
                            strokeDashoffset={-arc.offset}
                            strokeLinecap={
                                segments.length === 1 ? 'round' : 'butt'
                            }
                            style={{ stroke: arc.color }}
                        />
                    ))}
                </svg>
                {(centerValue || centerLabel) && (
                    <div className="absolute inset-0 flex flex-col items-center justify-center text-center">
                        {centerValue && (
                            <span className="text-lg leading-6 font-semibold tracking-[-0.02em] tabular-nums">
                                {centerValue}
                            </span>
                        )}
                        {centerLabel && (
                            <span className="type-small text-muted-foreground">
                                {centerLabel}
                            </span>
                        )}
                    </div>
                )}
            </div>
            {legend && !single && segments.length > 0 && (
                <ul className="flex min-w-0 flex-col gap-1.5 type-small">
                    {segments.map((item) => (
                        <li key={item.key} className="flex items-center gap-2">
                            <span
                                aria-hidden="true"
                                className="size-2 shrink-0 rounded-[2px]"
                                style={{ backgroundColor: item.color }}
                            />
                            <span className="truncate">{item.label}</span>
                            <span className="ml-auto pl-3 text-muted-foreground tabular-nums">
                                {format(item.value)}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
