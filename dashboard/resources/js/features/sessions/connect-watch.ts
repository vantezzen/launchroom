import { endSession, fetchSession } from './api';

/** How long a session may stay "Connecting…" before it is given up. */
export const CONNECT_TIMEOUT_MS = 25_000;
const POLL_MS = 2_000;

type SessionItem = App.Data.Sessions.SessionHistoryItemData;

export type ConnectWatchDeps = {
    fetch: (id: string, signal: AbortSignal) => Promise<SessionItem>;
    end: (id: string) => Promise<unknown>;
    setTimer: (callback: () => void, ms: number) => number;
    clearTimer: (timer: number) => void;
};

const browserDeps: ConnectWatchDeps = {
    fetch: (id, signal) => fetchSession(id, signal),
    end: (id) => endSession(id),
    setTimer: (callback, ms) => window.setTimeout(callback, ms),
    clearTimer: (timer) => window.clearTimeout(timer),
};

/** The sentence for a session that ended before it was usable. */
export function failureOf(item: SessionItem): string {
    return item.failure ?? `${item.endReason ?? 'The session ended'}.`;
}

/**
 * Watches a session while it connects (docs/03 §11.2): the server may fail
 * to start it (no shell, a refusal, launchroomd offline) without ever
 * joining the channel, so the dashboard is asked every two seconds, and
 * after CONNECT_TIMEOUT_MS the session is ended. `onFailed` gets one
 * sentence for the tab. Returns a function that stops watching (call it
 * once the session is live or over).
 */
export function watchConnect(
    sessionId: string,
    serverName: string,
    onFailed: (message: string) => void,
    deps: ConnectWatchDeps = browserDeps,
    timeoutMs: number = CONNECT_TIMEOUT_MS,
): () => void {
    const controller = new AbortController();
    let done = false;
    let poll: number | null = null;

    const fail = (message: string) => {
        if (done) {
            return;
        }

        stop();
        onFailed(message);
    };

    const check = async () => {
        poll = null;

        try {
            const item = await deps.fetch(sessionId, controller.signal);

            if (item.status === 'ended') {
                fail(failureOf(item));

                return;
            }
        } catch {
            // Asking failed: keep waiting, the timeout still applies.
        }

        if (!done) {
            poll = deps.setTimer(() => void check(), POLL_MS);
        }
    };

    const timeout = deps.setTimer(() => {
        void deps.end(sessionId).catch(() => undefined);
        fail(
            `${serverName} didn’t answer within ${Math.round(timeoutMs / 1000)} seconds. The server may be offline, busy or unable to reach the live connection.`,
        );
    }, timeoutMs);

    poll = deps.setTimer(() => void check(), POLL_MS);

    function stop() {
        done = true;
        controller.abort();
        deps.clearTimer(timeout);

        if (poll !== null) {
            deps.clearTimer(poll);
        }
    }

    return stop;
}
