import {
    echoIsConfigured,
    useConnectionStatus,
    useEcho,
} from '@laravel/echo-react';
import { useEffect, useRef } from 'react';
import { schedulePoll } from '@/hooks/use-live-props';
import type { BroadcastName, BroadcastPayload } from '@/lib/realtime';
import { channelName, echoEvent } from '@/lib/realtime';

function Subscription<E extends BroadcastName>({
    channel,
    event,
    onEvent,
    pollMs,
}: {
    channel: string;
    event: E;
    onEvent: (payload: BroadcastPayload<E> | null) => void;
    pollMs: number;
}) {
    const handler = useRef(onEvent);
    const status = useConnectionStatus();

    useEffect(() => {
        handler.current = onEvent;
    });

    useEcho<BroadcastPayload<E>>(
        channel,
        echoEvent(event),
        (payload) => handler.current(payload),
        [channel, event],
    );

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

        return schedulePoll(() => handler.current(null), pollMs);
    }, [active, pollMs, handler]);
}

/**
 * Subscribes to a private channel event (authorized in routes/channels.php).
 * Without Reverb, or while its socket is not connected, `onEvent(null)`
 * runs on a polling interval instead: realtime is an enhancement, never a
 * requirement.
 */
export function ChannelEvents<E extends BroadcastName>({
    channel,
    event,
    onEvent,
    pollMs = 10_000,
}: {
    channel: string;
    event: E;
    onEvent: (payload: BroadcastPayload<E> | null) => void;
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
        <Subscription<E>
            channel={channel}
            event={event}
            onEvent={onEvent}
            pollMs={pollMs}
        />
    );
}

/**
 * `onChange` when something of `topic` on this service changed state (a
 * session or remote access ended, a backup, restore or import moved on).
 */
export function ServiceActivityEvents({
    environment,
    service,
    topic,
    onChange,
    pollMs = 15_000,
}: {
    environment: string;
    service: string;
    topic: App.Broadcasting.ServiceActivityTopic;
    onChange: () => void;
    pollMs?: number;
}) {
    return (
        <ChannelEvents
            channel={channelName('environments', environment)}
            event="service.activity"
            pollMs={pollMs}
            onEvent={(event) => {
                if (
                    event === null ||
                    (event.serviceId === service && event.topic === topic)
                ) {
                    onChange();
                }
            }}
        />
    );
}
