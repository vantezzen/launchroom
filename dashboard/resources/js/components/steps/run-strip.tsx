import { cn } from '@/lib/utils';

export type Run = {
    key: string;
    status: 'ok' | 'failed' | 'none';
    /** Tooltip text, e.g. "Oct 12: succeeded, 4.1 GB". */
    label: string;
};

const fill: Record<Run['status'], string> = {
    ok: 'var(--status-ok)',
    failed: 'var(--status-error)',
    none: 'var(--border)',
};

/**
 * One bar per run or day (design-system §5.4): backups, health history.
 * Green ok, red failed, track for "no run". Summary in the accessible name.
 */
export function RunStrip({
    runs,
    label,
    height = 20,
    className,
}: {
    runs: Run[];
    label: string;
    height?: number;
    className?: string;
}) {
    if (runs.length === 0) {
        return null;
    }

    const ok = runs.filter((run) => run.status === 'ok').length;
    const failed = runs.filter((run) => run.status === 'failed').length;

    return (
        <svg
            viewBox={`0 0 ${runs.length * 5 - 2} ${height}`}
            preserveAspectRatio="none"
            className={cn('block w-full', className)}
            style={{ height }}
            role="img"
            aria-label={`${label}: ${ok} succeeded, ${failed} failed, ${runs.length - ok - failed} without a run`}
        >
            {runs.map((run, index) => (
                <rect
                    key={run.key}
                    x={index * 5}
                    y={0}
                    width={3}
                    height={height}
                    rx={1}
                    style={{ fill: fill[run.status] }}
                >
                    <title>{run.label}</title>
                </rect>
            ))}
        </svg>
    );
}
