import type { ReactNode } from 'react';
import { useNow } from '@/hooks/use-now';
import { cn } from '@/lib/utils';
import { elapsedSeconds, formatDuration } from '@/lib/format';

/**
 * A fixed duration ("1m 42s", design-system §8) with tabular figures, so
 * neighbours don't shift when the number changes.
 */
export function Duration({
    seconds,
    fallback = null,
    className,
}: {
    seconds: number | null;
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
            {formatDuration(Math.max(0, Math.round(seconds)))}
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
    fallback = null,
    className,
}: {
    since: string | number | null;
    until?: string | number | null;
    fallback?: ReactNode;
    className?: string;
}) {
    const running = since !== null && until === null;
    const now = useNow(running);

    return (
        <Duration
            seconds={since === null ? null : elapsedSeconds(since, until, now)}
            fallback={fallback}
            className={className}
        />
    );
}
