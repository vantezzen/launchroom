/**
 * Compact durations (design-system §8): "42s", "1m 42s", "1h 3m".
 */
export function formatDuration(seconds: number | null): string | null {
    if (seconds === null) {
        return null;
    }

    if (seconds < 60) {
        return `${seconds}s`;
    }

    const minutes = Math.floor(seconds / 60);

    if (minutes < 60) {
        return `${minutes}m ${seconds % 60}s`;
    }

    return `${Math.floor(minutes / 60)}h ${minutes % 60}m`;
}

function toMillis(value: string | number): number {
    return typeof value === 'number' ? value : new Date(value).getTime();
}

/** Whole seconds from `from` to `to`, or to `now` while `to` is null. */
export function elapsedSeconds(
    from: string | number,
    to: string | number | null,
    now = Date.now(),
): number {
    const end = to === null ? now : toMillis(to);

    return Math.max(0, Math.floor((end - toMillis(from)) / 1000));
}
