import { router } from '@inertiajs/react';
import { echoIsConfigured, useEcho } from '@laravel/echo-react';
import { useEffect, useRef } from 'react';

const RELOAD_DEBOUNCE_MS = 400;

function Subscription({
    environment,
    onChange,
}: {
    environment: string;
    onChange: () => void;
}) {
    useEcho(`environments.${environment}`, '.canvas.changed', onChange, [
        environment,
    ]);

    return null;
}

type Token = { cancel: VoidFunction };

/** Background refreshes in flight, and user visits in flight. */
const inFlight = new Set<Token>();
let userVisits = 0;
let waiting: { component: string | null; run: VoidFunction } | null = null;
let component: string | null = null;
let listening = false;

function isUserVisit(visit: { async: boolean; prefetch: boolean }) {
    return !visit.async && !visit.prefetch;
}

/**
 * A user visit wins: it cancels background refreshes that are still
 * loading (their answer could otherwise put back the old URL or old
 * filters), and refreshes wait until it is done.
 */
function listen() {
    if (listening) {
        return;
    }

    listening = true;
    router.on('navigate', (event) => {
        component = event.detail.page.component;
    });
    router.on('start', (event) => {
        if (!isUserVisit(event.detail.visit)) {
            return;
        }

        userVisits++;
        inFlight.forEach((token) => token.cancel());
        inFlight.clear();
    });
    router.on('finish', (event) => {
        if (!isUserVisit(event.detail.visit)) {
            return;
        }

        userVisits = Math.max(0, userVisits - 1);

        if (userVisits === 0 && waiting) {
            const next = waiting;
            waiting = null;

            // Not after the user left for another page.
            if (next.component === component) {
                next.run();
            }
        }
    });
}

/**
 * A background refresh of `only` (docs/10 §3): `async`, so it never
 * cancels the click the user just made. While a user visit loads it waits;
 * when a user visit cancels it, `retry` runs it again later.
 */
export function liveReload(only: string[], retry?: VoidFunction) {
    listen();

    if (userVisits > 0) {
        waiting = { component, run: () => liveReload(only, retry) };

        return;
    }

    let token: Token | null = null;
    router.reload({
        only,
        async: true,
        showProgress: false,
        onCancelToken: (cancelToken) => {
            token = cancelToken;
            inFlight.add(cancelToken);
        },
        onCancel: () => retry?.(),
        onFinish: () => {
            if (token) {
                inFlight.delete(token);
            }
        },
    });
}

/**
 * Reloads the given props when anything on these environments' canvases
 * changes (private channel per environment, authorized by policy). Without
 * Reverb it falls back to polling, because realtime is an enhancement.
 */
export function EnvironmentLiveUpdates({
    environments,
    only,
    pollMs = 30_000,
}: {
    environments: string[];
    only: string[];
    pollMs?: number;
}) {
    const timer = useRef<number | null>(null);
    const configured = echoIsConfigured();
    const key = only.join(',');

    const mounted = useRef(true);

    const reload = () => {
        if (!mounted.current) {
            return;
        }

        // Prefetched service panels (canvas hover) describe the old state.
        router.flushByCacheTags('service-panel');

        if (timer.current !== null) {
            window.clearTimeout(timer.current);
        }

        timer.current = window.setTimeout(() => {
            timer.current = null;
            liveReload(key.split(','), reload);
        }, RELOAD_DEBOUNCE_MS);
    };

    useEffect(() => {
        if (configured) {
            return;
        }

        const interval = window.setInterval(() => {
            if (document.visibilityState === 'visible') {
                liveReload(key.split(','));
            }
        }, pollMs);

        return () => window.clearInterval(interval);
    }, [configured, key, pollMs]);

    useEffect(() => {
        mounted.current = true;

        return () => {
            mounted.current = false;
        };
    }, []);

    useEffect(
        () => () => {
            if (timer.current !== null) {
                window.clearTimeout(timer.current);
            }
        },
        [],
    );

    if (!configured) {
        return null;
    }

    return (
        <>
            {environments.map((environment) => (
                <Subscription
                    key={environment}
                    environment={environment}
                    onChange={reload}
                />
            ))}
        </>
    );
}
