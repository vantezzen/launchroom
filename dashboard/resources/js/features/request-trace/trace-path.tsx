import { Link } from '@inertiajs/react';
import {
    CircleCheck,
    CircleX,
    CornerDownRight,
    Eye,
    Gauge,
    Globe,
    ListChecks,
    Network,
    SearchX,
    Shield,
    SlidersHorizontal,
    Timer,
    X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useRef } from 'react';
import type { CSSProperties, ReactNode, RefObject } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { useMeasuredWidth } from '@/components/charts/use-measure';
import { DeviceImage } from '@/components/devices/device-image';
import { ServiceGlyph } from '@/components/service-glyph';
import { Status, StatusPill, serverStatus } from '@/components/status';
import { deviceStatus } from '@/features/servers/format';
import { prefersReducedMotion } from '@/lib/motion';
import { cn } from '@/lib/utils';
import { labelOf, vocabulary } from '@/lib/vocabulary';
import {
    FIREWALL_DECISION,
    firewallWords,
    formatMicros,
    timing,
    tracePath,
} from './trace-model';
import type {
    Answer,
    Hop,
    PathNode,
    RequestTrace,
    TracePath,
} from './trace-model';
import './request-trace.css';

type Size = 'visitor' | 'edge' | 'firewall' | 'target';

/** Card widths side by side: as wide as there is room for, down to the minimum. */
const IDEAL: Record<Size, number> = {
    visitor: 176,
    edge: 216,
    firewall: 196,
    target: 236,
};
const MIN: Record<Size, number> = {
    visitor: 152,
    edge: 180,
    firewall: 172,
    target: 188,
};
/** The picture stage: on top in a row, on the left when stacked. */
const STAGE = 88;
const PAD_ROW = 28;
const PAD_STACK = 16;
const MAX_GAP = 120;
const STACK_W = 440;

/** The flying dot (docs/23 §7): slow, one at a time, a rest between runs. */
const HOP_MS = 1600;
const LEAD_MS = 900;
const FADE_MS = 450;
const REST_MS = 1600;
const DOT_EASE = 'cubic-bezier(0.45, 0, 0.3, 1)';

function sizeOf(node: PathNode): Size {
    switch (node.kind) {
        case 'visitor':
        case 'edge':
        case 'firewall':
            return node.kind;
        default:
            return 'target';
    }
}

function pillWidth(hop: Hop, iconOnly: boolean): number {
    if (hop.state === 'failed') {
        return iconOnly ? 28 : Math.round((hop.pill ?? '').length * 7.3 + 40);
    }

    return hop.pill ? Math.round(hop.pill.length * 7.3 + 22) : 0;
}

type Layout =
    | {
          columns: true;
          iconOnly: boolean;
          widths: number[];
          gaps: number[];
      }
    | { columns: false; width: number };

/**
 * Cards side by side while they fit at their minimum with each line long
 * enough for its pill (a failed one shrinks to its ✕ first); else one
 * column.
 */
function layout(path: TracePath, width: number): Layout {
    const sizes = path.nodes.map(sizeOf);
    const available = width - PAD_ROW * 2;
    const ideal = sizes.reduce((sum, s) => sum + IDEAL[s], 0);
    const min = sizes.reduce((sum, s) => sum + MIN[s], 0);

    for (const iconOnly of [false, true]) {
        const needs = path.hops.map((hop) =>
            Math.max(40, pillWidth(hop, iconOnly) + 24),
        );
        const needed = needs.reduce((a, b) => a + b, 0);

        if (min + needed > available) {
            continue;
        }

        const t = Math.min(
            1,
            Math.max(0, (available - needed - min) / (ideal - min)),
        );
        const widths = sizes.map((s) =>
            Math.round(MIN[s] + (IDEAL[s] - MIN[s]) * t),
        );
        const spare = available - needed - widths.reduce((a, b) => a + b, 0);
        const extra = needs.length > 0 ? Math.max(0, spare) / needs.length : 0;
        const gaps = needs.map((need) =>
            Math.round(Math.max(need, Math.min(MAX_GAP, need + extra))),
        );

        return { columns: true, iconOnly, widths, gaps };
    }

    return {
        columns: false,
        width: Math.min(width - PAD_STACK * 2, STACK_W),
    };
}

/** A row of a card: icon, label, value right. */
type Row = {
    key: string;
    icon: LucideIcon;
    label: string;
    value: ReactNode;
    /** Extra line under the row (a server's state). */
    after?: ReactNode;
};

function Rows({ rows }: { rows: Row[] }) {
    if (rows.length === 0) {
        return null;
    }

    return (
        <dl className="flex flex-col gap-2">
            {rows.map(({ key, icon: Icon, label, value, after }) => (
                <div key={key} className="flex min-w-0 flex-col gap-1">
                    <div className="flex min-w-0 items-center gap-2 type-small">
                        <Icon
                            aria-hidden="true"
                            className="size-3.5 shrink-0 text-faint"
                        />
                        <dt className="shrink-0 text-muted-foreground">
                            {label}
                        </dt>
                        <dd className="ml-auto flex min-w-0 items-center justify-end gap-1.5 text-right tabular-nums">
                            {value}
                        </dd>
                    </div>
                    {after && <div className="flex justify-end">{after}</div>}
                </div>
            ))}
        </dl>
    );
}

/** The response a card sent, at its foot: the status pill and why. */
function AnswerFoot({ answer }: { answer: Answer }) {
    return (
        <div className="-mx-3.5 mt-auto flex flex-col items-start gap-1.5 border-t px-3.5 pt-3">
            <span className="type-small text-muted-foreground">Answered</span>
            <StatusPill
                state={answer.state}
                label={answer.words}
                className="tabular-nums"
            />
            {answer.note && (
                <p className="type-small text-muted-foreground">
                    {answer.note}
                </p>
            )}
        </div>
    );
}

/** The firewall's decision: an explicit check, ✕ or eye, with its word. */
export function DecisionIcon({
    outcome,
    className,
}: {
    outcome: App.Enums.Traces.TraceFirewallOutcome;
    className?: string;
}) {
    const Icon =
        outcome === 'blocked'
            ? CircleX
            : outcome === 'detected'
              ? Eye
              : CircleCheck;

    return (
        <Icon
            aria-hidden="true"
            strokeWidth={2}
            className={cn(
                'shrink-0',
                outcome === 'blocked'
                    ? 'text-status-error'
                    : outcome === 'detected'
                      ? 'text-status-progress'
                      : 'text-status-ok',
                className,
            )}
        />
    );
}

function Decision({
    outcome,
}: {
    outcome: App.Enums.Traces.TraceFirewallOutcome;
}) {
    return (
        <span className="inline-flex items-center gap-1.5 type-small">
            <DecisionIcon outcome={outcome} className="size-3.5" />
            {FIREWALL_DECISION[outcome].word}
        </span>
    );
}

/** A 44 px tile like the logo tiles, for the cards without device art. */
function Tile({ children, dashed }: { children: ReactNode; dashed?: boolean }) {
    return (
        <span
            className={cn(
                'relative flex size-11 items-center justify-center rounded-[11px] border bg-card text-foreground',
                dashed &&
                    'border-dashed border-border-strong bg-transparent text-faint',
            )}
        >
            {children}
        </span>
    );
}

type CardProps = {
    columns: boolean;
    width: number;
    label: string;
    picture: ReactNode;
    title: ReactNode;
    subtitle?: ReactNode;
    mark?: ReactNode;
    rows?: Row[];
    answer?: Answer | null;
    /** Not reached: dashed and quieter. */
    ghost?: boolean;
    style?: CSSProperties;
};

/**
 * A card on the ray's canvas: the picture on a recessed stage, the name
 * with its state, labelled rows, and the answer it sent at its foot.
 */
function Card({
    columns,
    width,
    label,
    picture,
    title,
    subtitle,
    mark,
    rows = [],
    answer,
    ghost,
    style,
}: CardProps) {
    return (
        <div
            role="group"
            aria-label={label}
            className={cn(
                'flex min-w-0 shrink-0 overflow-hidden rounded-[14px] border bg-card shadow-card',
                columns ? 'flex-col' : 'flex-row',
                ghost &&
                    'border-dashed border-border-strong bg-card/50 shadow-none',
            )}
            style={{ width, ...style }}
        >
            <div
                className={cn(
                    'flex shrink-0 justify-center bg-recessed',
                    columns
                        ? 'items-center border-b'
                        : 'items-start border-r pt-3',
                    ghost &&
                        'border-dashed border-border-strong bg-transparent opacity-70',
                )}
                style={columns ? { height: STAGE } : { width: STAGE }}
            >
                {picture}
            </div>
            <div className="flex min-w-0 flex-1 flex-col gap-3 p-3.5">
                <div
                    className={cn(
                        'flex min-w-0 flex-col gap-0.5',
                        ghost && 'opacity-70',
                    )}
                >
                    <div className="flex min-w-0 items-center justify-between gap-2">
                        <span className="min-w-0 truncate type-label">
                            {title}
                        </span>
                        {mark && <span className="shrink-0">{mark}</span>}
                    </div>
                    {subtitle && (
                        <span className="truncate type-small text-muted-foreground">
                            {subtitle}
                        </span>
                    )}
                </div>
                <Rows rows={rows} />
                {answer && <AnswerFoot answer={answer} />}
            </div>
        </div>
    );
}

/** The service's state when the edge couldn't get an answer from it. */
const SILENT: Partial<
    Record<
        App.Enums.Traces.TraceFailure,
        { word: string; state: 'failed' | 'idle' }
    >
> = {
    refused: { word: 'Refused', state: 'failed' },
    timeout: { word: 'No answer', state: 'failed' },
    no_healthy_upstream: { word: 'Unhealthy', state: 'failed' },
    client_closed: { word: 'No answer', state: 'idle' },
};

function NotReached() {
    return (
        <Status
            state="idle"
            label="Not reached"
            className="type-small text-muted-foreground"
        />
    );
}

/** A server's name as a link to it. */
function ServerLink({ server }: { server: App.Data.Traces.TraceServerData }) {
    return (
        <Link
            href={server.href}
            className="truncate rounded-sm outline-none hover:underline focus-visible:ring-2 focus-visible:ring-signal"
        >
            {server.name}
        </Link>
    );
}

function NodeCard({
    node,
    index,
    trace,
    path,
    columns,
    width,
    style,
    pulseRef,
}: {
    node: PathNode;
    index: number;
    trace: RequestTrace;
    path: TracePath;
    columns: boolean;
    width: number;
    style?: CSSProperties;
    pulseRef?: (el: HTMLElement | null) => void;
}) {
    const ghost = index > path.reached;
    const answer = path.answers[index];
    const time = timing(trace);
    const common = { columns, width, style, answer, ghost };

    switch (node.kind) {
        case 'visitor':
            return (
                <Card
                    {...common}
                    label="Visitor"
                    picture={
                        <Tile>
                            <Globe aria-hidden="true" className="size-5" />
                        </Tile>
                    }
                    title="Visitor"
                    rows={[
                        ...(trace.method
                            ? [
                                  {
                                      key: 'method',
                                      icon: CornerDownRight,
                                      label: 'Method',
                                      value: (
                                          <span className="type-mono-small">
                                              {trace.method}
                                          </span>
                                      ),
                                  },
                              ]
                            : []),
                        ...(trace.protocol
                            ? [
                                  {
                                      key: 'protocol',
                                      icon: Network,
                                      label: 'Protocol',
                                      value: trace.protocol,
                                  },
                              ]
                            : []),
                    ]}
                />
            );

        case 'edge': {
            const { server } = node;
            const state = serverStatus[server.status];
            const edgeUs = time?.edgeUs ?? null;

            return (
                <Card
                    {...common}
                    label={`${server.name}, ${node.both ? 'edge and app server' : 'edge server'}`}
                    picture={
                        <DeviceImage
                            device={server.device.kind}
                            status={deviceStatus(server.status)}
                            size={columns ? 136 : 76}
                        />
                    }
                    title={<ServerLink server={server} />}
                    subtitle={node.both ? 'Edge and app server' : 'Edge server'}
                    mark={
                        <Status
                            state={state.state}
                            label={state.label}
                            className="type-small"
                        />
                    }
                    rows={
                        edgeUs !== null
                            ? [
                                  {
                                      key: 'time',
                                      icon: Timer,
                                      label: 'Time here',
                                      value: formatMicros(edgeUs),
                                  },
                              ]
                            : []
                    }
                />
            );
        }

        case 'firewall': {
            const { firewall } = node;
            const count = firewall.rules.length;
            const mode =
                labelOf(vocabulary.Security.WafMode, firewall.mode) ??
                firewall.mode;

            return (
                <Card
                    {...common}
                    label={`Firewall: ${firewallWords(trace) ?? ''}`}
                    picture={
                        <Tile>
                            <Shield aria-hidden="true" className="size-5" />
                            <span
                                ref={pulseRef}
                                className="absolute -right-1.5 -bottom-1.5 flex size-5 items-center justify-center rounded-full bg-card"
                            >
                                <DecisionIcon
                                    outcome={firewall.outcome}
                                    className="size-4"
                                />
                            </span>
                        </Tile>
                    }
                    title="Firewall"
                    subtitle={`on ${trace.edge.name}`}
                    mark={<Decision outcome={firewall.outcome} />}
                    rows={[
                        {
                            key: 'mode',
                            icon: SlidersHorizontal,
                            label: 'Mode',
                            value:
                                firewall.mode === 'log' ? (
                                    <span className="inline-flex h-5 items-center rounded-full border px-1.5 type-small">
                                        {mode}
                                    </span>
                                ) : (
                                    mode
                                ),
                        },
                        {
                            key: 'score',
                            icon: Gauge,
                            label: 'Score',
                            value: firewall.score,
                        },
                        {
                            key: 'rules',
                            icon: ListChecks,
                            label: 'Rules matched',
                            value:
                                count > 0 ? (
                                    <a
                                        href="#trace-rules"
                                        className="rounded-sm underline decoration-border-strong underline-offset-2 outline-none hover:decoration-foreground focus-visible:ring-2 focus-visible:ring-signal"
                                    >
                                        {count}
                                    </a>
                                ) : (
                                    'None'
                                ),
                        },
                    ]}
                />
            );
        }

        case 'service':
        case 'dashboard': {
            const service = node.kind === 'service' ? node.service : null;
            const appUs = time?.appUs ?? null;
            const runsOn = node.runsOn;
            const runsState = runsOn ? serverStatus[runsOn.status] : null;
            const stopped = trace.origin === 'stopped';
            const silent =
                trace.origin === 'upstream_failure' && trace.failure
                    ? SILENT[trace.failure]
                    : null;
            const rows: Row[] = [];

            if (runsOn && runsState) {
                rows.push({
                    key: 'runs',
                    icon: CornerDownRight,
                    label: 'Runs on',
                    value: (
                        <>
                            <DeviceImage
                                device={runsOn.device.kind}
                                status={deviceStatus(runsOn.status)}
                                size={24}
                            />
                            <ServerLink server={runsOn} />
                        </>
                    ),
                    after:
                        runsOn.status !== 'online' ? (
                            <Status
                                state={runsState.state}
                                label={runsState.label}
                                className="type-small"
                            />
                        ) : undefined,
                });
            }

            if (appUs !== null && index <= path.reached) {
                rows.push({
                    key: 'time',
                    icon: Timer,
                    label: 'Time here',
                    value: formatMicros(appUs),
                });
            }

            return (
                <Card
                    {...common}
                    ghost={ghost || stopped}
                    label={
                        service
                            ? `${service.name} in ${service.projectName}, ${service.environmentName}${ghost ? ', not reached' : ''}`
                            : `Dashboard${ghost ? ', not reached' : ''}`
                    }
                    picture={
                        service ? (
                            <ServiceGlyph
                                logo={service.logo ?? 'app'}
                                size="lg"
                            />
                        ) : (
                            <Tile>
                                <AppLogoIcon className="size-6" />
                            </Tile>
                        )
                    }
                    title={
                        service ? (
                            <Link
                                href={service.href}
                                className="rounded-sm outline-none hover:underline focus-visible:ring-2 focus-visible:ring-signal"
                            >
                                {service.name}
                            </Link>
                        ) : (
                            'Dashboard'
                        )
                    }
                    subtitle={
                        service
                            ? `${service.projectName} / ${service.environmentName}`
                            : 'launchroom itself'
                    }
                    mark={
                        stopped ? (
                            <Status
                                state="idle"
                                label="Stopped"
                                className="type-small"
                            />
                        ) : silent ? (
                            <Status
                                state={silent.state}
                                label={silent.word}
                                className="type-small"
                            />
                        ) : ghost ? (
                            <NotReached />
                        ) : null
                    }
                    rows={rows}
                />
            );
        }

        default:
            return (
                <Card
                    {...common}
                    ghost
                    label="No service: no domain matched"
                    picture={
                        <Tile dashed>
                            <SearchX aria-hidden="true" className="size-5" />
                        </Tile>
                    }
                    title="No service"
                    subtitle="No app at this address"
                />
            );
    }
}

/** A line between two cards, its pill, and the dot's track along it. */
function Connector({
    hop,
    columns,
    length,
    iconOnly,
    style,
    trackRef,
    dotRef,
    pulseRef,
}: {
    hop: Hop;
    columns: boolean;
    length: number;
    iconOnly: boolean;
    style?: CSSProperties;
    trackRef: (el: HTMLElement | null) => void;
    dotRef: (el: HTMLElement | null) => void;
    pulseRef?: (el: HTMLElement | null) => void;
}) {
    const failed = hop.state === 'failed';
    const travelled = hop.state === 'travelled';
    const stroke = travelled
        ? 'var(--foreground)'
        : failed
          ? 'var(--status-error)'
          : 'var(--foreground)';

    return (
        <div
            aria-hidden="true"
            className={cn(
                'relative shrink-0',
                columns ? 'self-start' : 'self-stretch',
            )}
            style={
                columns
                    ? {
                          width: length,
                          height: 0,
                          marginTop: STAGE / 2,
                          ...style,
                      }
                    : { height: length, ...style }
            }
        >
            <svg
                className="absolute overflow-visible"
                style={
                    columns
                        ? { left: 0, top: -1, width: '100%', height: 2 }
                        : {
                              left: STAGE / 2 - 1,
                              top: 0,
                              width: 2,
                              height: '100%',
                          }
                }
            >
                <line
                    x1={columns ? 0 : 1}
                    y1={columns ? 1 : 0}
                    x2={columns ? '100%' : 1}
                    y2={columns ? 1 : '100%'}
                    stroke={stroke}
                    strokeWidth={1.5}
                    strokeOpacity={travelled ? 0.55 : failed ? 0.85 : 0.22}
                    strokeDasharray={
                        travelled ? undefined : failed ? '5 4' : '3 4'
                    }
                />
            </svg>
            <span
                ref={trackRef}
                className="pointer-events-none absolute"
                style={
                    columns
                        ? { left: 0, top: 0, width: '100%', height: 0 }
                        : { left: STAGE / 2, top: 0, width: 0, height: '100%' }
                }
            >
                <span
                    ref={dotRef}
                    className="absolute -top-[2.5px] -left-[2.5px] size-[5px] rounded-full bg-foreground/60 opacity-0"
                />
            </span>
            {(hop.pill || failed) && (
                <span
                    title={hop.title ?? undefined}
                    className={cn(
                        'absolute flex h-5 items-center gap-1 rounded-full border bg-card px-2 type-mono-small whitespace-nowrap tabular-nums',
                        failed
                            ? 'border-[color-mix(in_oklab,var(--status-error)_40%,var(--border))] text-foreground'
                            : 'text-muted-foreground',
                        failed && iconOnly && 'px-1',
                    )}
                    style={
                        columns
                            ? {
                                  left: '50%',
                                  top: 0,
                                  transform: 'translate(-50%, -50%)',
                              }
                            : {
                                  left: STAGE / 2 + 14,
                                  top: '50%',
                                  transform: 'translateY(-50%)',
                              }
                    }
                >
                    {failed && (
                        <span ref={pulseRef} className="inline-flex">
                            <X
                                aria-hidden="true"
                                strokeWidth={2.5}
                                className="size-3 text-status-error"
                            />
                        </span>
                    )}
                    {failed && iconOnly ? null : hop.pill}
                </span>
            )}
        </div>
    );
}

type Leg = { hop: number; from: number; to: number; ms: number };

/**
 * Where the dot goes: along every travelled hop to the furthest card,
 * halfway into a connection that failed, else back to the card that
 * answered when that came earlier (a firewall that refused the app's
 * response, an edge that answered after its firewall).
 */
export function dotLegs(path: TracePath): Leg[] {
    const legs: Leg[] = [];

    for (let i = 0; i < path.reached; i++) {
        legs.push({ hop: i, from: 0, to: 1, ms: HOP_MS });
    }

    const failed = path.hops.findIndex((hop) => hop.state === 'failed');

    if (failed >= 0) {
        legs.push({ hop: failed, from: 0, to: 0.5, ms: HOP_MS / 2 });

        return legs;
    }

    for (let i = path.reached - 1; i >= path.end; i--) {
        legs.push({ hop: i, from: 1, to: 0, ms: HOP_MS });
    }

    return legs;
}

/**
 * The flying dot: one Web Animation per connector on a shared cycle, so
 * one dot moves at a time. Paused while the tab is hidden; none with
 * reduced motion.
 */
function useFlyingDot(
    path: TracePath,
    columns: boolean | null,
    tracks: RefObject<(HTMLElement | null)[]>,
    dots: RefObject<(HTMLElement | null)[]>,
    pulse: RefObject<HTMLElement | null>,
    layoutKey: string,
) {
    useEffect(() => {
        if (
            columns === null ||
            prefersReducedMotion() ||
            typeof Element === 'undefined' ||
            typeof Element.prototype.animate !== 'function'
        ) {
            return;
        }

        const legs = dotLegs(path);

        if (legs.length === 0) {
            return;
        }

        let t = LEAD_MS;
        const windows = legs.map((leg, i) => {
            const w = {
                ...leg,
                s: t,
                e: t + leg.ms,
                last: i === legs.length - 1,
            };
            t += leg.ms;

            return w;
        });
        const arrive = t;
        const total = t + FADE_MS + REST_MS;
        const at = (ms: number) => Math.min(1, Math.max(0, ms / total));
        const pos = (f: number) =>
            columns
                ? `translateX(${(f * 100).toFixed(2)}%)`
                : `translateY(${(f * 100).toFixed(2)}%)`;
        const animations: Animation[] = [];

        path.hops.forEach((_, hop) => {
            const own = windows.filter((w) => w.hop === hop);
            const track = tracks.current[hop];
            const dot = dots.current[hop];

            if (own.length === 0 || !track || !dot) {
                return;
            }

            const moves: Keyframe[] = [
                { offset: 0, transform: pos(own[0].from) },
            ];
            const fades: Keyframe[] = [{ offset: 0, opacity: 0 }];

            for (const w of own) {
                const edgeMs = Math.min(140, (w.e - w.s) / 4);
                moves.push(
                    {
                        offset: at(w.s),
                        transform: pos(w.from),
                        easing: DOT_EASE,
                    },
                    { offset: at(w.e), transform: pos(w.to) },
                );
                fades.push(
                    { offset: at(w.s), opacity: 0 },
                    { offset: at(w.s + edgeMs), opacity: 1 },
                );

                if (w.last) {
                    // Stops where the request ended and fades there.
                    fades.push(
                        { offset: at(w.e), opacity: 1 },
                        { offset: at(w.e + FADE_MS), opacity: 0 },
                    );
                } else {
                    fades.push(
                        { offset: at(w.e - edgeMs), opacity: 1 },
                        { offset: at(w.e), opacity: 0 },
                    );
                }
            }

            moves.push({ offset: 1, transform: pos(own[own.length - 1].to) });
            fades.push({ offset: 1, opacity: 0 });

            const options = { duration: total, iterations: Infinity };
            animations.push(track.animate(moves, options));
            animations.push(dot.animate(fades, options));
        });

        if (pulse.current) {
            animations.push(
                pulse.current.animate(
                    [
                        { offset: 0, transform: 'scale(1)' },
                        { offset: at(arrive), transform: 'scale(1)' },
                        {
                            offset: at(arrive + 160),
                            transform: 'scale(1.3)',
                            easing: 'cubic-bezier(0.2, 0.8, 0.2, 1)',
                        },
                        { offset: at(arrive + 480), transform: 'scale(1)' },
                        { offset: 1, transform: 'scale(1)' },
                    ],
                    { duration: total, iterations: Infinity },
                ),
            );
        }

        const start = document.timeline.currentTime;

        for (const animation of animations) {
            animation.startTime = start;
        }

        const onVisibility = () => {
            if (document.hidden) {
                animations.forEach((animation) => animation.pause());

                return;
            }

            const now = animations[0]?.currentTime ?? 0;
            animations.forEach((animation) => {
                animation.currentTime = now;
                animation.play();
            });
        };

        document.addEventListener('visibilitychange', onVisibility);

        return () => {
            document.removeEventListener('visibilitychange', onVisibility);
            animations.forEach((animation) => animation.cancel());
        };
        // The layout key stands for the path and the geometry.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [layoutKey, columns]);
}

function nodeWords(node: PathNode, trace: RequestTrace): string {
    switch (node.kind) {
        case 'visitor':
            return 'Visitor';
        case 'edge':
            return `${node.server.name}, ${node.both ? 'edge and app server' : 'edge server'}`;
        case 'firewall':
            return `Firewall on ${trace.edge.name}: ${firewallWords(trace) ?? ''}`;
        case 'service':
            return node.service.name;
        case 'dashboard':
            return 'Dashboard';
        default:
            return 'No service';
    }
}

/**
 * The request's path as cards on a dotted canvas (docs/23 §7): visitor,
 * edge server, its firewall, the service. Solid lines where the request
 * went, dashed beyond, red where the edge couldn't connect, with a dot
 * flying along the way it took. One column on narrow screens.
 */
export function TraceCanvas({ trace }: { trace: RequestTrace }) {
    const [ref, width] = useMeasuredWidth<HTMLDivElement>();
    const path = tracePath(trace);
    const geometry = width > 0 ? layout(path, width) : null;
    const tracks = useRef<(HTMLElement | null)[]>([]);
    const dots = useRef<(HTMLElement | null)[]>([]);
    const pulse = useRef<HTMLElement | null>(null);
    // What pulses when the dot stops: the firewall's ✕, or the ✕ of a
    // connection that failed.
    const pulseOn = path.hops.some((hop) => hop.state === 'failed')
        ? 'failed'
        : path.nodes[path.end]?.kind === 'firewall' &&
            trace.firewall?.outcome === 'blocked'
          ? 'firewall'
          : null;
    const layoutKey = geometry
        ? geometry.columns
            ? `c|${geometry.widths.join(',')}|${geometry.gaps.join(',')}|${geometry.iconOnly}`
            : `s|${geometry.width}`
        : '';

    useFlyingDot(
        path,
        geometry ? geometry.columns : null,
        tracks,
        dots,
        pulse,
        `${trace.requestId}|${layoutKey}`,
    );

    const pulseRef = (el: HTMLElement | null) => {
        pulse.current = el;
    };

    return (
        <div
            ref={ref}
            className="trace-canvas relative w-full min-w-0"
            style={{ minHeight: geometry ? undefined : 260 }}
        >
            {geometry && (
                <div
                    className={cn(
                        'flex w-full',
                        geometry.columns
                            ? 'flex-row items-start justify-center'
                            : 'flex-col items-center',
                    )}
                    style={{
                        padding: geometry.columns
                            ? `32px ${PAD_ROW}px`
                            : `20px ${PAD_STACK}px`,
                    }}
                >
                    {path.nodes.map((node, i) => {
                        const hop = path.hops[i];
                        const cardWidth = geometry.columns
                            ? geometry.widths[i]
                            : geometry.width;

                        return (
                            <div
                                key={node.id}
                                className={cn(
                                    'flex',
                                    geometry.columns
                                        ? 'flex-row items-start'
                                        : 'flex-col',
                                )}
                                style={
                                    geometry.columns
                                        ? undefined
                                        : { width: geometry.width }
                                }
                            >
                                <NodeCard
                                    node={node}
                                    index={i}
                                    trace={trace}
                                    path={path}
                                    columns={geometry.columns}
                                    width={cardWidth}
                                    pulseRef={
                                        pulseOn === 'firewall'
                                            ? pulseRef
                                            : undefined
                                    }
                                />
                                {hop && (
                                    <Connector
                                        hop={hop}
                                        columns={geometry.columns}
                                        length={
                                            geometry.columns
                                                ? geometry.gaps[i]
                                                : hop.pill ||
                                                    hop.state === 'failed'
                                                  ? 52
                                                  : 36
                                        }
                                        iconOnly={
                                            geometry.columns &&
                                            geometry.iconOnly
                                        }
                                        trackRef={(el) => {
                                            tracks.current[i] = el;
                                        }}
                                        dotRef={(el) => {
                                            dots.current[i] = el;
                                        }}
                                        pulseRef={
                                            pulseOn === 'failed'
                                                ? pulseRef
                                                : undefined
                                        }
                                    />
                                )}
                            </div>
                        );
                    })}
                </div>
            )}
            <ol className="sr-only">
                {path.nodes.map((node, i) => {
                    const hop = path.hops[i];
                    const answer = path.answers[i];

                    return (
                        <li key={node.id}>
                            {nodeWords(node, trace)}
                            {i > path.reached && ', not reached'}
                            {answer &&
                                `. Answered ${answer.words}${answer.note ? `: ${answer.note}` : ''}`}
                            {hop?.title && `. Then ${hop.title}`}
                        </li>
                    );
                })}
            </ol>
        </div>
    );
}
