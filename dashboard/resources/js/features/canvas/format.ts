/** "2m", "3h", "5d", "now": compact ages for cards and nodes (design-system §10). */
export function compactAge(iso: string, now: number = Date.now()): string {
    const seconds = Math.max(
        0,
        Math.round((now - new Date(iso).getTime()) / 1000),
    );

    if (seconds < 45) {
        return 'now';
    }

    if (seconds < 3600) {
        return `${Math.max(1, Math.round(seconds / 60))}m`;
    }

    if (seconds < 86_400) {
        return `${Math.round(seconds / 3600)}h`;
    }

    if (seconds < 86_400 * 60) {
        return `${Math.round(seconds / 86_400)}d`;
    }

    return new Date(iso).toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
    });
}

export function plural(count: number, one: string, many = `${one}s`): string {
    return `${count} ${count === 1 ? one : many}`;
}

/** "3d", "5h": time until a moment in the future ("Expires in 3d"). */
export function compactUntil(iso: string, now: number = Date.now()): string {
    const seconds = Math.max(0, (new Date(iso).getTime() - now) / 1000);

    if (seconds < 3600) {
        return `${Math.max(1, Math.round(seconds / 60))}m`;
    }

    if (seconds < 86_400) {
        return `${Math.round(seconds / 3600)}h`;
    }

    return `${Math.round(seconds / 86_400)}d`;
}
