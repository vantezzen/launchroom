import { router } from '@inertiajs/react';
import { echoIsConfigured, useEcho } from '@laravel/echo-react';
import { useEffect, useRef } from 'react';

const RELOAD_DEBOUNCE_MS = 400;

function Subscription({
    environment,
    onChange,
}: {
    environment: string;
    onChange: () => void;
}) {
    useEcho(`environments.${environment}`, '.canvas.changed', onChange, [
        environment,
    ]);

    return null;
}

/**
 * Reloads the given props when anything on these environments' canvases
 * changes (private channel per environment, authorized by policy). Without
 * Reverb it falls back to polling, because realtime is an enhancement.
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
    const configured = echoIsConfigured();
    const key = only.join(',');

    const reload = () => {
        if (timer.current !== null) {
            window.clearTimeout(timer.current);
        }

        timer.current = window.setTimeout(() => {
            router.reload({ only: key.split(',') });
        }, RELOAD_DEBOUNCE_MS);
    };

    useEffect(() => {
        if (configured) {
            return;
        }

        const interval = window.setInterval(() => {
            if (document.visibilityState === 'visible') {
                router.reload({ only: key.split(',') });
            }
        }, pollMs);

        return () => window.clearInterval(interval);
    }, [configured, key, pollMs]);

    useEffect(
        () => () => {
            if (timer.current !== null) {
                window.clearTimeout(timer.current);
            }
        },
        [],
    );

    if (!configured) {
        return null;
    }

    return (
        <>
            {environments.map((environment) => (
                <Subscription
                    key={environment}
                    environment={environment}
                    onChange={reload}
                />
            ))}
        </>
    );
}
