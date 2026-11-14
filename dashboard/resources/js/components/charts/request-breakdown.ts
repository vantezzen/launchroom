/**
 * How requests are named and coloured wherever they are broken down
 * (design-system §9 "Request breakdown"): Analytics, the server pages and the
 * Security firewall chart use these, so a blocked request looks the same
 * on every page. Status colours only where the class is a status (4xx
 * amber, 5xx red); requests the web firewall refused get their own violet
 * and stripes, so they never read as the app's own 4xx. Problem classes are
 * hatched as the non-colour cue.
 */
export type RequestClass =
    | 'success'
    | 'redirect'
    | 'clientError'
    | 'blocked'
    | 'detected'
    | 'serverError'
    | 'upgrade';

export type RequestClassStyle = {
    label: string;
    /** Short form for tiles and chips. */
    short: string;
    color: string;
    hatched?: boolean;
};

export const REQUEST_CLASS: Record<RequestClass, RequestClassStyle> = {
    success: { label: 'Success (2xx)', short: '2xx', color: 'var(--signal)' },
    redirect: {
        label: 'Redirects (3xx)',
        short: '3xx',
        color: 'var(--chart-other)',
    },
    clientError: {
        label: 'Client errors (4xx)',
        short: '4xx',
        color: 'var(--status-progress)',
    },
    blocked: {
        label: 'Blocked by firewall',
        short: 'Blocked',
        color: 'var(--chart-7)',
        hatched: true,
    },
    detected: {
        label: 'Would have been blocked',
        short: 'Detected',
        color: 'var(--chart-other)',
        hatched: true,
    },
    serverError: {
        label: 'Server errors (5xx)',
        short: '5xx',
        color: 'var(--status-error)',
        hatched: true,
    },
    upgrade: {
        label: 'Upgrades (1xx)',
        short: '1xx',
        color: 'color-mix(in oklab, var(--chart-other) 45%, transparent)',
    },
};
