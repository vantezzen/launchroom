import { useEffect, useSyncExternalStore } from 'react';

/**
 * Unsaved edits per settings page. The sidebar marks pages that have some
 * (pages stay mounted once visited, so switching keeps the edits), and the
 * panel hides its StagedBar while the open page shows its UnsavedBar (one
 * floating bar at a time).
 */
const dirty = new Map<string, number>();
let visible = 0;
const listeners = new Set<() => void>();
let snapshot: ReadonlySet<string> = new Set();

function emit(): void {
    snapshot = new Set(
        [...dirty].filter(([, count]) => count > 0).map(([key]) => key),
    );
    listeners.forEach((listener) => listener());
}

function subscribe(listener: () => void): () => void {
    listeners.add(listener);

    return () => listeners.delete(listener);
}

/** Marks `section` dirty (and, when `shown`, its bar visible) while mounted. */
export function useReportDirty(
    section: string,
    isDirty: boolean,
    shown: boolean,
): void {
    useEffect(() => {
        if (!isDirty) {
            return;
        }

        dirty.set(section, (dirty.get(section) ?? 0) + 1);
        emit();

        return () => {
            dirty.set(section, (dirty.get(section) ?? 1) - 1);
            emit();
        };
    }, [section, isDirty]);

    useEffect(() => {
        if (!isDirty || !shown) {
            return;
        }

        visible++;
        emit();

        return () => {
            visible--;
            emit();
        };
    }, [isDirty, shown]);
}

/** An UnsavedBar is showing in the panel. */
export function useAnyDirty(): boolean {
    return useSyncExternalStore(
        subscribe,
        () => visible > 0,
        () => false,
    );
}

/** Pages with unsaved edits (for the sidebar's dots). */
export function useDirtySections(): ReadonlySet<string> {
    return useSyncExternalStore(
        subscribe,
        () => snapshot,
        () => snapshot,
    );
}
