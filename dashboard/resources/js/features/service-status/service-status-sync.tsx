import { echoIsConfigured, useConnectionStatus } from '@laravel/echo-react';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useRef } from 'react';
import { ChannelEvents } from '@/components/channel-events';
import { channelName } from '@/lib/realtime';
import type { LiveStatus } from './live-status';
import {
    liveStatusQuery,
    refreshLiveStatus,
    seedLiveStatus,
    useLiveStatus,
} from './store';

const NUDGE_DEBOUNCE_MS = 300;
/** Without a socket: how often the store asks instead. */
const POLL_MS = 15_000;

/**
 * Keeps the live-status store of the open environment current (D108,
 * docs/08 §23): seeds it from the `liveStatus` prop, refetches it on
 * `service.status` and `canvas.changed` (debounced, so a burst is one
 * request), when the socket reconnects (events sent meanwhile are lost),
 * when the tab becomes visible again (TanStack's focus refetch) and, while
 * the socket is down, every 15 s. The project top bar mounts it once.
 */
export function ServiceStatusSync({
    project,
    environment,
    seed,
}: {
    project: string;
    environment: string;
    seed: LiveStatus | null | undefined;
}) {
    const timer = useRef<number | null>(null);
    const seeded = useLiveStatus(environment) !== undefined;

    useEffect(() => {
        if (seed && seed.environmentId === environment) {
            seedLiveStatus(seed);
        }
    }, [seed, environment]);

    // The store's one fetching observer; the deferred prop brings the first
    // snapshot, so it waits for that instead of asking twice.
    useQuery({
        ...liveStatusQuery(project, environment),
        enabled: seeded,
        refetchOnMount: false,
        refetchOnWindowFocus: true,
    });

    useEffect(
        () => () => {
            if (timer.current !== null) {
                window.clearTimeout(timer.current);
            }
        },
        [],
    );

    const nudge = () => {
        if (timer.current !== null) {
            window.clearTimeout(timer.current);
        }

        timer.current = window.setTimeout(() => {
            timer.current = null;
            void refreshLiveStatus(environment);
        }, NUDGE_DEBOUNCE_MS);
    };

    const channel = channelName('environments', environment);

    return (
        <>
            <ChannelEvents
                channel={channel}
                event="service.status"
                pollMs={POLL_MS}
                onEvent={nudge}
            />
            {/* Polls only through the subscription above. */}
            <ChannelEvents
                channel={channel}
                event="canvas.changed"
                pollMs={3_600_000}
                onEvent={nudge}
            />
            {echoIsConfigured() && <RefreshOnReconnect onReconnect={nudge} />}
        </>
    );
}

/** Events sent while the socket was down are gone: one refetch once it is back. */
function RefreshOnReconnect({ onReconnect }: { onReconnect: () => void }) {
    const status = useConnectionStatus();
    const previous = useRef(status);
    const handler = useRef(onReconnect);

    useEffect(() => {
        handler.current = onReconnect;
    });

    useEffect(() => {
        if (status === 'connected' && previous.current !== 'connected') {
            handler.current();
        }

        previous.current = status;
    }, [status]);

    return null;
}
