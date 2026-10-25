import { LoaderCircle } from 'lucide-react';
import { useSyncExternalStore } from 'react';
import { connectionState, subscribeConnection } from '@/lib/connection';
import type { ConnectionState } from '@/lib/connection';

/** The dashboard's connection to its server (lib/connection.ts). */
export function useConnection(): ConnectionState {
    return useSyncExternalStore(
        subscribeConnection,
        connectionState,
        connectionState,
    );
}

/**
 * One calm, non-blocking notice while launchroom can't be reached (a
 * restart, an update): the page stays usable to read, background updates
 * pause and resume by themselves.
 */
export function ReconnectBanner() {
    const state = useConnection();

    return (
        <div
            role="status"
            aria-live="polite"
            className="pointer-events-none fixed inset-x-0 top-3 z-[60] flex justify-center px-4"
        >
            {state === 'reconnecting' && (
                <div className="pointer-events-auto flex max-w-full animate-enter items-center gap-2 rounded-full border bg-popover py-1.5 pr-3.5 pl-2.5 type-label text-foreground shadow-float">
                    <LoaderCircle
                        aria-hidden="true"
                        className="size-4 shrink-0 text-status-progress motion-safe:animate-spin"
                    />
                    <span className="truncate">
                        Reconnecting to launchroom…
                    </span>
                </div>
            )}
        </div>
    );
}
