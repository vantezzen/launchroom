import { useCallback, useEffect, useRef, useState } from 'react';
import { api } from '@/features/variables/api';
import generations from '@/routes/projects/services/dockerfile-generations';

export type Generation = App.Data.Dockerfiles.DockerfileGenerationData;
type Route = [project: string, environment: string, service: string];

const POLL_MS = 2000;

function args(route: Route) {
    return { project: route[0], environment: route[1], service: route[2] };
}

function withGeneration(route: Route, generation: string) {
    return { ...args(route), generation };
}

/**
 * One generation in the sheet: start, live updates while it runs (polls,
 * docs/20 §7), use, pull request, cancel. Errors carry the server's
 * user-facing message.
 */
export function useGeneration(route: Route, initial: Generation | null) {
    const [generation, setGeneration] = useState<Generation | null>(initial);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState<string | null>(null);
    const id = generation?.id;
    const running =
        generation !== null &&
        !['verified', 'failed', 'cancelled'].includes(generation.status);
    const routeKey = route.join('/');
    const routeRef = useRef(route);
    routeRef.current = route;

    useEffect(() => {
        if (!running || !id) {
            return;
        }

        const controller = new AbortController();
        const timer = window.setInterval(() => {
            if (document.visibilityState !== 'visible') {
                return;
            }

            api<Generation>(
                'get',
                generations.show(withGeneration(routeRef.current, id)).url,
                undefined,
                controller.signal,
            )
                .then(setGeneration)
                .catch(() => undefined);
        }, POLL_MS);

        return () => {
            window.clearInterval(timer);
            controller.abort();
        };
    }, [running, id, routeKey]);

    const run = useCallback(
        async (label: string, request: () => Promise<Generation>) => {
            setBusy(label);
            setError(null);

            try {
                const next = await request();
                setGeneration(next);

                return next;
            } catch (failure) {
                setError(
                    failure instanceof Error
                        ? failure.message
                        : 'The request failed. Try again in a moment.',
                );

                return null;
            } finally {
                setBusy(null);
            }
        },
        [],
    );

    return {
        generation,
        error,
        busy,
        running,
        reset: () => {
            setGeneration(null);
            setError(null);
        },
        start: (trigger: App.Enums.Dockerfiles.DockerfileGenerationTrigger) =>
            run('start', () =>
                api<Generation>('post', generations.store(args(route)).url, {
                    trigger,
                }),
            ),
        use: () =>
            id
                ? run('use', () =>
                      api<Generation>(
                          'post',
                          generations.use(withGeneration(route, id)).url,
                      ),
                  )
                : Promise.resolve(null),
        pullRequest: () =>
            id
                ? run('pullRequest', () =>
                      api<Generation>(
                          'post',
                          generations.pullRequest(withGeneration(route, id))
                              .url,
                      ),
                  )
                : Promise.resolve(null),
        cancel: () =>
            id
                ? run('cancel', () =>
                      api<Generation>(
                          'delete',
                          generations.destroy(withGeneration(route, id)).url,
                      ),
                  )
                : Promise.resolve(null),
    };
}

export function formatCost(cost: number | null): string | null {
    if (cost === null) {
        return null;
    }

    return cost < 0.01 ? 'under $0.01' : `$${cost.toFixed(2)}`;
}

export function formatMs(ms: number | null): string | null {
    if (ms === null) {
        return null;
    }

    const seconds = ms / 1000;

    return seconds < 60
        ? `${seconds < 10 ? seconds.toFixed(1) : Math.round(seconds)}s`
        : `${Math.floor(seconds / 60)}m ${Math.round(seconds % 60)}s`;
}
