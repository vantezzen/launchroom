import {
    echoIsConfigured,
    useConnectionStatus,
    useEcho,
} from '@laravel/echo-react';
import { useEffect, useRef } from 'react';

export type DeploymentUpdatedEvent = {
    deploymentId: string;
    environmentId: string;
    serviceId: string;
    status: App.Enums.DeploymentStatus;
    /** Only true when the status just changed (not for progress nudges). */
    statusChanged?: boolean;
};

function Subscription<T>({
    channel,
    event,
    onEvent,
    pollMs,
}: {
    channel: string;
    event: string;
    onEvent: (payload: T | null) => void;
    pollMs: number;
}) {
    const handler = useRef(onEvent);
    const status = useConnectionStatus();

    useEffect(() => {
        handler.current = onEvent;
    });

    useEcho<T>(channel, event, (payload) => handler.current(payload), [
        channel,
        event,
    ]);

    // The socket is down (Reverb unreachable, a proxy dropping
    // WebSockets): poll until it is back, so the page never freezes.
    usePoll(status !== 'connected', pollMs, handler);

    return null;
}

function usePoll<T>(
    active: boolean,
    pollMs: number,
    handler: { current: (payload: T | null) => void },
) {
    useEffect(() => {
        if (!active) {
            return;
        }

        const interval = window.setInterval(() => {
            if (document.visibilityState === 'visible') {
                handler.current(null);
            }
        }, pollMs);

        return () => window.clearInterval(interval);
    }, [active, pollMs, handler]);
}

/**
 * Subscribes to a private channel event (authorized in routes/channels.php).
 * Without Reverb, or while its socket is not connected, `onEvent(null)`
 * runs on a polling interval instead: realtime is an enhancement, never a
 * requirement.
 */
export function ChannelEvents<T>({
    channel,
    event,
    onEvent,
    pollMs = 10_000,
}: {
    channel: string;
    event: string;
    onEvent: (payload: T | null) => void;
    pollMs?: number;
}) {
    const configured = echoIsConfigured();
    const handler = useRef(onEvent);

    useEffect(() => {
        handler.current = onEvent;
    });

    usePoll(!configured, pollMs, handler);

    if (!configured) {
        return null;
    }

    return (
        <Subscription<T>
            channel={channel}
            event={event}
            onEvent={onEvent}
            pollMs={pollMs}
        />
    );
}
