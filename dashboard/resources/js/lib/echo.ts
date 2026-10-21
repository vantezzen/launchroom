import { configureEcho } from '@laravel/echo-react';

type RealtimeConfig = App.Data.Instance.RealtimeConfigData;

let configured = false;

/**
 * Configure Laravel Echo for Reverb (live logs, canvas and deployment
 * updates).
 *
 * The settings come from the `realtime` shared prop at runtime (the
 * production image is built without VITE_REVERB_*). Each value falls back to
 * the build-time VITE_REVERB_* value (local dev: Reverb on its own port),
 * then to the page's own origin (production proxies /app/* to Reverb).
 *
 * Realtime is an enhancement, never a requirement: without a key Echo stays
 * unconfigured and every subscription falls back to polling. Hooks that
 * subscribe to channels check `echoIsConfigured()` from
 * `@laravel/echo-react` first.
 */
export function initializeEcho(
    runtime: RealtimeConfig | null | undefined,
): boolean {
    if (configured) {
        return true;
    }

    const key = runtime?.key || import.meta.env.VITE_REVERB_APP_KEY;

    if (!key || typeof window === 'undefined') {
        return false;
    }

    const scheme =
        runtime?.scheme ||
        import.meta.env.VITE_REVERB_SCHEME ||
        window.location.protocol.replace(':', '');
    const tls = scheme === 'https';
    const port = Number(
        runtime?.port ||
            import.meta.env.VITE_REVERB_PORT ||
            window.location.port ||
            (tls ? 443 : 80),
    );

    configureEcho({
        broadcaster: 'reverb',
        key,
        wsHost:
            runtime?.host ||
            import.meta.env.VITE_REVERB_HOST ||
            window.location.hostname,
        wsPort: port,
        wssPort: port,
        forceTLS: tls,
        enabledTransports: ['ws', 'wss'],
    });

    configured = true;

    return true;
}
