import { useCallback, useState, useSyncExternalStore } from 'react';
import {
    DEFAULT_RANGE,
    RANGE_WORDS,
    readScope,
    replaceQuery,
    SCOPE_EVENT,
    snapRange,
} from '@/lib/observe-scope';
import type { ObserveRange, ObserveWindow } from '@/lib/observe-scope';

function subscribe(onChange: () => void): () => void {
    window.addEventListener(SCOPE_EVENT, onChange);
    window.addEventListener('popstate', onChange);

    return () => {
        window.removeEventListener(SCOPE_EVENT, onChange);
        window.removeEventListener('popstate', onChange);
    };
}

/**
 * The query string as it is now, updated when a page changes the scope:
 * for links that carry the scope to another page (the project tabs).
 */
export function useLocationSearch(): string {
    return useSyncExternalStore(
        subscribe,
        () => window.location.search,
        () => '',
    );
}

export type ObserveScopeState<T extends ObserveRange> = {
    range: T;
    setRange: (range: T) => void;
    service: string | null;
    setService: (service: string | null) => void;
    /** An absolute window from a drill-down (`?from=&to=`), else null. */
    window: ObserveWindow | null;
    setWindow: (window: ObserveWindow | null) => void;
    /**
     * Set when the URL asked for a range this page doesn't have: "There's
     * no view of the last hour here, so this shows the last 24 hours."
     */
    snapped: string | null;
};

/**
 * The page's part of the observe scope (lib/observe-scope.ts): reads
 * `?range=`, `?service=`, `?from=`/`?to=` once, snaps the range to what the
 * page supports and, with `sync`, writes changes back to the address bar
 * (replaceState, so the back button isn't flooded). Inside the service
 * panel pass `sync: false` for the service: the panel is the service.
 */
export function useObserveScope<T extends ObserveRange>(
    supported: readonly T[],
    { sync = true }: { sync?: boolean } = {},
): ObserveScopeState<T> {
    const [initial] = useState(() =>
        readScope(typeof window === 'undefined' ? '' : window.location.search),
    );
    const [range, setRangeState] = useState<T>(() =>
        snapRange(initial.range, supported),
    );
    const [service, setServiceState] = useState<string | null>(initial.service);
    const [window_, setWindowState] = useState<ObserveWindow | null>(
        initial.window,
    );
    const snapped =
        initial.range !== null && initial.range !== range
            ? `There's no view of the ${RANGE_WORDS[initial.range]} here, so this shows the ${RANGE_WORDS[range]}.`
            : null;

    const setRange = useCallback(
        (next: T) => {
            setRangeState(next);
            setWindowState(null);

            if (sync) {
                replaceQuery({
                    range: next === DEFAULT_RANGE ? null : next,
                    from: null,
                    to: null,
                });
            }
        },
        [sync],
    );
    const setService = useCallback(
        (next: string | null) => {
            setServiceState(next);

            if (sync) {
                replaceQuery({ service: next });
            }
        },
        [sync],
    );
    const setWindow = useCallback(
        (next: ObserveWindow | null) => {
            setWindowState(next);

            if (sync) {
                replaceQuery({
                    from: next ? String(Math.floor(next.from / 1000)) : null,
                    to: next ? String(Math.ceil(next.to / 1000)) : null,
                });
            }
        },
        [sync],
    );

    return {
        range,
        setRange,
        service,
        setService,
        window: window_,
        setWindow,
        snapped,
    };
}
