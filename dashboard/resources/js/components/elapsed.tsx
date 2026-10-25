import type { ReactNode } from 'react';
import { useNow } from '@/hooks/use-now';
import { elapsedSeconds, formatDuration } from '@/lib/duration';
import { cn } from '@/lib/utils';

type ClockFormat = (seconds: number) => string;

const compact: ClockFormat = (seconds) => formatDuration(seconds) ?? '';

/**
 * A fixed duration ("1m 42s", design-system §8) with tabular figures, so
 * neighbours don't shift when the number changes.
 */
export function Duration({
    seconds,
    format = compact,
    fallback = null,
    className,
}: {
    seconds: number | null;
    format?: ClockFormat;
    fallback?: ReactNode;
    className?: string;
}) {
    if (seconds === null) {
        return <>{fallback}</>;
    }

    return (
        <time
            dateTime={`PT${Math.max(0, Math.round(seconds))}S`}
            className={cn('tabular-nums', className)}
        >
            {format(Math.max(0, Math.round(seconds)))}
        </time>
    );
}

/**
 * Time since `since`: ticks every second on the page's shared clock while
 * `until` is null, and shows the fixed duration once it is set. Numbers keep
 * updating under reduced motion (only animation is reduced).
 */
export function Elapsed({
    since,
    until = null,
    format,
    fallback = null,
    className,
}: {
    since: string | number | null;
    until?: string | number | null;
    format?: ClockFormat;
    fallback?: ReactNode;
    className?: string;
}) {
    const running = since !== null && until === null;
    const now = useNow(running);

    return (
        <Duration
            seconds={since === null ? null : elapsedSeconds(since, until, now)}
            format={format}
            fallback={fallback}
            className={className}
        />
    );
}
