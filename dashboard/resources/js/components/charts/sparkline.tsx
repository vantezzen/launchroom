import { useId, useMemo, useState } from 'react';
import type { KeyboardEvent, PointerEvent } from 'react';
import { cn } from '@/lib/utils';
import { SIGNAL } from './palette';
import { useMeasuredWidth } from './use-measure';

export type SparklineProps = {
    /** Values in time order; `null` leaves a gap. */
    values: (number | null)[];
    /** Accessible description, e.g. "CPU, last hour, 12% to 34%". */
    label: string;
    height?: number;
    /** Line colour; defaults to the signal accent (single series rule). */
    color?: string;
    /** 22 % → 0 % fill under the line. */
    area?: boolean;
    /** Fixed scale (e.g. 0–100 for percentages); defaults to the data range. */
    min?: number;
    max?: number;
    strokeWidth?: number;
    /** Hover/keyboard crosshair; reports the active index (null on leave). */
    onActiveChange?: (index: number | null) => void;
    className?: string;
};

type Point = { x: number; y: number } | null;

function linePath(points: Point[]): string {
    let d = '';
    let pen = false;

    for (const point of points) {
        if (!point) {
            pen = false;
            continue;
        }

        d += `${pen ? 'L' : 'M'}${point.x.toFixed(2)} ${point.y.toFixed(2)}`;
        pen = true;
    }

    return d;
}

function areaPath(points: Point[], baseline: number): string {
    const runs: { x: number; y: number }[][] = [];
    let run: { x: number; y: number }[] = [];

    for (const point of points) {
        if (point) {
            run.push(point);
        } else if (run.length) {
            runs.push(run);
            run = [];
        }
    }

    if (run.length) {
        runs.push(run);
    }

    return runs
        .filter((segment) => segment.length > 1)
        .map(
            (segment) =>
                `M${segment[0].x.toFixed(2)} ${baseline}` +
                segment
                    .map((p) => `L${p.x.toFixed(2)} ${p.y.toFixed(2)}`)
                    .join('') +
                `L${segment[segment.length - 1].x.toFixed(2)} ${baseline}Z`,
        )
        .join('');
}

/**
 * Inline trend line (design-system §9): no axes, no gridlines, signal colour
 * by default. Renders nothing when there are fewer than two values, so a
 * caller never shows an empty chart frame; put the current value beside it
 * as text.
 */
export function Sparkline({
    values,
    label,
    height = 24,
    color = SIGNAL,
    area = false,
    min,
    max,
    strokeWidth = 1.75,
    onActiveChange,
    className,
}: SparklineProps) {
    const gradientId = `spark-${useId().replace(/[^a-zA-Z0-9_-]/g, '')}`;
    const [ref, width] = useMeasuredWidth<HTMLDivElement>();
    const [active, setActive] = useState<number | null>(null);
    const points = useMemo<Point[]>(() => {
        const present = values.filter((v): v is number => v !== null);

        if (width === 0 || present.length < 2) {
            return [];
        }

        const lo = min ?? Math.min(...present);
        const hi = max ?? Math.max(...present);
        const span = hi - lo || 1;
        const pad = strokeWidth;
        const step = values.length > 1 ? width / (values.length - 1) : 0;

        return values.map((value, index) =>
            value === null
                ? null
                : {
                      x: index * step,
                      y:
                          pad +
                          (1 -
                              (Math.min(Math.max(value, lo), hi) - lo) / span) *
                              (height - pad * 2),
                  },
        );
    }, [width, values, min, max, height, strokeWidth]);

    if (values.filter((v) => v !== null).length < 2) {
        return null;
    }

    const interactive = onActiveChange !== undefined;

    const setIndex = (index: number | null) => {
        setActive(index);
        onActiveChange?.(index);
    };

    const onPointerMove = (event: PointerEvent<HTMLDivElement>) => {
        const rect = event.currentTarget.getBoundingClientRect();
        const ratio = (event.clientX - rect.left) / rect.width;
        const index = Math.round(ratio * (values.length - 1));
        setIndex(Math.min(Math.max(index, 0), values.length - 1));
    };

    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') {
            return;
        }

        event.preventDefault();
        const current = active ?? values.length - 1;
        const next =
            event.key === 'ArrowLeft'
                ? Math.max(0, current - 1)
                : Math.min(values.length - 1, current + 1);
        setIndex(next);
    };

    const activePoint = active !== null ? points[active] : null;

    return (
        <div
            ref={ref}
            className={cn(
                'relative w-full',
                interactive && 'cursor-crosshair',
                className,
            )}
            style={{ height }}
            role="img"
            aria-label={label}
            tabIndex={interactive ? 0 : undefined}
            onPointerMove={interactive ? onPointerMove : undefined}
            onPointerLeave={interactive ? () => setIndex(null) : undefined}
            onBlur={interactive ? () => setIndex(null) : undefined}
            onKeyDown={interactive ? onKeyDown : undefined}
        >
            {width > 0 && (
                <svg
                    width={width}
                    height={height}
                    className="block overflow-visible"
                    aria-hidden="true"
                >
                    {area && (
                        <>
                            <defs>
                                <linearGradient
                                    id={gradientId}
                                    x1="0"
                                    x2="0"
                                    y1="0"
                                    y2="1"
                                >
                                    <stop
                                        offset="0%"
                                        style={{
                                            stopColor: color,
                                            stopOpacity: 0.22,
                                        }}
                                    />
                                    <stop
                                        offset="100%"
                                        style={{
                                            stopColor: color,
                                            stopOpacity: 0,
                                        }}
                                    />
                                </linearGradient>
                            </defs>
                            <path
                                d={areaPath(points, height)}
                                fill={`url(#${gradientId})`}
                            />
                        </>
                    )}
                    <path
                        d={linePath(points)}
                        fill="none"
                        style={{ stroke: color }}
                        strokeWidth={strokeWidth}
                        strokeLinejoin="round"
                        strokeLinecap="round"
                    />
                    {activePoint && (
                        <>
                            <line
                                x1={activePoint.x}
                                x2={activePoint.x}
                                y1={0}
                                y2={height}
                                style={{ stroke: 'var(--border-strong)' }}
                                strokeWidth={1}
                            />
                            <circle
                                cx={activePoint.x}
                                cy={activePoint.y}
                                r={3}
                                style={{ fill: 'var(--card)', stroke: color }}
                                strokeWidth={1.75}
                            />
                        </>
                    )}
                </svg>
            )}
        </div>
    );
}
