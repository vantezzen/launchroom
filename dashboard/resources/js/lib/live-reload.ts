import { router } from '@inertiajs/react';

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
