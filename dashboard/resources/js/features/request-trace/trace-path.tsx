import { Link } from '@inertiajs/react';
import {
    Globe,
    LayoutDashboard,
    SearchX,
    ShieldAlert,
    ShieldCheck,
    ShieldX,
    X,
} from 'lucide-react';
import type { CSSProperties, ReactNode } from 'react';
import { REQUEST_CLASS } from '@/components/charts/request-breakdown';
import { useMeasuredWidth } from '@/components/charts/use-measure';
import { deviceStatus } from '@/features/servers/format';
import { DeviceImage } from '@/components/devices/device-image';
import { ServiceGlyph } from '@/components/service-glyph';
import { Status, serverStatus } from '@/components/status';
import { labelOf, vocabulary } from '@/lib/vocabulary';
import { cn } from '@/lib/utils';
import { firewallWords, tracePath } from './trace-model';
import type {
    Hop,
    Outcome,
    PathNode,
    RequestTrace,
    TracePath,
} from './trace-model';
import './request-trace.css';

type Box = { x: number; y: number; w: number; h: number };
type TraceFirewallData = App.Data.Traces.TraceFirewallData;
type Size = 'visitor' | 'server' | 'target';

/** Node sizes side by side: as wide as there is room for, down to the minimum. */
const WIDE: Record<Size, number> = { visitor: 140, server: 188, target: 188 };
const NARROW: Record<Size, number> = { visitor: 112, server: 144, target: 148 };
const TALL: Record<Size, number> = { visitor: 120, server: 184, target: 156 };
/** Stacked (phones): one column, picture left. */
const ROW_H: Record<Size, number> = { visitor: 68, server: 84, target: 72 };
const MARKER_H = 32;
const PAD = 24;
const GATE = 28;
const MAX_GAP = 168;
const STEP = 260;

const ROLE_WORDS = {
    edge: 'Edge server',
    app: 'App server',
    both: 'Edge and app server',
} as const;

function sizeOf(node: PathNode): Size {
    return node.kind === 'visitor'
        ? 'visitor'
        : node.kind === 'server'
          ? 'server'
          : 'target';
}

function pillWidth(text: string): number {
    return Math.round(text.length * 7.3 + 22);
}

/**
 * The narrowest gap a hop needs: its pill (a failed hop's ✕ alone when
 * `iconOnly`), and the firewall gate on the edge's side.
 */
function minGap(hop: Hop, gate: boolean, iconOnly: boolean): number {
    const failed = hop.state === 'failed';
    const pill =
        failed && iconOnly
            ? 28
            : hop.pill
              ? pillWidth(hop.pill) + (failed ? 16 : 0)
              : 0;

    return Math.max(56, pill > 0 ? pill + 28 : 0) + (gate ? GATE + 8 : 0);
}

type Layout = {
    columns: boolean;
    /** A failed hop shows only its ✕ (the words are in the tooltip and the facts). */
    iconOnly: boolean;
    boxes: Box[];
    height: number;
};

function layout(
    path: TracePath,
    width: number,
    gateOn: boolean,
): Layout | null {
    if (width <= 0) {
        return null;
    }

    const sizes = path.nodes.map(sizeOf);
    const available = width - PAD * 2;
    const wide = sizes.reduce((sum, s) => sum + WIDE[s], 0);
    const narrow = sizes.reduce((sum, s) => sum + NARROW[s], 0);

    for (const iconOnly of [false, true]) {
        const gaps = path.hops.map((hop) =>
            minGap(hop, gateOn && hop.from === path.edge, iconOnly),
        );
        const gapSum = gaps.reduce((a, b) => a + b, 0);

        if (narrow + gapSum > available) {
            continue;
        }

        // Shrink the nodes towards their minimum, then spread the gaps.
        const t = Math.min(
            1,
            Math.max(0, (available - gapSum - narrow) / (wide - narrow)),
        );
        const widths = sizes.map((s) =>
            Math.round(NARROW[s] + (WIDE[s] - NARROW[s]) * t),
        );
        const used = widths.reduce((a, b) => a + b, 0);
        const spare = Math.max(0, available - used - gapSum);
        const extra = gaps.length > 0 ? spare / gaps.length : 0;
        // Never below what a hop needs (its pill, the gate), never
        // stretched past MAX_GAP for looks.
        const spread = gaps.map((gap) =>
            Math.max(gap, Math.min(MAX_GAP, gap + extra)),
        );
        const total = used + spread.reduce((a, b) => a + b, 0);
        const tallest = Math.max(...sizes.map((s) => TALL[s]));
        const height = tallest + PAD * 2;
        const centre = height / 2;
        let x = PAD + (available - total) / 2;

        const boxes = sizes.map((s, i) => {
            const box = {
                x: Math.round(x),
                y: Math.round(centre - TALL[s] / 2),
                w: widths[i],
                h: TALL[s],
            };
            x += widths[i] + (spread[i] ?? 0);

            return box;
        });

        return { columns: true, iconOnly, boxes, height };
    }

    const w = Math.min(available + PAD, 400);
    const left = Math.round((width - w) / 2);
    let y = 16;
    const boxes = sizes.map((s, i) => {
        const h = ROW_H[s] + (i === path.end ? MARKER_H : 0);
        const hop = path.hops[i];
        const box = { x: left, y, w, h };
        y +=
            h +
            (hop
                ? (hop.pill ? 52 : 36) +
                  (gateOn && hop.from === path.edge ? GATE : 0)
                : 0);

        return box;
    });

    return { columns: false, iconOnly: false, boxes, height: y + 16 };
}

/** The x of the vertical line in the stacked layout: under the pictures. */
function railX(box: Box): number {
    return box.x + 44;
}

function hopPath(columns: boolean, a: Box, b: Box): string {
    if (!columns) {
        const x = railX(a);

        return `M${x} ${a.y + a.h}L${x} ${b.y}`;
    }

    const x1 = a.x + a.w;
    const y1 = a.y + a.h / 2;
    const x2 = b.x;
    const y2 = b.y + b.h / 2;
    const mid = (x1 + x2) / 2;

    return `M${x1} ${y1}C${mid} ${y1} ${mid} ${y2} ${x2} ${y2}`;
}

/** Where the gate sits: on the edge server's far side, on the line. */
function gatePoint(columns: boolean, box: Box): { x: number; y: number } {
    return columns
        ? { x: box.x + box.w, y: box.y + box.h / 2 }
        : { x: railX(box), y: box.y + box.h };
}

/** Where a hop's pill sits: the middle of the line, past the gate. */
function pillPoint(
    columns: boolean,
    a: Box,
    b: Box,
    gate: boolean,
): { x: number; y: number } {
    const skip = gate ? GATE / 2 + 4 : 0;

    if (columns) {
        return {
            x: (a.x + a.w + skip + b.x) / 2,
            y: a.y + a.h / 2,
        };
    }

    return { x: railX(a) + 12, y: (a.y + a.h + skip + b.y) / 2 };
}

function delay(ms: number): CSSProperties {
    return { '--trace-delay': `${Math.round(ms)}ms` } as CSSProperties;
}

/**
 * When each part appears: the hops draw one after another (300 ms each,
 * overlapping a little), a node fades in as its line arrives, and what
 * wasn't reached and the outcome marker follow.
 */
function timeline(path: TracePath) {
    const hopAt = (i: number) => 80 + i * STEP;
    const nodeAt = (i: number) => (i === 0 ? 0 : hopAt(i - 1) + 200);
    const settled = nodeAt(path.reached) + 120;

    return { hopAt, nodeAt, settled, marker: settled + 80 };
}

export function OutcomeMarker({
    outcome,
    className,
    style,
}: {
    outcome: Outcome;
    className?: string;
    style?: CSSProperties;
}) {
    const cls = REQUEST_CLASS[outcome.cls];

    return (
        <span
            className={cn(
                'inline-flex h-6 max-w-full min-w-0 items-center gap-1.5 rounded-full border bg-card px-2 type-label',
                className,
            )}
            style={style}
        >
            <Swatch color={cls.color} hatched={cls.hatched === true} />
            <span className="truncate">{outcome.word}</span>
        </span>
    );
}

export function Swatch({
    color,
    hatched,
    className,
}: {
    color: string;
    hatched: boolean;
    className?: string;
}) {
    return (
        <span
            aria-hidden="true"
            className={cn('size-2.5 shrink-0 rounded-[3px]', className)}
            style={{
                backgroundColor: color,
                backgroundImage: hatched
                    ? 'repeating-linear-gradient(45deg, transparent 0 2px, color-mix(in oklab, var(--card) 55%, transparent) 2px 3.5px)'
                    : undefined,
            }}
        />
    );
}

function Gate({
    firewall,
    words,
}: {
    firewall: TraceFirewallData;
    words: string;
}) {
    const mode = labelOf(vocabulary.Security.WafMode, firewall.mode) ?? '';
    const Icon =
        firewall.outcome === 'blocked'
            ? ShieldX
            : firewall.outcome === 'detected'
              ? ShieldAlert
              : ShieldCheck;
    const tone =
        firewall.outcome === 'blocked'
            ? REQUEST_CLASS.blocked
            : firewall.outcome === 'detected'
              ? REQUEST_CLASS.detected
              : null;
    const label = `Firewall, ${mode}: ${words}`;
    const body = (
        <>
            <Icon aria-hidden="true" className="size-4" />
            <span className="sr-only">{label}</span>
        </>
    );
    const className =
        'flex size-full items-center justify-center rounded-md border bg-card text-muted-foreground outline-none focus-visible:ring-2 focus-visible:ring-signal';
    const style: CSSProperties | undefined = tone
        ? {
              color: firewall.outcome === 'blocked' ? tone.color : undefined,
              borderColor: `color-mix(in oklab, ${tone.color} 45%, var(--border))`,
              backgroundImage: `repeating-linear-gradient(45deg, transparent 0 3px, color-mix(in oklab, ${tone.color} 16%, transparent) 3px 5px)`,
          }
        : undefined;

    return firewall.rules.length > 0 ? (
        <a
            href="#trace-rules"
            title={label}
            className={className}
            style={style}
        >
            {body}
        </a>
    ) : (
        <span title={label} className={className} style={style}>
            {body}
        </span>
    );
}

function Frame({
    href,
    label,
    dim,
    dashed,
    columns,
    children,
}: {
    href?: string | null;
    label: string;
    dim: boolean;
    dashed?: boolean;
    columns: boolean;
    children: ReactNode;
}) {
    const className = cn(
        'flex size-full min-w-0 rounded-xl border bg-card shadow-card transition-[border-color,opacity] duration-fast',
        columns
            ? 'flex-col items-center justify-center gap-1 px-3 py-3 text-center'
            : 'items-center gap-3 py-2 pr-3 pl-3',
        dashed && 'border-dashed bg-transparent shadow-none',
        dim && 'opacity-50',
    );

    if (href) {
        return (
            <Link
                href={href}
                aria-label={label}
                className={cn(
                    className,
                    'outline-none hover:border-border-strong focus-visible:ring-2 focus-visible:ring-signal',
                )}
            >
                {children}
            </Link>
        );
    }

    return (
        <div className={className} aria-label={label} role="group">
            {children}
        </div>
    );
}

/** The picture column: centred over the text, or a fixed column on phones. */
function Picture({
    columns,
    children,
}: {
    columns: boolean;
    children: ReactNode;
}) {
    return (
        <span
            className={cn(
                'flex shrink-0 items-center justify-center',
                columns ? 'mb-1' : 'w-16',
            )}
        >
            {children}
        </span>
    );
}

function Words({
    columns,
    children,
}: {
    columns: boolean;
    children: ReactNode;
}) {
    return (
        <span
            className={cn(
                'flex max-w-full min-w-0 flex-col gap-0.5',
                columns ? 'items-center' : 'flex-1 items-start',
            )}
        >
            {children}
        </span>
    );
}

function NodeCard({
    node,
    trace,
    path,
    index,
    box,
    columns,
    markerDelay,
}: {
    node: PathNode;
    trace: RequestTrace;
    path: TracePath;
    index: number;
    box: Box;
    columns: boolean;
    markerDelay: number;
}) {
    const dim = index > path.reached;
    const marker =
        index === path.end ? (
            <OutcomeMarker
                outcome={path.outcome}
                className={cn('trace-enter', columns ? 'mt-1.5' : 'mt-1')}
                style={delay(markerDelay)}
            />
        ) : null;
    const notReached = dim ? (
        <span className="type-small text-muted-foreground">Not reached</span>
    ) : null;

    switch (node.kind) {
        case 'visitor':
            return (
                <Frame label="Visitor" dim={false} columns={columns}>
                    <Picture columns={columns}>
                        <span className="flex size-10 items-center justify-center rounded-full border bg-recessed text-muted-foreground">
                            <Globe aria-hidden="true" className="size-4" />
                        </span>
                    </Picture>
                    <Words columns={columns}>
                        <span className="type-label">Visitor</span>
                        {(trace.method || trace.protocol) && (
                            <span className="type-small text-muted-foreground">
                                {trace.method && (
                                    <span className="type-mono-small text-foreground">
                                        {trace.method}
                                    </span>
                                )}
                                {trace.method && trace.protocol && ' over '}
                                {trace.protocol}
                            </span>
                        )}
                    </Words>
                </Frame>
            );

        case 'server': {
            const { server } = node;
            const state = serverStatus[server.status];
            const role = ROLE_WORDS[node.role];
            const deviceSize = columns ? Math.min(136, box.w - 28) : 60;

            return (
                <Frame
                    href={server.href}
                    label={`${server.name}, ${role.toLowerCase()}${dim ? ', not reached' : ''}`}
                    dim={dim}
                    columns={columns}
                >
                    <Picture columns={columns}>
                        <DeviceImage
                            device={server.device.kind}
                            status={deviceStatus(server.status)}
                            size={deviceSize}
                        />
                    </Picture>
                    <Words columns={columns}>
                        <span
                            className="max-w-full truncate type-label"
                            title={server.name}
                        >
                            {server.name}
                        </span>
                        <span className="type-small text-muted-foreground">
                            {role}
                        </span>
                        {server.status !== 'online' && (
                            <Status
                                state={state.state}
                                label={state.label}
                                className="type-small"
                            />
                        )}
                        {notReached}
                        {marker}
                    </Words>
                </Frame>
            );
        }

        case 'service': {
            const { service } = node;

            return (
                <Frame
                    href={service.href}
                    label={`${service.name} in ${service.projectName}, ${service.environmentName}${dim ? ', not reached' : ''}`}
                    dim={dim}
                    columns={columns}
                >
                    <Picture columns={columns}>
                        <ServiceGlyph logo={service.logo ?? 'app'} size="md" />
                    </Picture>
                    <Words columns={columns}>
                        <span
                            className="max-w-full truncate type-label"
                            title={service.name}
                        >
                            {service.name}
                        </span>
                        <span className="max-w-full truncate type-small text-muted-foreground">
                            {service.projectName}, {service.environmentName}
                        </span>
                        {trace.origin === 'stopped' ? (
                            <Status
                                state="idle"
                                label="Stopped"
                                className="type-small"
                            />
                        ) : (
                            notReached
                        )}
                        {marker}
                    </Words>
                </Frame>
            );
        }

        case 'dashboard':
            return (
                <Frame
                    label={`Dashboard${dim ? ', not reached' : ''}`}
                    dim={dim}
                    columns={columns}
                >
                    <Picture columns={columns}>
                        <span className="flex size-8 items-center justify-center rounded-lg border bg-recessed text-muted-foreground">
                            <LayoutDashboard
                                aria-hidden="true"
                                className="size-4"
                            />
                        </span>
                    </Picture>
                    <Words columns={columns}>
                        <span className="type-label">Dashboard</span>
                        <span className="type-small text-muted-foreground">
                            launchroom itself
                        </span>
                        {notReached}
                        {marker}
                    </Words>
                </Frame>
            );

        default:
            return (
                <Frame
                    label="No service: no domain matched"
                    dim={false}
                    dashed
                    columns={columns}
                >
                    <Picture columns={columns}>
                        <span className="flex size-8 items-center justify-center text-faint">
                            <SearchX aria-hidden="true" className="size-5" />
                        </span>
                    </Picture>
                    <Words columns={columns}>
                        <span className="type-label text-muted-foreground">
                            No service
                        </span>
                        <span className="type-small text-muted-foreground">
                            No domain matched
                        </span>
                    </Words>
                </Frame>
            );
    }
}

function nodeWords(node: PathNode): string {
    switch (node.kind) {
        case 'visitor':
            return 'Visitor';
        case 'server':
            return `${node.server.name}, ${ROLE_WORDS[node.role].toLowerCase()}`;
        case 'service':
            return node.service.name;
        case 'dashboard':
            return 'Dashboard';
        default:
            return 'No service';
    }
}

/**
 * The request's path drawn left to right (docs/23 §7): visitor, edge
 * server with the firewall as a gate on its far side, app server, service.
 * Hops are curves with timing pills, drawn on once; where the request
 * ended carries the outcome's colour and word, and what came after is
 * dashed and dimmed. Stacks into one column on narrow screens.
 */
export function TracePathDiagram({ trace }: { trace: RequestTrace }) {
    const [ref, width] = useMeasuredWidth<HTMLDivElement>();
    const path = tracePath(trace);
    const gateOn = trace.firewall !== null;
    const geometry = layout(path, width, gateOn);
    const time = timeline(path);
    const fw = firewallWords(trace);

    return (
        <div className="flex flex-col">
            <div
                ref={ref}
                className="relative w-full"
                style={{ height: geometry?.height ?? 232 }}
            >
                {geometry && (
                    <Drawing
                        trace={trace}
                        path={path}
                        geometry={geometry}
                        width={width}
                        time={time}
                        gate={
                            trace.firewall && fw
                                ? { firewall: trace.firewall, words: fw }
                                : null
                        }
                    />
                )}
            </div>
            <ol className="sr-only">
                {path.nodes.map((node, i) => {
                    const hop = path.hops[i];

                    return (
                        <li key={node.id + i}>
                            {nodeWords(node)}
                            {i > path.reached && ', not reached'}
                            {i === path.edge && fw && `. Firewall: ${fw}`}
                            {i === path.end &&
                                `. Answered: ${path.outcome.word}`}
                            {hop?.title && `. Then ${hop.title}`}
                        </li>
                    );
                })}
            </ol>
        </div>
    );
}

function Drawing({
    trace,
    path,
    geometry,
    width,
    time,
    gate,
}: {
    trace: RequestTrace;
    path: TracePath;
    geometry: Layout;
    width: number;
    time: ReturnType<typeof timeline>;
    gate: { firewall: TraceFirewallData; words: string } | null;
}) {
    const { columns, iconOnly, boxes, height } = geometry;
    const edgeBox = boxes[path.edge];
    const gateAt = gatePoint(columns, edgeBox);

    return (
        <>
            <svg
                width={width}
                height={height}
                aria-hidden="true"
                className="absolute inset-0 block overflow-visible"
            >
                {path.hops.map((hop) => {
                    const d = hopPath(columns, boxes[hop.from], boxes[hop.to]);

                    if (hop.state === 'reached') {
                        return (
                            <path
                                key={hop.to}
                                d={d}
                                pathLength={1}
                                fill="none"
                                strokeWidth={1.5}
                                strokeLinecap="round"
                                className="trace-draw"
                                style={{
                                    stroke: 'var(--foreground)',
                                    opacity: 0.75,
                                    ...delay(time.hopAt(hop.from)),
                                }}
                            />
                        );
                    }

                    return (
                        <g
                            key={hop.to}
                            className="trace-fade"
                            style={delay(time.settled)}
                        >
                            <path
                                d={d}
                                fill="none"
                                strokeWidth={1.5}
                                strokeLinecap="round"
                                strokeDasharray={
                                    hop.state === 'failed' ? '5 4' : '3 5'
                                }
                                style={{
                                    stroke:
                                        hop.state === 'failed'
                                            ? 'var(--status-error)'
                                            : 'var(--foreground)',
                                    opacity: hop.state === 'failed' ? 0.9 : 0.3,
                                }}
                            />
                        </g>
                    );
                })}
            </svg>

            {path.hops.map((hop) => {
                if (!hop.pill && hop.state !== 'failed') {
                    return null;
                }

                const at = pillPoint(
                    columns,
                    boxes[hop.from],
                    boxes[hop.to],
                    gate !== null && hop.from === path.edge,
                );
                const failed = hop.state === 'failed';

                return (
                    <div
                        key={`pill-${hop.to}`}
                        aria-hidden="true"
                        className="absolute"
                        style={{
                            left: at.x,
                            top: at.y,
                            transform: columns
                                ? 'translate(-50%, -50%)'
                                : 'translate(0, -50%)',
                        }}
                    >
                        <span
                            title={hop.title ?? undefined}
                            className={cn(
                                'trace-enter flex h-5 items-center gap-1 rounded-full border bg-card px-2 type-mono-small whitespace-nowrap tabular-nums',
                                failed
                                    ? 'border-[color-mix(in_oklab,var(--status-error)_40%,var(--border))] text-foreground'
                                    : 'text-muted-foreground',
                            )}
                            style={delay(
                                failed
                                    ? time.settled
                                    : time.hopAt(hop.from) + 150,
                            )}
                        >
                            {failed && (
                                <X
                                    aria-hidden="true"
                                    strokeWidth={2.5}
                                    className="size-3 text-status-error"
                                />
                            )}
                            {failed && iconOnly ? null : hop.pill}
                        </span>
                    </div>
                );
            })}

            {path.nodes.map((node, i) => {
                const box = boxes[i];

                return (
                    <div
                        key={node.id + i}
                        className={cn(
                            'absolute',
                            i > path.reached ? 'trace-fade' : 'trace-enter',
                        )}
                        style={{
                            left: box.x,
                            top: box.y,
                            width: box.w,
                            height: box.h,
                            ...delay(
                                i > path.reached
                                    ? time.settled
                                    : time.nodeAt(i),
                            ),
                        }}
                    >
                        <NodeCard
                            node={node}
                            trace={trace}
                            path={path}
                            index={i}
                            box={box}
                            columns={columns}
                            markerDelay={time.marker}
                        />
                    </div>
                );
            })}

            {gate && (
                <div
                    className="absolute flex flex-col items-center"
                    style={{
                        left: gateAt.x,
                        top: gateAt.y,
                        width: GATE,
                        transform: 'translate(-50%, -50%)',
                    }}
                >
                    <div
                        className="trace-enter"
                        style={{
                            width: GATE,
                            height: GATE,
                            ...delay(time.nodeAt(path.edge) + 120),
                        }}
                    >
                        <Gate firewall={gate.firewall} words={gate.words} />
                    </div>
                    <span
                        aria-hidden="true"
                        className={cn(
                            'trace-fade absolute type-small whitespace-nowrap text-muted-foreground',
                            columns
                                ? 'top-[calc(100%+4px)]'
                                : 'top-1/2 left-[calc(100%+8px)] -translate-y-1/2',
                        )}
                        style={delay(time.nodeAt(path.edge) + 160)}
                    >
                        Firewall
                    </span>
                </div>
            )}
        </>
    );
}
