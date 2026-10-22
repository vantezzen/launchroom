import { api, RequestFailed } from '@/features/variables/api';
import sessions from '@/routes/sessions';
import serviceSessions from '@/routes/sessions/services';

export { api, RequestFailed };

export type SessionKind = App.Enums.Sessions.InteractiveSessionKind;
export type ServiceRef = {
    project: string;
    environment: string;
    service: string;
};

export function tabUrl(ref: ServiceRef, kind: SessionKind): string {
    return serviceSessions.show({ ...ref, kind }).url;
}

export function openSession(
    ref: ServiceRef,
    kind: SessionKind,
    body: {
        workload?: string | null;
        shell?: string | null;
        cols: number;
        rows: number;
    },
): Promise<App.Data.Sessions.OpenedSessionData> {
    return api('post', serviceSessions.open({ ...ref, kind }).url, body);
}

export function sendCommand(
    session: string,
    command: Record<string, unknown>,
): Promise<{ op: string }> {
    return api('post', sessions.commands(session).url, command);
}

export function endSession(session: string): Promise<null> {
    return api('post', sessions.end(session).url, {});
}

export function fetchRecording(session: string): Promise<{ lines: string[] }> {
    return api('post', sessions.recording(session).url, {});
}

function xsrfToken(): string | null {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : null;
}

/**
 * Ends a session while the page goes away (tab closed, navigation): a
 * keepalive fetch survives unload and, unlike sendBeacon, carries the XSRF header.
 */
export function endOnUnload(session: string): void {
    const token = xsrfToken();

    try {
        void fetch(sessions.end(session).url, {
            method: 'POST',
            keepalive: true,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(token ? { 'X-XSRF-TOKEN': token } : {}),
            },
        });
    } catch {
        // The server ends it after 45 s without a frame anyway.
    }
}

/** The channel authorization for this socket, with the single-use ticket. */
export function authorizeChannel(
    session: string,
    socketId: string,
    ticket: string,
): Promise<{ auth: string }> {
    return api('post', sessions.authorize(session).url, {
        socket_id: socketId,
        ticket,
    });
}
