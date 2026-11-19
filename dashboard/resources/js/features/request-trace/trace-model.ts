import { REQUEST_CLASS } from '@/components/charts/request-breakdown';
import { formatLatency, plural } from '@/lib/format';
import { labelOf, vocabulary } from '@/lib/vocabulary';

export type RequestTrace = App.Data.Traces.RequestTraceData;
type TraceServer = App.Data.Traces.TraceServerData;
type TraceService = App.Data.Security.SecurityServiceRefData;

export type RequestClassKey = keyof typeof REQUEST_CLASS;

export type PathNode =
    | { kind: 'visitor'; id: 'visitor' }
    | {
          kind: 'server';
          id: string;
          server: TraceServer;
          role: 'edge' | 'app' | 'both';
      }
    | { kind: 'service'; id: 'service'; service: TraceService }
    | { kind: 'dashboard'; id: 'dashboard' }
    /** No domain on the edge matched: a ghost where the service would be. */
    | { kind: 'nowhere'; id: 'nowhere' };

export type HopState = 'reached' | 'failed' | 'skipped';

export type Hop = {
    from: number;
    to: number;
    state: HopState;
    /** Text on the line ("0.9 ms", "Connection refused"). */
    pill: string | null;
    /** The pill as a sentence (tooltip, screen readers). */
    title: string | null;
};

export type Outcome = {
    cls: RequestClassKey;
    /** The marker's words: "200 OK", "Blocked by firewall". */
    word: string;
};

export type TracePath = {
    nodes: PathNode[];
    hops: Hop[];
    /** The edge server's node (the firewall gate sits on its far side). */
    edge: number;
    /** Where the request ended: it carries the outcome marker. */
    end: number;
    /** Nodes after this index were not reached (dashed, dimmed). */
    reached: number;
    outcome: Outcome;
};

const REASON: Record<number, string> = {
    101: 'Switching protocols',
    200: 'OK',
    201: 'Created',
    202: 'Accepted',
    204: 'No content',
    301: 'Moved permanently',
    302: 'Found',
    303: 'See other',
    304: 'Not modified',
    307: 'Temporary redirect',
    308: 'Permanent redirect',
    400: 'Bad request',
    401: 'Unauthorized',
    403: 'Forbidden',
    404: 'Not found',
    405: 'Method not allowed',
    408: 'Request timeout',
    409: 'Conflict',
    413: 'Content too large',
    422: 'Unprocessable content',
    429: 'Too many requests',
    499: 'Client closed request',
    500: 'Internal server error',
    502: 'Bad gateway',
    503: 'Service unavailable',
    504: 'Gateway timeout',
};

/** Shorter words for the outcome marker, where the reason phrase is long. */
const SHORT: Record<number, string> = {
    101: 'Switching',
    301: 'Moved',
    307: 'Redirect',
    308: 'Redirect',
    405: 'Not allowed',
    413: 'Too large',
    422: 'Unprocessable',
    429: 'Rate limited',
    499: 'Client closed',
    500: 'Server error',
    503: 'Unavailable',
};

/** "200 OK", "418", or null without a status. */
export function statusWords(status: number | null): string | null {
    if (status === null) {
        return null;
    }

    return REASON[status] ? `${status} ${REASON[status]}` : String(status);
}

/** Why the edge couldn't reach the app, in the enum's words. */
export function failureWords(trace: RequestTrace): string | null {
    return labelOf(vocabulary.Traces.TraceFailure, trace.failure) ?? null;
}

/** Microseconds as a latency ("0.9 ms", "84 ms", "1.2 s"). */
export function formatMicros(us: number): string {
    return formatLatency(us / 1000);
}

export type Timing = {
    /** Receipt to the response headers. */
    totalUs: number;
    /** Time at the edge (total minus the app's share). */
    edgeUs: number;
    /** Dial to the app's response headers; null when the edge answered itself. */
    appUs: number | null;
};

export function timing(trace: RequestTrace): Timing | null {
    if (trace.edgeTimeUs === null) {
        return null;
    }

    const appUs =
        trace.upstreamTimeUs !== null
            ? Math.min(trace.upstreamTimeUs, trace.edgeTimeUs)
            : null;

    return {
        totalUs: trace.edgeTimeUs,
        edgeUs: trace.edgeTimeUs - (appUs ?? 0),
        appUs,
    };
}

/** The marker's colour and words: the server's request class, the status. */
export function outcomeOf(trace: RequestTrace): Outcome {
    const cls = trace.requestClass;

    return {
        cls,
        word:
            cls === 'blocked'
                ? REQUEST_CLASS.blocked.label
                : SHORT[trace.status]
                  ? `${trace.status} ${SHORT[trace.status]}`
                  : (statusWords(trace.status) ?? String(trace.status)),
    };
}

/**
 * The request's path through launchroom (docs/23 §7): visitor, edge
 * server, app server (unless the edge ran the app itself), then the
 * service, the dashboard, or a ghost when no domain matched. The hops up
 * to where the request ended are drawn; the rest are dashed.
 */
export function tracePath(trace: RequestTrace): TracePath {
    const nodes: PathNode[] = [
        { kind: 'visitor', id: 'visitor' },
        {
            kind: 'server',
            id: trace.edge.id,
            server: trace.edge,
            role: trace.sameServer ? 'both' : 'edge',
        },
    ];
    const edge = 1;

    if (trace.upstreamServer !== null && !trace.sameServer) {
        nodes.push({
            kind: 'server',
            id: trace.upstreamServer.id,
            server: trace.upstreamServer,
            role: 'app',
        });
    }

    if (trace.service !== null) {
        nodes.push({ kind: 'service', id: 'service', service: trace.service });
    } else if (trace.dashboard) {
        nodes.push({ kind: 'dashboard', id: 'dashboard' });
    } else if (trace.origin === 'no_route') {
        nodes.push({ kind: 'nowhere', id: 'nowhere' });
    }

    const last = nodes.length - 1;
    const appServer = nodes.findIndex(
        (node, i) => i > edge && node.kind === 'server',
    );
    let end: number;
    let reached: number;
    let failedFrom: number | null = null;

    if (trace.origin === 'upstream_failure') {
        // The edge answered (502/503/504), so the outcome sits on it. A
        // refused connection reached the app server and the app refused;
        // anything else failed on the way out of the edge (through the app
        // server, or straight to a local replica).
        end = edge;
        reached =
            trace.failure === 'refused' && appServer > 0 ? appServer : edge;
        failedFrom = reached;
    } else {
        end = trace.endsAt === 'service' ? last : edge;
        // The firewall may have refused a response the app already sent.
        reached =
            trace.endsAt === 'firewall' && trace.upstreamStatus !== null
                ? last
                : end;
    }

    const time = timing(trace);
    const name = targetName(trace);

    const hops: Hop[] = nodes.slice(1).map((_, i) => {
        const to = i + 1;

        if (i === 0) {
            // Without the app's share, the edge's is all of it only when
            // the app didn't answer.
            const show =
                time !== null &&
                (time.appUs !== null || trace.endsAt !== 'service');

            return {
                from: i,
                to,
                state: 'reached',
                pill: show ? formatMicros(time.edgeUs) : null,
                title: show
                    ? `${formatMicros(time.edgeUs)} on ${trace.edge.name}`
                    : null,
            };
        }

        if (to <= reached) {
            // The app's share goes on the hop leaving the edge.
            const app = i === edge && time?.appUs != null ? time.appUs : null;

            return {
                from: i,
                to,
                state: 'reached',
                pill: app !== null ? formatMicros(app) : null,
                title:
                    app !== null
                        ? `${formatMicros(app)} until ${name} answered`
                        : null,
            };
        }

        if (i === failedFrom) {
            return {
                from: i,
                to,
                state: 'failed',
                pill: failureWords(trace),
                title: failureTitle(trace),
            };
        }

        return { from: i, to, state: 'skipped', pill: null, title: null };
    });

    return { nodes, hops, edge, end, reached, outcome: outcomeOf(trace) };
}

function failureTitle(trace: RequestTrace): string {
    const tries =
        trace.attempts !== null && trace.attempts > 1
            ? ` after ${plural(trace.attempts, 'attempt')}`
            : '';

    return `${failureWords(trace) ?? 'The connection failed'}${tries}`;
}

/** The service's name, or what stands for it. */
export function targetName(trace: RequestTrace): string {
    if (trace.service !== null) {
        return trace.service.name;
    }

    return trace.dashboard ? 'the dashboard' : 'the app';
}

/** What the firewall did, in words ("Blocked, 2 rules matched"). */
export function firewallWords(trace: RequestTrace): string | null {
    const firewall = trace.firewall;

    if (firewall === null) {
        return null;
    }

    const outcome =
        labelOf(vocabulary.Traces.TraceFirewallOutcome, firewall.outcome) ??
        firewall.outcome;
    const count = firewall.rules.length;

    return count === 0
        ? outcome
        : `${outcome}, ${count === 1 ? '1 rule' : `${count} rules`} matched`;
}

/**
 * The pasted text as a trace: a `Launchroom-Trace:` prefix and whitespace
 * dropped. Null when it can't be one (the server decides the rest).
 */
export function normaliseTrace(input: string): string | null {
    const value = input
        .trim()
        .replace(/^launchroom-trace\s*:\s*/i, '')
        .replace(/\s+/g, '');

    return /^[A-Za-z0-9_-]{24,}$/.test(value) ? value : null;
}
