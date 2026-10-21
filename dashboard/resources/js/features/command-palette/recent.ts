export type RecentItem = {
    kind: string;
    id: string;
    label: string;
    detail: string | null;
    url: string;
};

const KEY = 'launchroom.palette.recent';
const LIMIT = 6;

/**
 * Recently opened palette items, per browser (a convenience; it may be
 * empty in private windows).
 */
export function readRecent(): RecentItem[] {
    try {
        const parsed: unknown = JSON.parse(
            window.localStorage.getItem(KEY) ?? '[]',
        );

        return Array.isArray(parsed)
            ? (parsed as RecentItem[]).slice(0, LIMIT)
            : [];
    } catch {
        return [];
    }
}

export function addRecent(item: RecentItem): void {
    try {
        const next = [
            item,
            ...readRecent().filter(
                (entry) => !(entry.kind === item.kind && entry.id === item.id),
            ),
        ].slice(0, LIMIT);
        window.localStorage.setItem(KEY, JSON.stringify(next));
    } catch {
        // Storage unavailable: recent items are optional.
    }
}
