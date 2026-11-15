import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { ChannelEvents } from '@/components/channel-events';
import { liveReload } from '@/lib/live-reload';
import { SERVICE_PANEL_CACHE_TAG } from '@/lib/cache-tags';
import { channelName } from '@/lib/realtime';

const RELOAD_DEBOUNCE_MS = 400;

/**
 * Reloads the given props when anything on these environments' canvases
 * changes (private channel per environment, authorized by policy), or on a
 * polling interval while the socket is down.
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
    const mounted = useRef(true);
    const key = only.join(',');

    // One debounced reload for many environments changing (or polling) together.
    const reload = () => {
        if (!mounted.current) {
            return;
        }

        // Prefetched service panels (canvas hover) describe the old state.
        router.flushByCacheTags(SERVICE_PANEL_CACHE_TAG);

        if (timer.current !== null) {
            window.clearTimeout(timer.current);
        }

        timer.current = window.setTimeout(() => {
            timer.current = null;
            liveReload(key.split(','), reload);
        }, RELOAD_DEBOUNCE_MS);
    };

    useEffect(() => {
        mounted.current = true;

        return () => {
            mounted.current = false;

            if (timer.current !== null) {
                window.clearTimeout(timer.current);
            }
        };
    }, []);

    return (
        <>
            {environments.map((environment) => (
                <ChannelEvents
                    key={environment}
                    channel={channelName('environments', environment)}
                    event="canvas.changed"
                    pollMs={pollMs}
                    onEvent={reload}
                />
            ))}
        </>
    );
}
