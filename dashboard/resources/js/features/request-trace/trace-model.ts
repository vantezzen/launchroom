import type { StatusState } from '@/components/status';
import { formatLatency, plural } from '@/lib/format';
import { labelOf, vocabulary } from '@/lib/vocabulary';

export type RequestTrace = App.Data.Traces.RequestTraceData;
type TraceServer = App.Data.Traces.TraceServerData;
type TraceService = App.Data.Security.SecurityServiceRefData;
type TraceFirewall = App.Data.Traces.TraceFirewallData;

export type RequestClassKey = RequestTrace['requestClass'];

/**
 * A card on the ray's canvas (docs/23 §7). The firewall is its own card
 * because it decides on its own, though it runs on the edge server. The
 * server an app runs on is a row of the service's card ("Runs on studio").
 */
export type PathNode =
    | { kind: 'visitor'; id: 'visitor' }
    | { kind: 'edge'; id: 'edge'; server: TraceServer; both: boolean }
    | { kind: 'firewall'; id: 'firewall'; firewall: TraceFirewall }
    | {
          kind: 'service';
          id: 'service';
          service: TraceService;
          runsOn: TraceServer | null;
      }
    | { kind: 'dashboard'; id: 'dashboard'; runsOn: TraceServer | null }
    /** No domain on the edge matched: a ghost where the service would be. */
    | { kind: 'nowhere'; id: 'nowhere' };

/** travelled: the request went along it; failed: the edge couldn't get through. */
export type HopState = 'travelled' | 'failed' | 'skipped';

export type Hop = {
    from: number;
    to: number;
    state: HopState;
    /** Text on the line ("0.9 ms", "Connection refused"). */
    pill: string | null;
    /** The pill as a sentence (tooltip, screen readers). */
    title: string | null;
};

/** A response a card sent: the status with its words and tone, and why. */
export type Answer = {
    status: number;
    words: string;
    state: StatusState;
    note: string | null;
};

export type TracePath = {
    nodes: PathNode[];
    hops: Hop[];
    /** The furthest card the request got to. */
    reached: number;
    /** The card that sent the visitor the response. */
    end: number;
    /** The answer each card sent, if it sent one. */
    answers: (Answer | null)[];
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

/** Shorter words for a card's answer, where the reason phrase is long. */
const SHORT: Record<number, string> = {
    101: 'Switching',
    301: 'Moved',
    405: 'Not allowed',
    413: 'Too large',
    422: 'Unprocessable',
    429: 'Rate limited',
    499: 'Client closed',
    500: 'Server error',
    503: 'Unavailable',
};

/** "503 Unavailable": the status with a reason short enough for a card. */
export function shortStatusWords(status: number): string {
    return SHORT[status]
        ? `${status} ${SHORT[status]}`
        : (statusWords(status) ?? String(status));
}

/** "200 OK", "418", or null without a status. */
export function statusWords(status: number | null): string | null {
    if (status === null) {
        return null;
    }

    return REASON[status] ? `${status} ${REASON[status]}` : String(status);
}

/** The status's tone: an icon and a colour, never the colour alone. */
export function statusState(cls: RequestClassKey): StatusState {
    switch (cls) {
        case 'success':
        case 'upgrade':
            return 'active';
        case 'redirect':
            return 'idle';
        case 'clientError':
            return 'warning';
        default:
            return 'failed';
    }
}

function classOf(status: number): RequestClassKey {
    return status < 200
        ? 'upgrade'
        : status < 300
          ? 'success'
          : status < 400
            ? 'redirect'
            : status < 500
              ? 'clientError'
              : 'serverError';
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

/** What the firewall decided, as the card's mark. */
export const FIREWALL_DECISION: Record<
    App.Enums.Traces.TraceFirewallOutcome,
    { word: string; state: StatusState }
> = {
    clean: { word: 'Allowed', state: 'active' },
    detected: { word: 'Would block', state: 'warning' },
    blocked: { word: 'Blocked', state: 'failed' },
};

/**
 * The request's path through launchroom (docs/23 §7): visitor, edge
 * server, its firewall, then the service, the dashboard, or a ghost when no
 * domain matched. The hops up to where the request got are travelled, a
 * connection the edge couldn't make is failed, the rest are skipped.
 */
export function tracePath(trace: RequestTrace): TracePath {
    const nodes: PathNode[] = [
        { kind: 'visitor', id: 'visitor' },
        {
            kind: 'edge',
            id: 'edge',
            server: trace.edge,
            both: trace.sameServer,
        },
    ];
    const edge = 1;
    let firewall: number | null = null;

    if (trace.firewall !== null) {
        firewall = nodes.length;
        nodes.push({
            kind: 'firewall',
            id: 'firewall',
            firewall: trace.firewall,
        });
    }

    const runsOn =
        trace.upstreamServer !== null && !trace.sameServer
            ? trace.upstreamServer
            : null;

    if (trace.service !== null) {
        nodes.push({
            kind: 'service',
            id: 'service',
            service: trace.service,
            runsOn,
        });
    } else if (trace.dashboard) {
        nodes.push({ kind: 'dashboard', id: 'dashboard', runsOn });
    } else if (trace.origin === 'no_route') {
        nodes.push({ kind: 'nowhere', id: 'nowhere' });
    }

    const last = nodes.length - 1;
    const target = last > (firewall ?? edge) ? last : null;
    // The last card on the edge server: the firewall when there is one.
    const gate = firewall ?? edge;
    let end: number = edge;
    let reached: number = gate;
    let failed = false;

    switch (trace.origin) {
        case 'app':
            end = target ?? gate;
            reached = end;
            break;
        case 'firewall':
            end = firewall ?? edge;
            // The firewall may have refused a response the app already sent.
            reached =
                trace.upstreamStatus !== null && target !== null ? target : end;
            break;
        case 'upstream_failure':
            failed = target !== null;
            break;
    }

    const hops: Hop[] = nodes.slice(1).map((_, i) => {
        const to = i + 1;

        if (to <= reached) {
            // Times live on the cards ("Time here") and in the TTFB bar,
            // not on the lines.
            return { from: i, to, state: 'travelled', pill: null, title: null };
        }

        if (failed && i === reached) {
            return {
                from: i,
                to,
                state: 'failed',
                pill: failureWords(trace) ?? 'Connection failed',
                title: failureTitle(trace),
            };
        }

        return { from: i, to, state: 'skipped', pill: null, title: null };
    });

    const answers: (Answer | null)[] = nodes.map(() => null);
    const final = shortStatusWords(trace.status);

    if (
        target !== null &&
        trace.upstreamStatus !== null &&
        reached === target
    ) {
        // The app's own answer, when the visitor got another one.
        answers[target] = {
            status: trace.upstreamStatus,
            words: shortStatusWords(trace.upstreamStatus),
            state: statusState(classOf(trace.upstreamStatus)),
            note: end === target ? `The edge sent ${final}` : null,
        };
    }

    if (answers[end] === null) {
        answers[end] = {
            status: trace.status,
            words: final,
            state: statusState(trace.requestClass),
            note: answerNote(trace, end === firewall),
        };
    }

    return { nodes, hops, reached, end, answers };
}

/** Why the edge server answered itself, under its answer. */
function answerNote(trace: RequestTrace, atFirewall: boolean): string | null {
    const name = targetName(trace);

    switch (trace.origin) {
        case 'firewall':
            return atFirewall && trace.upstreamStatus !== null
                ? `Blocked ${name}’s response`
                : null;
        case 'upstream_failure':
            return trace.attempts !== null && trace.attempts > 1
                ? `Couldn’t reach ${name}, tried ${trace.attempts} times`
                : `Couldn’t reach ${name}`;
        case 'stopped':
            return `${trace.service?.name ?? 'The service'} is stopped`;
        case 'redirect':
            return 'Redirected to HTTPS or the main domain';
        case 'no_route':
            return 'No domain matched this address';
        case 'access_refused':
            return 'Refused by sign-in protection';
        default:
            return null;
    }
}

function failureTitle(trace: RequestTrace): string {
    const time = timing(trace);
    const after =
        time?.appUs != null ? ` after ${formatMicros(time.appUs)}` : '';
    const tries =
        trace.attempts !== null && trace.attempts > 1
            ? `${after ? ',' : ' after'} ${plural(trace.attempts, 'attempt')}`
            : '';

    return `${failureWords(trace) ?? 'The connection failed'}${after}${tries}`;
}

/** The service's name, or what stands for it. */
export function targetName(trace: RequestTrace): string {
    if (trace.service !== null) {
        return trace.service.name;
    }

    return trace.dashboard ? 'the dashboard' : 'the app';
}

/** The service's name as a label ("web", "Dashboard", "App"). */
export function targetLabel(trace: RequestTrace): string {
    if (trace.service !== null) {
        return trace.service.name;
    }

    return trace.dashboard ? 'Dashboard' : 'App';
}

export type TtfbSegment = {
    key: 'edge' | 'app';
    label: string;
    us: number;
    /** Retries, on the app's segment ("3 attempts"). */
    note: string | null;
};

export type Ttfb = {
    totalUs: number;
    segments: TtfbSegment[];
    /** Received, sent to the app, first byte: microseconds from receipt. */
    milestones: { key: string; label: string; us: number }[];
};

/**
 * Time to first byte (docs/23 §7): the edge's share and the app's as
 * segments, with the moments between them.
 */
export function ttfb(trace: RequestTrace): Ttfb | null {
    const time = timing(trace);

    if (time === null) {
        return null;
    }

    const segments: TtfbSegment[] = [
        { key: 'edge', label: 'Edge', us: time.edgeUs, note: null },
    ];
    const milestones = [{ key: 'received', label: 'Received', us: 0 }];

    if (time.appUs !== null) {
        segments.push({
            key: 'app',
            label: targetLabel(trace),
            us: time.appUs,
            note:
                trace.attempts !== null && trace.attempts > 1
                    ? plural(trace.attempts, 'attempt')
                    : null,
        });
        milestones.push({
            key: 'sent',
            label: `Sent to ${targetName(trace)}`,
            us: time.edgeUs,
        });
    }

    milestones.push({ key: 'first', label: 'First byte', us: time.totalUs });

    return { totalUs: time.totalUs, segments, milestones };
}

/** What the firewall did, in words ("Blocked, 2 rules matched"). */
export function firewallWords(trace: RequestTrace): string | null {
    const firewall = trace.firewall;

    if (firewall === null) {
        return null;
    }

    const outcome = FIREWALL_DECISION[firewall.outcome].word;
    const count = firewall.rules.length;

    return count === 0
        ? outcome
        : `${outcome}, ${count === 1 ? '1 rule' : `${count} rules`} matched`;
}

/** The ray's header names: today's and the one of edges before v0.1.6. */
const RAY_HEADER = /(?:x-launchroom-ray|launchroom-trace)/i;

/** A ray value: `lr<n>.` and the base64url envelope, or the envelope alone (older edges). */
const RAY_VALUE = /(?:lr\d+\.)?[A-Za-z0-9_-]{24,}/;

/** A prefixed value standing alone somewhere in the text. */
const PREFIXED_RAY =
    /(?<![A-Za-z0-9_.-])lr\d+\.[A-Za-z0-9_-]{24,}(?![A-Za-z0-9_.-])/;

/**
 * The ray in pasted text (docs/23 §6): the bare value, a header line
 * (`X-Launchroom-Ray: lr1.…`, or `Launchroom-Trace: …` from older edges), a
 * whole `curl -i` output or a HAR entry (`"name": "x-launchroom-ray",
 * "value": "lr1.…"`), with quotes and line breaks around it. The value is
 * returned with its prefix, so the server can tell a newer format. Null when
 * there's no ray in it (the server decides the rest).
 */
export function normaliseTrace(input: string): string | null {
    // The value alone or a single header line, perhaps wrapped over lines.
    const whole = input
        .trim()
        .replace(/^["']+|["']+$/g, '')
        .replace(new RegExp(`^${RAY_HEADER.source}\\s*:\\s*`, 'i'), '')
        .replace(/["'\s]+/g, '');

    if (new RegExp(`^${RAY_VALUE.source}$`).test(whole)) {
        return whole;
    }

    // Somewhere in a longer text: after the header name, else any prefixed value.
    const named = new RegExp(
        `${RAY_HEADER.source}["']?\\s*(?::|,\\s*["']?value["']?\\s*:)\\s*["']?(${RAY_VALUE.source})(?![A-Za-z0-9_.-])`,
        'i',
    ).exec(input);

    return named?.[1] ?? PREFIXED_RAY.exec(input)?.[0] ?? null;
}
