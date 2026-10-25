import { http, router } from '@inertiajs/react';
import type {
    HttpCancelledError,
    HttpNetworkError,
    HttpResponse,
    HttpResponseError,
} from '@inertiajs/core';

/**
 * The dashboard's connection to its own server (docs/08 §4).
 *
 * While launchroom restarts (an update, a move, a crash) the edge answers
 * 502/503/504 or nothing at all. Inertia would open its error dialog for
 * every failed poll and partial reload, stacking one modal per request, and
 * after an update it ignores the 409 "new version" answer to background
 * reloads, so the page polls forever with stale assets. This module takes
 * over globally:
 *
 * - unreachable (network error, 502/503/504): one calm "Reconnecting"
 *   banner, background reloads pause, a probe of `/up/version` retries with
 *   backoff; when the server answers, a new build reloads the page fully,
 *   the same build refreshes the page's props;
 * - 409 with a new asset version (any visit, async or not): full reload;
 * - 419 (session expired): full reload, the server redirects to sign-in;
 * - any other non-Inertia error: at most one error dialog at a time.
 */

export type ResponseKind =
    | 'ok'
    | 'unreachable'
    | 'version-change'
    | 'expired'
    | 'error';

const UNREACHABLE = new Set([0, 502, 503, 504]);

/** What a response means for the connection (pure, for tests). */
export function classifyResponse(response: {
    status: number;
    headers?: Record<string, string | undefined>;
}): ResponseKind {
    const headers = response.headers ?? {};

    if (UNREACHABLE.has(response.status)) {
        return 'unreachable';
    }

    if (response.status === 409 && headers['x-inertia-location']) {
        return 'version-change';
    }

    if (response.status === 419) {
        return 'expired';
    }

    return response.status >= 400 ? 'error' : 'ok';
}

/** The same for a failed request of Inertia's HTTP client. */
export function classifyError(
    error: HttpResponseError | HttpNetworkError | HttpCancelledError,
): ResponseKind | 'cancelled' {
    switch (error.code) {
        case 'ERR_NETWORK':
            return 'unreachable';
        case 'ERR_CANCELLED':
            return 'cancelled';
        default:
            return 'response' in error
                ? classifyResponse(error.response)
                : 'error';
    }
}

/** Retry delay after `attempt` failed probes: 1 s, 2 s, 4 s, … at most 15 s. */
export function backoffMs(attempt: number): number {
    return Math.min(15_000, 1000 * 2 ** Math.max(0, attempt));
}

/** Whether the probe's answer comes from a different build than the page. */
export function isNewBuild(
    pageVersion: string | null,
    serverVersion: string | null,
): boolean {
    return (pageVersion ?? '') !== (serverVersion ?? '');
}

export type ConnectionState = 'online' | 'reconnecting';

type Probe = () => Promise<{ reachable: boolean; version: string | null }>;

const listeners = new Set<() => void>();
let state: ConnectionState = 'online';
let attempt = 0;
let retry: number | null = null;
let pageVersion: string | null = null;
let installed = false;

function setState(next: ConnectionState): void {
    if (state !== next) {
        state = next;
        listeners.forEach((listener) => listener());
    }
}

export function connectionState(): ConnectionState {
    return state;
}

export function subscribeConnection(listener: () => void): () => void {
    listeners.add(listener);

    return () => listeners.delete(listener);
}

const versionUrl = '/up/version';

/** A probe without an answer after this long counts as unreachable. */
const PROBE_TIMEOUT_MS = 5000;

/** A visit still waiting after this long makes the page probe the server. */
const SLOW_VISIT_MS = 6000;

/** GET /up/version: tiny, sessionless, never cached. */
export const probeServer: Probe = async () => {
    try {
        const response = await fetch(versionUrl, {
            cache: 'no-store',
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            // A server that is gone can leave a request hanging.
            signal: AbortSignal.timeout(PROBE_TIMEOUT_MS),
        });

        if (!response.ok) {
            return { reachable: false, version: null };
        }

        const body = (await response.json()) as { version?: string | null };

        return { reachable: true, version: body.version ?? null };
    } catch {
        return { reachable: false, version: null };
    }
};

function hardReload(url?: URL): void {
    if (url && url.href !== window.location.href) {
        window.location.href = url.href;
    } else {
        window.location.reload();
    }
}

/**
 * Probe once now: reload on a new build, otherwise mark online and refresh
 * the page's props. Returns whether the server answered.
 */
export async function checkConnection(
    probe: Probe = probeServer,
): Promise<boolean> {
    const result = await probe();

    if (!result.reachable) {
        attempt += 1;
        markUnreachable();

        return false;
    }

    if (isNewBuild(pageVersion, result.version)) {
        hardReload();

        return true;
    }

    const wasReconnecting = state === 'reconnecting';
    attempt = 0;

    if (retry !== null) {
        window.clearTimeout(retry);
        retry = null;
    }

    setState('online');

    if (wasReconnecting) {
        // Data may have changed while away; refresh quietly.
        router.reload();
    }

    return true;
}

function scheduleProbe(): void {
    if (retry !== null) {
        return;
    }

    retry = window.setTimeout(() => {
        retry = null;
        void checkConnection();
    }, backoffMs(attempt));
}

/** The server didn't answer: show the banner and keep probing. */
export function markUnreachable(): void {
    setState('reconnecting');
    scheduleProbe();
}

function errorDialogOpen(): boolean {
    return document.getElementById('inertia-error-dialog') !== null;
}

function onResponse(response: HttpResponse): void {
    if (state === 'reconnecting' && !UNREACHABLE.has(response.status)) {
        // Something answered; confirm through the probe (it checks the build).
        void checkConnection();
    }
}

/**
 * Install the global handlers once, with the first page's asset version.
 * Every Inertia visit, poll and partial reload, and every JSON request made
 * through Inertia's HTTP client, passes through here.
 */
export function initializeConnection(version: string | null): void {
    pageVersion = version;

    if (installed || typeof window === 'undefined') {
        return;
    }

    installed = true;

    // The asset version follows client-side navigations.
    router.on('navigate', (event) => {
        pageVersion = event.detail.page.version ?? null;
    });

    // Background reloads (polls, realtime fallbacks) wait while the server
    // is away; the probe refreshes the page once it is back. Visits the
    // person starts still go through.
    router.on('before', (event) => {
        const visit = event.detail.visit;

        if (state === 'reconnecting' && visit.async && visit.method === 'get') {
            event.preventDefault();
        }
    });

    // A request can also hang (the server vanished mid-connection): when a
    // visit takes long, ask the probe whether launchroom is still there.
    let slow: number | null = null;
    router.on('start', () => {
        if (slow !== null) {
            window.clearTimeout(slow);
        }
        slow = window.setTimeout(() => {
            slow = null;
            if (state === 'online') {
                void checkConnection();
            }
        }, SLOW_VISIT_MS);
    });
    router.on('finish', () => {
        if (slow !== null) {
            window.clearTimeout(slow);
            slow = null;
        }
    });

    router.on('httpException', (event) => {
        const kind = classifyResponse(event.detail.response);

        if (kind === 'unreachable') {
            event.preventDefault();
            markUnreachable();

            return;
        }

        if (kind === 'expired') {
            event.preventDefault();
            hardReload();

            return;
        }

        // A non-Inertia error page: Inertia shows it in a dialog; never two.
        if (errorDialogOpen()) {
            event.preventDefault();
        }
    });

    router.on('networkError', (event) => {
        event.preventDefault();
        markUnreachable();
    });

    // 409 + X-Inertia-Location: Inertia skips the reload for async visits
    // (polls, partial reloads), which left pages stuck after an update.
    router.on('location', (event) => {
        if (event.detail.versionChange) {
            event.preventDefault();
            hardReload(event.detail.url);
        }
    });

    // JSON requests through Inertia's client (service panel tabs, logs,
    // staged changes) report the connection too.
    http.onResponse((response) => {
        onResponse(response);

        return response;
    });
    http.onError((error) => {
        if (classifyError(error) === 'unreachable') {
            markUnreachable();
        }
    });
}
