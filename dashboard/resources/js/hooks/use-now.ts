import { useCallback, useSyncExternalStore } from 'react';

/**
 * One clock for the whole page: a single one-second interval shared by every
 * running timer, started by the first subscriber and paused while the tab is
 * hidden (it ticks once on return, so timers jump to the right value).
 */
const listeners = new Set<() => void>();
let current = Date.now();
let timer: number | null = null;

function tick(): void {
    current = Date.now();
    listeners.forEach((listener) => listener());
}

function hidden(): boolean {
    return (
        typeof document !== 'undefined' && document.visibilityState === 'hidden'
    );
}

function start(): void {
    if (timer !== null || listeners.size === 0 || hidden()) {
        return;
    }

    tick();
    timer = window.setInterval(tick, 1000);
}

function stop(): void {
    if (timer !== null) {
        window.clearInterval(timer);
        timer = null;
    }
}

function onVisibility(): void {
    if (hidden()) {
        stop();
    } else {
        start();
    }
}

/** Subscribes to the shared clock; exported for tests. */
export function subscribe(listener: () => void): () => void {
    if (listeners.size === 0 && typeof document !== 'undefined') {
        document.addEventListener('visibilitychange', onVisibility);
    }

    listeners.add(listener);
    start();

    return () => {
        listeners.delete(listener);

        if (listeners.size === 0) {
            stop();
            document.removeEventListener('visibilitychange', onVisibility);
        }
    };
}

const idle = () => () => {};

/**
 * The current time in milliseconds, re-rendering every `every` ms while
 * `active` (default one second). Inactive callers read the clock without
 * subscribing, so a finished timer stays fixed and costs nothing.
 */
export function useNow(active: boolean = true, every: number = 1000): number {
    const snapshot = useCallback(
        () => Math.floor(current / every) * every,
        [every],
    );

    return useSyncExternalStore(active ? subscribe : idle, snapshot, snapshot);
}

/** For tests: the number of subscribed timers and whether the interval runs. */
export function tickerState(): { listeners: number; running: boolean } {
    return { listeners: listeners.size, running: timer !== null };
}
