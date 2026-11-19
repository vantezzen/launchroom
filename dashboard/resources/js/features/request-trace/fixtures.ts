import type { RequestTrace } from './trace-model';

/*
 * Sample traces for the design gallery and the model tests: one per way a
 * request can end (docs/23 §2.2). Fixed ids and times so screenshots are
 * stable.
 */

const P = 'prj_01JB7Z6X9Q3T8M5N2P4R6S8V0W';
const E = 'env_01JB7Z6X9Q3T8M5N2P4R6S8V0X';
const S = 'svc_01JB7Z6X9Q3T8M5N2P4R6S8V0Y';

const device = (kind: App.Enums.DeviceKind): App.Data.Servers.DeviceData => ({
    kind,
    detected: kind,
    overridden: false,
    model: null,
    provider: null,
    providerName: null,
    virtualized: false,
});

const fra: App.Data.Traces.TraceServerData = {
    id: 'srv_01JB7Z6X9Q3T8M5N2P4R6S8V10',
    name: 'fra-1',
    status: 'online',
    device: device('cloud'),
    href: '/servers/srv_01JB7Z6X9Q3T8M5N2P4R6S8V10',
};

const studio: App.Data.Traces.TraceServerData = {
    id: 'srv_01JB7Z6X9Q3T8M5N2P4R6S8V11',
    name: 'studio',
    status: 'online',
    device: device('compact'),
    href: '/servers/srv_01JB7Z6X9Q3T8M5N2P4R6S8V11',
};

const web: App.Data.Security.SecurityServiceRefData = {
    id: S,
    name: 'web',
    logo: 'nextjs',
    kind: 'app',
    projectId: P,
    projectName: 'shop',
    environmentId: E,
    environmentName: 'production',
    href: `/p/${P}/${E}/s/${S}`,
};

const base: RequestTrace = {
    receivedAt: '2026-11-19T14:02:31.482Z',
    requestId: '3f2b9c1e-6a4d-4e8f-9b0a-7c5d2e1f8a6b',
    method: 'GET',
    path: '/products/:id',
    protocol: 'HTTP/2',
    status: 200,
    upstreamStatus: null,
    origin: 'app',
    failure: null,
    edgeTimeUs: 85_200,
    upstreamTimeUs: 84_300,
    attempts: 1,
    firewall: { mode: 'block', outcome: 'clean', score: 0, rules: [] },
    edge: fra,
    upstreamServer: studio,
    sameServer: false,
    host: 'shop.example.com',
    service: web,
    dashboard: false,
    endsAt: 'service',
    requestClass: 'success',
    summary: 'The app answered with 200.',
    logsHref: `/p/${P}/${E}/logs?service=web&q=3f2b9c1e-6a4d-4e8f-9b0a-7c5d2e1f8a6b`,
    firewallHref: `/p/${P}/${E}/s/${S}/settings/firewall`,
    allow: null,
};

const sqli: App.Data.Security.WafRuleData[] = [
    {
        id: 942100,
        message: 'SQL Injection Attack Detected via libinjection',
        category: 'sqli',
    },
    {
        id: 942190,
        message:
            'Detects MSSQL code execution and information gathering attempts',
        category: 'sqli',
    },
];

export const TRACE_FIXTURES = {
    /** Served by an app on another server. */
    viaOtherServer: base,

    /** The edge runs the app itself. */
    sameServer: {
        ...base,
        protocol: 'HTTP/1.1',
        edgeTimeUs: 12_800,
        upstreamTimeUs: 12_100,
        upstreamServer: fra,
        sameServer: true,
    },

    /** The firewall blocked the request: two rules. */
    blocked: {
        ...base,
        method: 'POST',
        path: '/api/search',
        status: 403,
        origin: 'firewall',
        edgeTimeUs: 2_400,
        upstreamTimeUs: null,
        attempts: null,
        upstreamServer: null,
        firewall: { mode: 'block', outcome: 'blocked', score: 10, rules: sqli },
        endsAt: 'firewall',
        requestClass: 'blocked',
        summary: 'The firewall blocked this request. 2 rules matched.',
        allow: {
            rules: sqli.map((rule) => ({
                rule: rule.id,
                message: rule.message,
                target: 'ARGS:q',
            })),
            path: '/api/search',
            uncovered: [],
            settingsHref: `/p/${P}/${E}/s/${S}/settings/firewall`,
            allowUrl: `/p/${P}/${E}/s/${S}/firewall/exclusions/allow`,
        },
    },

    /** Log only: the app answered, the firewall would have blocked it. */
    detected: {
        ...base,
        method: 'POST',
        path: '/api/search',
        firewall: {
            mode: 'log',
            outcome: 'detected',
            score: 5,
            rules: [
                {
                    id: 932160,
                    message: 'Remote Command Execution: Unix Shell Code Found',
                    category: 'rce',
                },
            ],
        },
        summary:
            'The app answered with 200. The firewall would have blocked it.',
    },

    /** Connection refused after three attempts. */
    refused: {
        ...base,
        status: 502,
        origin: 'upstream_failure',
        failure: 'refused',
        edgeTimeUs: 3_100,
        upstreamTimeUs: null,
        attempts: 3,
        endsAt: 'app_server',
        requestClass: 'serverError',
        summary:
            'The app server refused the connection. The edge tried 3 times.',
    },

    /** The app server was offline: no answer through it in time. */
    timeoutRemote: {
        ...base,
        status: 502,
        origin: 'upstream_failure',
        failure: 'timeout',
        edgeTimeUs: 5_104_000,
        upstreamTimeUs: 5_100_000,
        attempts: 1,
        upstreamServer: { ...studio, status: 'unreachable' },
        endsAt: 'app_server',
        requestClass: 'serverError',
        summary: 'web didn’t answer in time.',
    },

    /** Every replica failed its health check: the edge never dialled. */
    unhealthy: {
        ...base,
        status: 503,
        origin: 'upstream_failure',
        failure: 'no_healthy_upstream',
        edgeTimeUs: 1_100,
        upstreamTimeUs: null,
        attempts: null,
        upstreamServer: null,
        endsAt: 'app_server',
        requestClass: 'serverError',
        summary: 'No replica of web was healthy.',
    },

    /** The service is stopped. */
    stopped: {
        ...base,
        status: 503,
        origin: 'stopped',
        edgeTimeUs: 400,
        upstreamTimeUs: null,
        attempts: null,
        upstreamServer: null,
        endsAt: 'edge',
        requestClass: 'serverError',
        summary: 'The service is stopped, so the edge answered 503.',
    },

    /** No domain on the edge matched the host. */
    noRoute: {
        ...base,
        path: '/',
        status: 404,
        origin: 'no_route',
        edgeTimeUs: 300,
        upstreamTimeUs: null,
        attempts: null,
        firewall: null,
        upstreamServer: null,
        host: null,
        service: null,
        endsAt: 'edge',
        requestClass: 'clientError',
        summary: 'No domain on this server matched the address.',
        logsHref: null,
        firewallHref: null,
    },

    /** HTTP to HTTPS. */
    redirect: {
        ...base,
        path: '/checkout',
        protocol: 'HTTP/1.1',
        status: 308,
        origin: 'redirect',
        edgeTimeUs: 200,
        upstreamTimeUs: null,
        attempts: null,
        firewall: null,
        upstreamServer: null,
        endsAt: 'edge',
        requestClass: 'redirect',
        summary: 'The edge redirected this request.',
    },

    /** The dashboard's own route. */
    dashboard: {
        ...base,
        path: '/servers',
        edgeTimeUs: 46_900,
        upstreamTimeUs: 45_800,
        firewall: null,
        upstreamServer: fra,
        sameServer: true,
        host: 'launchroom.example.com',
        service: null,
        dashboard: true,
        logsHref: null,
        firewallHref: null,
    },
} satisfies Record<string, RequestTrace>;
