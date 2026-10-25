import { Link, usePage } from '@inertiajs/react';
import { Gauge, X } from 'lucide-react';
import { useState } from 'react';
import { useDocsUrl } from '@/features/settings/settings-card';
import { show as serverPage } from '@/routes/servers';

const STORAGE_KEY = 'launchroom:load-notice-dismissed-until';
const DISMISS_HOURS = 6;

function dismissedUntil(): number {
    try {
        return Number(window.localStorage.getItem(STORAGE_KEY) ?? 0) || 0;
    } catch {
        return 0;
    }
}

/**
 * A calm notice at the bottom of the sidebar while the server running
 * launchroom is under heavy load (docs/08 §4; `controlPlaneLoad` shared
 * prop, people who manage that server only). Dismissing hides it for six
 * hours in this browser.
 */
export function ControlPlaneLoadNotice() {
    const { controlPlaneLoad: load } = usePage().props;
    const docsUrl = useDocsUrl();
    const [hidden, setHidden] = useState(() => dismissedUntil() > Date.now());

    if (load === null || load === undefined || hidden) {
        return null;
    }

    const dismiss = () => {
        try {
            window.localStorage.setItem(
                STORAGE_KEY,
                String(Date.now() + DISMISS_HOURS * 3_600_000),
            );
        } catch {
            // Without storage it stays hidden until the next page load.
        }
        setHidden(true);
    };

    return (
        <section
            aria-label="Heavy load"
            className="relative flex animate-enter flex-col gap-2 rounded-lg border bg-background p-3 pr-8 shadow-card group-data-[collapsible=icon]:hidden"
        >
            <p className="flex items-center gap-1.5 type-label">
                <Gauge
                    aria-hidden="true"
                    className="size-4 shrink-0 text-status-progress"
                />
                Heavy load
            </p>
            <p className="type-small text-muted-foreground">
                Your launchroom server is under heavy load. The dashboard may be
                slow. Consider adding resources or a separate build server.
            </p>
            <p className="flex flex-wrap gap-x-3 gap-y-1 type-small">
                <Link
                    href={serverPage(load.serverId)}
                    className="font-medium text-foreground underline-offset-4 hover:underline focus-visible:underline focus-visible:outline-none"
                >
                    View {load.serverName}
                </Link>
                <a
                    href={docsUrl(
                        'servers/roles-and-placement#where-builds-run',
                    )}
                    target="_blank"
                    rel="noreferrer"
                    className="font-medium text-foreground underline-offset-4 hover:underline focus-visible:underline focus-visible:outline-none"
                >
                    Build servers
                </a>
            </p>
            <button
                type="button"
                onClick={dismiss}
                aria-label={`Hide for ${DISMISS_HOURS} hours`}
                title={`Hide for ${DISMISS_HOURS} hours`}
                className="absolute top-2 right-2 flex size-6 items-center justify-center rounded-md text-muted-foreground transition-colors duration-fast hover:bg-hover hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                <X aria-hidden="true" className="size-3.5" />
            </button>
        </section>
    );
}
