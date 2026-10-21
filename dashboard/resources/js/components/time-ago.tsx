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
 * Relative time with the absolute time on hover (design-system §8).
 */
export function TimeAgo({
    value,
    fallback = 'Never',
}: {
    value: string | null;
    fallback?: string;
}) {
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
