import { useNow } from '@/hooks/use-now';
import { relativeTime } from '@/lib/format';

/**
 * Relative time with the absolute time on hover (design-system §8). Stays
 * current on the page's shared clock: every second for the first minute,
 * then every minute.
 */
export function TimeAgo({
    value,
    fallback = 'Never',
}: {
    value: string | null;
    fallback?: string;
}) {
    const recent =
        value !== null &&
        Math.abs(Date.now() - new Date(value).getTime()) < 60_000;
    // Re-renders on the shared clock; the label reads the time itself.
    useNow(value !== null, recent ? 1000 : 60_000);

    if (value === null) {
        return <span className="text-muted-foreground">{fallback}</span>;
    }

    return (
        <time
            dateTime={value}
            title={new Date(value).toLocaleString()}
            className="tabular-nums"
        >
            {relativeTime(value)}
        </time>
    );
}
