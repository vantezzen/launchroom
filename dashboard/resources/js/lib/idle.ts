import { router } from '@inertiajs/react';

/**
 * Runs `task` when the browser has nothing else to do (or after `timeout`
 * at the latest), so warm-ups never compete with what the user is waiting
 * for. Returns a cancel function.
 */
export function whenIdle(task: () => void, timeout = 2_000): () => void {
    if (typeof window === 'undefined') {
        return () => undefined;
    }

    if ('requestIdleCallback' in window) {
        const handle = window.requestIdleCallback(task, { timeout });

        return () => window.cancelIdleCallback(handle);
    }

    const handle = globalThis.setTimeout(task, 200);

    return () => globalThis.clearTimeout(handle);
}

const warmed = new Set<string>();

/**
 * Loads the code of pages the user is likely to open next (docs/10 §3):
 * Inertia prefetches a page's props but not its component chunk, so the
 * first visit of each page type would otherwise wait for one more
 * download after the response. Chunks are immutable and cached.
 */
export function warmPages(components: string[]): () => void {
    const todo = components.filter((name) => !warmed.has(name));

    if (todo.length === 0) {
        return () => undefined;
    }

    return whenIdle(() => {
        for (const name of todo) {
            if (warmed.has(name)) {
                continue;
            }

            warmed.add(name);
            router.resolveComponent(name).catch(() => warmed.delete(name));
        }
    });
}
