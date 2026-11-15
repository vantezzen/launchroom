/**
 * Display formats shared by every page (design-system §8): sizes with one
 * decimal under 10 ("4.1 GB"), compact durations ("1m 42s"), compact ages
 * ("2m", "2m ago").
 */

const byteUnits = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];

/** Bytes in binary units with one decimal under 10 ("4.1 GB", "512 MB"). */
export function formatBytes(bytes: number | null, empty = '—'): string {
    if (bytes === null) {
        return empty;
    }

    let value = Math.abs(bytes);
    let unit = 0;

    while (value >= 1024 && unit < byteUnits.length - 1) {
        value /= 1024;
        unit++;
    }

    const digits = value < 10 && unit > 0 ? 1 : 0;

    return `${(Math.sign(bytes) * value).toFixed(digits)} ${byteUnits[unit]}`;
}

const compact = new Intl.NumberFormat('en', {
    notation: 'compact',
    maximumFractionDigits: 1,
});

/** Large counts: "1.2K", "34M". */
export function formatCompact(value: number): string {
    return compact.format(value);
}

/** Requests per second: "12.4", "0.03", "1.2K". */
export function formatRate(value: number): string {
    if (value === 0) {
        return '0';
    }

    if (value < 0.01) {
        return '<0.01';
    }

    if (value < 0.1) {
        return value.toFixed(2);
    }

    return value < 1000
        ? value.toFixed(value < 10 ? 1 : 0)
        : formatCompact(value);
}

export function formatBandwidth(bytesPerSecond: number): string {
    return `${formatBytes(bytesPerSecond)}/s`;
}

/** Megabytes (memory limits and usage, disk sizes) in the units of formatBytes. */
export function formatMemory(
    mb: number | null | undefined,
    empty = '—',
): string {
    if (mb === null || mb === undefined || !Number.isFinite(mb)) {
        return empty;
    }

    return mb === 0 ? '0 MB' : formatBytes(mb * 1024 * 1024);
}

/** CPU cores: "0.25 cores", "1 core", "2.5 cores". */
export function formatCores(value: number): string {
    const rounded = Number(value.toFixed(2));

    return `${rounded} ${rounded === 1 ? 'core' : 'cores'}`;
}

/** Compact durations: "42s", "1m 42s", "1h 3m". */
export function formatDuration(seconds: number): string;
export function formatDuration(seconds: number | null): string | null;
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

/** A compact duration from milliseconds, rounded to whole seconds. */
export function formatMs(ms: number): string;
export function formatMs(ms: number | null): string | null;
export function formatMs(ms: number | null): string | null {
    return ms === null
        ? null
        : formatDuration(Math.max(0, Math.round(ms / 1000)));
}

/** Latency of requests and server links: "0.4 ms", "38 ms", "1.2 s", "> 30 s". */
export function formatLatency(ms: number): string {
    if (ms >= 30_000) {
        return '> 30 s';
    }

    if (ms >= 1000) {
        return `${(ms / 1000).toFixed(ms < 10_000 ? 1 : 0)} s`;
    }

    return ms < 10 ? `${ms.toFixed(1)} ms` : `${Math.round(ms)} ms`;
}

/** An estimate in seconds: "under a minute", "about 3 min", "about 1 h 5 min". */
export function formatEstimate(seconds: number | null): string {
    if (seconds === null) {
        return 'unknown';
    }

    if (seconds < 60) {
        return 'under a minute';
    }

    const minutes = Math.ceil(seconds / 60);

    if (minutes < 60) {
        return `about ${minutes} min`;
    }

    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    return rest === 0 ? `about ${hours} h` : `about ${hours} h ${rest} min`;
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

/** "12s", "8m", "3h", "2d": the largest whole unit of a span. */
function compactSpan(seconds: number): string {
    if (seconds < 60) {
        return `${seconds}s`;
    }

    if (seconds < 3600) {
        return `${Math.round(seconds / 60)}m`;
    }

    if (seconds < 86_400) {
        return `${Math.round(seconds / 3600)}h`;
    }

    return `${Math.round(seconds / 86_400)}d`;
}

function secondsSince(iso: string, now: number): number {
    return Math.max(0, Math.round((now - new Date(iso).getTime()) / 1000));
}

/** "now", "2m", "3h", "5d", then the date: ages on cards and nodes. */
export function compactAge(iso: string, now: number = Date.now()): string {
    const seconds = secondsSince(iso, now);

    if (seconds < 45) {
        return 'now';
    }

    if (seconds >= 86_400 * 60) {
        return new Date(iso).toLocaleDateString(undefined, {
            month: 'short',
            day: 'numeric',
        });
    }

    return compactSpan(Math.max(60, seconds));
}

/** "now", "12s ago", "8m ago", "3h ago", "2d ago": ages in lists. */
export function shortAge(
    iso: string | null,
    now: number = Date.now(),
): string | null {
    if (iso === null) {
        return null;
    }

    const seconds = secondsSince(iso, now);

    return seconds < 5 ? 'now' : `${compactSpan(seconds)} ago`;
}

/** The time since a moment without "ago": "8m", "3h". */
export function shortDuration(
    iso: string | null,
    now: number = Date.now(),
): string | null {
    if (iso === null) {
        return null;
    }

    const seconds = secondsSince(iso, now);

    return seconds < 5 ? 'now' : compactSpan(seconds);
}

/** "3d", "5h": time until a moment in the future ("Expires in 3d"). */
export function compactUntil(iso: string, now: number = Date.now()): string {
    const seconds = Math.max(
        0,
        Math.round((new Date(iso).getTime() - now) / 1000),
    );

    return compactSpan(Math.max(60, seconds));
}

const relativeUnits: [Intl.RelativeTimeFormatUnit, number][] = [
    ['day', 86_400],
    ['hour', 3_600],
    ['minute', 60],
    ['second', 1],
];

const relativeFormat = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });

/** "5 minutes ago", "yesterday", "in 2 hours". */
export function relativeTime(iso: string, now: number = Date.now()): string {
    const seconds = Math.round((new Date(iso).getTime() - now) / 1000);

    for (const [unit, size] of relativeUnits) {
        if (Math.abs(seconds) >= size || unit === 'second') {
            return relativeFormat.format(Math.round(seconds / size), unit);
        }
    }

    return '';
}

const dayFormat = new Intl.DateTimeFormat('en-GB', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
});

/** Day headings of a timeline: "Today", "Yesterday", "Monday 3 November". */
export function dayLabel(iso: string, now: Date = new Date()): string {
    const date = new Date(iso);
    const yesterday = new Date(now);
    yesterday.setDate(now.getDate() - 1);

    if (date.toDateString() === now.toDateString()) {
        return 'Today';
    }

    return date.toDateString() === yesterday.toDateString()
        ? 'Yesterday'
        : dayFormat.format(date);
}

/** "1 server", "3 servers", "1,204 requests". */
export function plural(count: number, one: string, many = `${one}s`): string {
    return `${count.toLocaleString('en')} ${count === 1 ? one : many}`;
}

/** Two letters for an avatar: "Ada Lovelace" → "AL", "vantezzen" → "VA". */
export function initials(name: string): string {
    const parts = name
        .replace(/[^\p{L}\p{N} _.-]+/gu, '')
        .split(/[\s_.-]+/)
        .filter(Boolean);

    return (
        (parts.length > 1
            ? parts[0][0] + parts[parts.length - 1][0]
            : (parts[0] ?? '?').slice(0, 2)
        ).toUpperCase() || '?'
    );
}

export function shortSha(sha: string): string;
export function shortSha(sha: string | null): string | null;
export function shortSha(sha: string | null): string | null {
    return sha === null ? null : sha.slice(0, 7);
}

/** "web", "web and api", "web, api and worker". */
export function listNames(names: string[]): string {
    return names.length < 2
        ? (names[0] ?? '')
        : `${names.slice(0, -1).join(', ')} and ${names[names.length - 1]}`;
}

/** A percentage share: "4.2%", "0.31%", "<0.01%", "0%". */
export function formatShare(value: number | null): string {
    if (value === null) {
        return '–';
    }

    if (value === 0) {
        return '0%';
    }

    if (value < 0.01) {
        return '<0.01%';
    }

    return value < 1 ? `${value.toFixed(2)}%` : `${value.toFixed(1)}%`;
}
