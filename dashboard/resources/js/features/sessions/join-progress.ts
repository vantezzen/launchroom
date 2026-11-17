/**
 * How far a session's live connection got (docs/03 §11.2): the browser's
 * socket, its channel authorization and subscription, then the server's
 * `ready` frame. When a session fails before it is usable, this says
 * which step didn't finish.
 */
export type JoinProgress = {
    socket: 'connecting' | 'connected' | 'unreachable';
    /** The browser's channel authorization (POST /sessions/<id>/authorize). */
    auth: 'waiting' | 'pending' | 'ok' | 'failed' | 'timeout';
    /** The HTTP status of a failed authorization, if there was one. */
    authStatus: number | null;
    /** The live connection confirmed the browser's subscription. */
    subscribed: boolean;
    /** The server joined the channel (its `ready` frame arrived). */
    serverJoined: boolean;
};

/** How long the browser's channel authorization may take. */
export const AUTHORIZE_TIMEOUT_MS = 10_000;
/** How long the browser may take to join the channel, from the start. */
export const JOIN_TIMEOUT_MS = 15_000;

export function initialProgress(): JoinProgress {
    return {
        socket: 'connecting',
        auth: 'waiting',
        authStatus: null,
        subscribed: false,
        serverJoined: false,
    };
}

/**
 * The sentence for a session that never became usable, naming the step
 * that didn't finish. `waitedSeconds` is how long the tab waited.
 */
export function joinFailure(
    progress: JoinProgress,
    serverName: string,
    waitedSeconds: number,
): string {
    const browser = 'Your browser couldn’t join the live connection.';

    if (progress.serverJoined) {
        return `${serverName} joined the live connection but didn’t start the session within ${waitedSeconds} seconds.`;
    }

    if (progress.auth === 'timeout') {
        return `${browser} The dashboard didn’t authorize it within ${Math.round(AUTHORIZE_TIMEOUT_MS / 1000)} seconds.`;
    }

    if (progress.auth === 'failed') {
        return progress.authStatus === null
            ? `${browser} The dashboard refused it.`
            : `${browser} The dashboard answered with HTTP ${progress.authStatus}.`;
    }

    if (progress.auth === 'waiting') {
        return progress.socket === 'connected'
            ? `${browser} It never asked the dashboard for access.`
            : 'Your browser couldn’t reach the live connection.';
    }

    if (progress.auth === 'pending') {
        return `${browser} The dashboard didn’t answer its request for access.`;
    }

    if (!progress.subscribed) {
        return `${browser} The dashboard allowed it, but the live connection didn’t confirm in time.`;
    }

    return `${serverName} didn’t connect to the live connection within ${waitedSeconds} seconds. The server may be offline, busy or unable to reach the dashboard.`;
}
