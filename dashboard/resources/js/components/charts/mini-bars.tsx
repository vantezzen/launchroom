import { cn } from '@/lib/utils';
import { OTHER, SIGNAL, TRACK } from './palette';

export type MiniBar = {
    key: string;
    /** Tooltip/table label, e.g. "Oct 12". */
    label: string;
    value: number;
    /** Part of `value` that failed (drawn red, at the base). */
    failed?: number;
    /** Today / the current period: drawn in the signal colour. */
    current?: boolean;
};

/**
 * Small frequency bars (design-system §5.3), e.g. 14 days of deploys:
 * neutral bars, failures in red at the base, the current period in signal
 * blue. Zero periods keep a 2 px stub so the rhythm stays readable. Renders
 * nothing when every bar is zero.
 */
export function MiniBars({
    bars,
    label,
    height = 28,
    unit = 'deploys',
    className,
}: {
    bars: MiniBar[];
    /** Accessible summary, e.g. "Deploys in the last 14 days". */
    label: string;
    height?: number;
    unit?: string;
    className?: string;
}) {
    const max = Math.max(0, ...bars.map((bar) => bar.value));

    if (max === 0) {
        return null;
    }

    const total = bars.reduce((sum, bar) => sum + bar.value, 0);
    const failed = bars.reduce((sum, bar) => sum + (bar.failed ?? 0), 0);

    return (
        <svg
            viewBox={`0 0 ${bars.length * 6 - 2} ${height}`}
            preserveAspectRatio="none"
            className={cn('block w-full', className)}
            style={{ height }}
            role="img"
            aria-label={`${label}: ${total} ${unit}${failed ? `, ${failed} failed` : ''}`}
        >
            {bars.map((bar, index) => {
                const x = index * 6;
                const h =
                    bar.value === 0
                        ? 2
                        : Math.max(3, (bar.value / max) * height);
                const failedH = bar.failed
                    ? Math.max(2, (bar.failed / max) * height)
                    : 0;
                const fill =
                    bar.value === 0 ? TRACK : bar.current ? SIGNAL : OTHER;

                return (
                    <g key={bar.key}>
                        <title>
                            {`${bar.label}: ${bar.value} ${unit}${bar.failed ? ` (${bar.failed} failed)` : ''}`}
                        </title>
                        <rect
                            x={x}
                            y={height - h}
                            width={4}
                            height={h}
                            rx={1}
                            style={{ fill }}
                        />
                        {failedH > 0 && (
                            <rect
                                x={x}
                                y={height - failedH}
                                width={4}
                                height={failedH}
                                rx={1}
                                style={{ fill: 'var(--status-error)' }}
                            />
                        )}
                    </g>
                );
            })}
        </svg>
    );
}
