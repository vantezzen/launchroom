import type { TimeRangeOption } from '@/components/charts/time-range-select';

/**
 * The observe scope (docs/08 §1, design-system §5.3): one time range and
 * one service filter shared by Logs, Analytics (Traffic and Resources) and
 * Security, kept in the URL (`?range=`, `?service=`, and for an absolute
 * window `?from=` and `?to=` in epoch seconds) so moving between those
 * pages keeps what you were looking at. Each page supports a subset of the ranges and snaps
 * anything else to the nearest one it has.
 */
export type ObserveRange = '15m' | '1h' | '24h' | '7d' | '30d';

export const RANGE_SECONDS: Record<ObserveRange, number> = {
    '15m': 900,
    '1h': 3600,
    '24h': 86_400,
    '7d': 604_800,
    '30d': 2_592_000,
};

/** Control labels, the same on every page. */
export const RANGE_LABEL: Record<ObserveRange, string> = {
    '15m': '15 min',
    '1h': 'Hour',
    '24h': 'Day',
    '7d': 'Week',
    '30d': 'Month',
};

/** The range in a sentence: "Requests per hour, last 7 days". */
export const RANGE_WORDS: Record<ObserveRange, string> = {
    '15m': 'last 15 minutes',
    '1h': 'last hour',
    '24h': 'last 24 hours',
    '7d': 'last 7 days',
    '30d': 'last 30 days',
};

/** Every observe page opens on the last day unless the URL says otherwise. */
export const DEFAULT_RANGE: ObserveRange = '24h';

/** The ranges each page offers. */
export const RANGE_SETS = {
    logs: ['15m', '1h', '24h', '7d', '30d'],
    /** Analytics → Resources and the server pages (usage samples). */
    resources: ['1h', '24h', '7d'],
    /** Analytics → Traffic, team Analytics and Security (hourly rollups). */
    analytics: ['24h', '7d', '30d'],
} as const satisfies Record<string, readonly ObserveRange[]>;

export function isObserveRange(value: unknown): value is ObserveRange {
    return typeof value === 'string' && value in RANGE_SECONDS;
}

/** "last 7 days"; unknown values read as the default range. */
export function rangeWords(range: string): string {
    return RANGE_WORDS[isObserveRange(range) ? range : DEFAULT_RANGE];
}

export function rangeOptions<T extends ObserveRange>(
    supported: readonly T[],
): TimeRangeOption<T>[] {
    return supported.map((value) => ({ value, label: RANGE_LABEL[value] }));
}

/**
 * The supported range closest to `value` (by ratio, so 1h → Day rather than
 * nothing, 30d → Week on Resources). Missing or unknown values give the
 * default range when it's supported, else the nearest one to it.
 */
export function snapRange<T extends ObserveRange>(
    value: string | null | undefined,
    supported: readonly T[],
): T {
    const wanted = isObserveRange(value) ? value : DEFAULT_RANGE;

    if ((supported as readonly ObserveRange[]).includes(wanted)) {
        return wanted as T;
    }

    const target = Math.log(RANGE_SECONDS[wanted]);

    return [...supported].sort(
        (a, b) =>
            Math.abs(Math.log(RANGE_SECONDS[a]) - target) -
                Math.abs(Math.log(RANGE_SECONDS[b]) - target) ||
            RANGE_SECONDS[b] - RANGE_SECONDS[a],
    )[0];
}

/** An absolute window in epoch milliseconds. */
export type ObserveWindow = { from: number; to: number };

export type ObserveScope = {
    range: ObserveRange | null;
    service: string | null;
    window: ObserveWindow | null;
};

/** Reads the scope from a query string (`window.location.search`). */
export function readScope(search: string): ObserveScope {
    const params = new URLSearchParams(search);
    const range = params.get('range');
    const from = Number(params.get('from'));
    const to = Number(params.get('to'));

    return {
        range: isObserveRange(range) ? range : null,
        service: params.get('service') || null,
        window:
            Number.isFinite(from) &&
            Number.isFinite(to) &&
            from > 0 &&
            to > from
                ? { from: from * 1000, to: to * 1000 }
                : null,
    };
}

/**
 * Query parameters for a link that keeps the scope. The default range is
 * left out so plain links stay plain.
 */
export function scopeQuery(scope: {
    range?: string | null;
    service?: string | null;
    window?: ObserveWindow | null;
}): Record<string, string> {
    const query: Record<string, string> = {};

    if (scope.range && scope.range !== DEFAULT_RANGE) {
        query.range = scope.range;
    }

    if (scope.service) {
        query.service = scope.service;
    }

    if (scope.window) {
        query.from = String(Math.floor(scope.window.from / 1000));
        query.to = String(Math.ceil(scope.window.to / 1000));
    }

    return query;
}

/** Appends the scope to a path: `withScope('/p/x/y/logs', scope)`. */
export function withScope(
    href: string,
    scope: Parameters<typeof scopeQuery>[0],
): string {
    const query = new URLSearchParams(scopeQuery(scope)).toString();

    if (query === '') {
        return href;
    }

    return `${href}${href.includes('?') ? '&' : '?'}${query}`;
}

/** Fired on `window` after replaceQuery() changed the address bar. */
export const SCOPE_EVENT = 'launchroom:scope';

/**
 * Updates the address bar without a visit; null or '' removes a key.
 * Listeners of SCOPE_EVENT (the project tabs) pick up the new scope.
 */
export function replaceQuery(params: Record<string, string | null>): void {
    if (typeof window === 'undefined') {
        return;
    }

    const url = new URL(window.location.href);

    for (const [key, value] of Object.entries(params)) {
        if (value === null || value === '') {
            url.searchParams.delete(key);
        } else {
            url.searchParams.set(key, value);
        }
    }

    window.history.replaceState(window.history.state, '', url);
    window.dispatchEvent(new Event(SCOPE_EVENT));
}

/**
 * A window in words: "14:00 to 15:00", "12 Nov 14:00 to 13 Nov 02:00".
 * Times are local, 24-hour.
 */
export function formatWindow(span: ObserveWindow): string {
    const from = new Date(span.from);
    const to = new Date(span.to);
    const time = (date: Date) =>
        date.toLocaleTimeString('en-GB', {
            hour: '2-digit',
            minute: '2-digit',
            hour12: false,
        });
    const day = (date: Date) =>
        date.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
    const sameDay = from.toDateString() === to.toDateString();
    const today = from.toDateString() === new Date().toDateString();

    if (sameDay) {
        return `${today ? '' : `${day(from)} `}${time(from)} to ${time(to)}`;
    }

    return `${day(from)} ${time(from)} to ${day(to)} ${time(to)}`;
}

/**
 * The window around a moment that a drill-down opens: the bucket itself,
 * widened to at least `minMs` so a one-minute bucket still shows context.
 */
export function windowAround(
    from: number,
    to: number,
    minMs = 10 * 60_000,
): ObserveWindow {
    const span = to - from;

    if (span >= minMs) {
        return { from, to };
    }

    const pad = (minMs - span) / 2;

    return { from: from - pad, to: to + pad };
}
