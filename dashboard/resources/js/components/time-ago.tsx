import { useNow } from '@/hooks/use-now';

const units: [Intl.RelativeTimeFormatUnit, number][] = [
    ['day', 86_400],
    ['hour', 3_600],
    ['minute', 60],
    ['second', 1],
];

const formatter = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });

export function relativeTime(iso: string, now: number = Date.now()): string {
    const seconds = Math.round((new Date(iso).getTime() - now) / 1000);

    for (const [unit, size] of units) {
        if (Math.abs(seconds) >= size || unit === 'second') {
            return formatter.format(Math.round(seconds / size), unit);
        }
    }

    return '';
}

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
