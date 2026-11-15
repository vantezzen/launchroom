/** Number formatting shared by the charts (design-system §10: compact, tabular). */

function formatClock(ms: number, withSeconds = false): string {
    const date = new Date(ms);

    return date.toLocaleTimeString('en-GB', {
        hour: '2-digit',
        minute: '2-digit',
        ...(withSeconds ? { second: '2-digit' } : {}),
    });
}

function formatDay(ms: number): string {
    return new Date(ms).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
    });
}

/** Axis/tooltip label for a timestamp given the span of the chart. */
export function formatTick(ms: number, spanMs: number): string {
    if (spanMs > 2 * 24 * 3600_000) {
        return formatDay(ms);
    }

    return formatClock(ms);
}

export function formatTooltipTime(ms: number, spanMs: number): string {
    if (spanMs > 2 * 24 * 3600_000) {
        return `${formatDay(ms)} ${formatClock(ms)}`;
    }

    return formatClock(ms, spanMs < 2 * 3600_000);
}

/** "Nice" upper bound for a y-axis (1, 2, 2.5, 5 × 10^n). */
export function niceMax(value: number): number {
    if (value <= 0 || !Number.isFinite(value)) {
        return 1;
    }

    const exponent = Math.floor(Math.log10(value));
    const base = 10 ** exponent;

    for (const step of [1, 2, 2.5, 5, 10]) {
        if (value <= step * base) {
            return step * base;
        }
    }

    return 10 * base;
}
