import { echoIsConfigured, useEcho } from '@laravel/echo-react';
import { useEffect, useRef } from 'react';

export type DeploymentUpdatedEvent = {
    deploymentId: string;
    environmentId: string;
    serviceId: string;
    status: App.Enums.DeploymentStatus;
};

function Subscription<T>({
    channel,
    event,
    onEvent,
}: {
    channel: string;
    event: string;
    onEvent: (payload: T) => void;
}) {
    const handler = useRef(onEvent);

    useEffect(() => {
        handler.current = onEvent;
    });

    useEcho<T>(channel, event, (payload) => handler.current(payload), [
        channel,
        event,
    ]);

    return null;
}

/**
 * Subscribes to a private channel event (authorized in routes/channels.php).
 * Without Reverb, `onEvent(null)` runs on a polling interval instead:
 * realtime is an enhancement, never a requirement.
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

    useEffect(() => {
        if (configured) {
            return;
        }

        const interval = window.setInterval(() => {
            if (document.visibilityState === 'visible') {
                handler.current(null);
            }
        }, pollMs);

        return () => window.clearInterval(interval);
    }, [configured, pollMs]);

    if (!configured) {
        return null;
    }

    return (
        <Subscription<T> channel={channel} event={event} onEvent={onEvent} />
    );
}
