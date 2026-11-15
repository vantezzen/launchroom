import { useEffect } from 'react';
import { liveReload } from '@/lib/live-reload';

type VisibilitySource = Pick<
    Document,
    'visibilityState' | 'addEventListener' | 'removeEventListener'
>;

/**
 * Calls `tick` every `everyMs` while the tab is visible, and once right
 * away when it becomes visible again. Returns a stop function.
 */
export function schedulePoll(
    tick: () => void,
    everyMs: number,
    doc: VisibilitySource = document,
): () => void {
    const timer = window.setInterval(() => {
        if (doc.visibilityState === 'visible') {
            tick();
        }
    }, everyMs);
    const onVisibility = () => {
        if (doc.visibilityState === 'visible') {
            tick();
        }
    };

    doc.addEventListener('visibilitychange', onVisibility);

    return () => {
        window.clearInterval(timer);
        doc.removeEventListener('visibilitychange', onVisibility);
    };
}

/**
 * Keeps the page props `only` current while `while` holds, as background
 * reloads (`liveReload`, docs/10 §3) that never cancel the user's click.
 * For state that changes without a realtime event (a server joining, a
 * move or certificate in progress).
 */
export function useLiveProps(
    only: string[],
    { while: active = true, everyMs }: { while?: boolean; everyMs: number },
): void {
    const key = only.join(',');

    useEffect(() => {
        if (!active) {
            return;
        }

        return schedulePoll(() => liveReload(key.split(',')), everyMs);
    }, [active, key, everyMs]);
}
