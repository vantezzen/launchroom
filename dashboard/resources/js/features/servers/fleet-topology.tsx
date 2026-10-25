import { Link } from '@inertiajs/react';
import { Globe, LayoutDashboard, Package } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import { useMeasuredWidth } from '@/components/charts/use-measure';
import { DeviceImage } from '@/components/devices/device-image';
import { Status, serverStatus } from '@/components/status';
import { deviceKind, deviceStatus } from '@/features/servers/format';
import {
    formatLatency,
    formatRate,
    linkLabel,
} from '@/features/servers/link-format';
import { roleIcons, roleTitles } from '@/features/servers/server-status';
import { cn } from '@/lib/utils';
import { show } from '@/routes/servers';

type Server = App.Data.Servers.ServerData;
type MeshLink = App.Data.Servers.MeshLinkData;

/** What a node does, as icons: its roles, plus the dashboard and image registry on the control plane. */
type Duty = { key: string; icon: LucideIcon; label: string };

type Node =
    | { kind: 'internet'; id: 'internet' }
    | { kind: 'server'; id: string; server: Server; duties: Duty[] }
    | { kind: 'dashboard'; id: 'dashboard'; duties: Duty[] };

type Edge = {
    key: string;
    from: string;
    to: string;
    kind: 'public' | 'direct' | 'relay' | 'idle';
    label: string;
    /** Measured quality shown on the line, e.g. "12 ms, 480 Mbit/s". */
    measured?: string | null;
    /** The round trip alone, for lines without room for the whole label. */
    short?: string | null;
};

type Pt = { x: number; y: number };
type Curve = { p0: Pt; p1: Pt; p2: Pt; p3: Pt };

function bezier({ p0, p1, p2, p3 }: Curve, t: number): Pt {
    const u = 1 - t;
    const a = u * u * u;
    const b = 3 * u * u * t;
    const c = 3 * u * t * t;
    const d = t * t * t;

    return {
        x: a * p0.x + b * p1.x + c * p2.x + d * p3.x,
        y: a * p0.y + b * p1.y + c * p2.y + d * p3.y,
    };
}

const LABEL_H = 20;

function overlaps(a: Box, b: Box, pad = 4): boolean {
    return (
        a.x < b.x + b.w + pad &&
        b.x < a.x + a.w + pad &&
        a.y < b.y + b.h + pad &&
        b.y < a.y + a.h + pad
    );
}

type Box = { x: number; y: number; w: number; h: number };

const NODE_W = 216;
const NODE_H = 68;
const INTERNET_W = 116;
const GAP_Y = 14;
const ROW_GAP = 26;
const GUTTER = 28;
const MIN_NODE_W = 172;

const DASHBOARD: Duty = {
    key: 'dashboard',
    icon: LayoutDashboard,
    label: 'Runs this dashboard',
};
const REGISTRY: Duty = {
    key: 'registry',
    icon: Package,
    label: 'Stores built images',
};

function duties(server: Server): Duty[] {
    const out: Duty[] = (['edge', 'runtime', 'build'] as const)
        .filter((role) => server.roles.includes(role))
        .map((role) => ({
            key: role,
            icon: roleIcons[role],
            label: roleTitles[role],
        }));

    // The image registry runs next to the dashboard (deploy/control-plane), or on a server with the registry role.
    if (server.isControlPlane || server.roles.includes('registry')) {
        out.push(REGISTRY);
    }

    if (server.isControlPlane) {
        out.unshift(DASHBOARD);
    }

    return out;
}

const pathWords: Record<'direct' | 'relay' | 'idle', string> = {
    direct: 'Direct',
    relay: 'Relayed',
    idle: 'Idle',
};

/**
 * Who does what in the system (Servers page overview): public traffic from
 * the internet to the edges, the servers that run apps and build images,
 * the dashboard and image registry, and the private-network links between
 * servers as their launchroomd reported them (solid direct, dashed
 * relayed, dotted idle), labelled with what launchroomd measured: the round
 * trip and the slower direction's transfer rate ("12 ms, 480 Mbit/s",
 * both directions on hover). Status per node is the device LED and one
 * word. Tiers run left to right (internet → edges → the rest) and stack on
 * narrow screens, where the labels move to the line's tooltip.
 */
export function FleetTopology({
    servers,
    links,
}: {
    servers: Server[];
    links: MeshLink[];
}) {
    const [ref, width] = useMeasuredWidth<HTMLDivElement>();
    const [hover, setHover] = useState<string | null>(null);

    const active = servers.filter((server) => server.status !== 'revoked');
    const order = (a: Server, b: Server) =>
        Number(b.isControlPlane) - Number(a.isControlPlane) ||
        a.name.localeCompare(b.name);
    const edges = active.filter((s) => s.roles.includes('edge')).sort(order);
    const rest = active.filter((s) => !s.roles.includes('edge')).sort(order);
    const toNode = (server: Server): Node => ({
        kind: 'server',
        id: server.id,
        server,
        duties: duties(server),
    });

    const hasControlPlane = active.some((server) => server.isControlPlane);
    const internet: Node = { kind: 'internet', id: 'internet' };
    const tiers: Node[][] = [
        edges.length > 0 ? [internet] : [],
        edges.map(toNode),
        [
            ...rest.map(toNode),
            ...(!hasControlPlane && active.length > 0
                ? [
                      {
                          kind: 'dashboard' as const,
                          id: 'dashboard' as const,
                          duties: [DASHBOARD, REGISTRY],
                      },
                  ]
                : []),
        ],
    ].filter((tier) => tier.length > 0);

    const nameOf = (id: string) =>
        active.find((server) => server.id === id)?.name ?? 'another server';

    const lines: Edge[] = [
        ...edges.map((server) => ({
            key: `internet-${server.id}`,
            from: 'internet',
            to: server.id,
            kind: 'public' as const,
            label: `Public traffic reaches ${server.name}`,
        })),
        ...links.map((link) => ({
            key: `${link.from}-${link.to}`,
            from: link.from,
            to: link.to,
            kind: link.path,
            label: [
                `${pathWords[link.path]}${link.relay ? ` via ${link.relay}` : ''}`,
                link.latencyMs !== null
                    ? `${formatLatency(link.latencyMs)} round trip`
                    : null,
                ...link.speeds.map(
                    (speed) =>
                        `${nameOf(speed.from)} to ${nameOf(speed.to)} ${formatRate(speed.mbps)}`,
                ),
            ]
                .filter(Boolean)
                .join(', '),
            measured: linkLabel(link),
            short:
                link.latencyMs !== null ? formatLatency(link.latencyMs) : null,
        })),
        // Another team's dashboard server: what we know is each server's path to it.
        ...(!hasControlPlane
            ? active.flatMap((server) =>
                  server.mesh?.pathToControlPlane
                      ? [
                            {
                                key: `${server.id}-dashboard`,
                                from: server.id,
                                to: 'dashboard',
                                kind: server.mesh.pathToControlPlane,
                                label: pathWords[
                                    server.mesh.pathToControlPlane
                                ],
                            },
                        ]
                      : [],
              )
            : []),
    ];

    const names = new Map<string, string>([
        ['internet', 'Internet'],
        ['dashboard', 'Dashboard'],
        ...active.map((server) => [server.id, server.name] as [string, string]),
    ]);

    // Layout: columns when every tier fits side by side, else one stacked column.
    const available = width - GUTTER * 2;
    const hasInternet = tiers[0]?.[0]?.kind === 'internet';
    const serverTiers = tiers.length - (hasInternet ? 1 : 0);
    const minGap = 56;
    const serverW = Math.min(
        NODE_W,
        (available -
            (hasInternet ? INTERNET_W : 0) -
            (tiers.length - 1) * minGap) /
            Math.max(1, serverTiers),
    );
    const columns = width > 0 && serverW >= MIN_NODE_W;
    const boxes = new Map<string, Box>();
    let height = 0;

    if (columns) {
        const tallest = Math.max(...tiers.map((tier) => tier.length));
        height = tallest * NODE_H + (tallest - 1) * GAP_Y + 16;
        const widths = tiers.map((tier) =>
            tier[0]?.kind === 'internet' ? INTERNET_W : serverW,
        );
        const used = widths.reduce((sum, w) => sum + w, 0);
        // One tier (a single server, no edge) sits centred; more spread out evenly.
        const gap =
            tiers.length > 1
                ? Math.min(128, (available - used) / (tiers.length - 1))
                : 0;
        let x = GUTTER + (available - used - gap * (tiers.length - 1)) / 2;

        tiers.forEach((tier, t) => {
            const blockH = tier.length * NODE_H + (tier.length - 1) * GAP_Y;
            const top = (height - blockH) / 2;

            tier.forEach((node, i) => {
                boxes.set(node.id, {
                    x: Math.round(x),
                    y: Math.round(top + i * (NODE_H + GAP_Y)),
                    w: widths[t],
                    h: NODE_H,
                });
            });
            x += widths[t] + gap;
        });
    } else if (width > 0) {
        let y = 0;

        tiers.forEach((tier, t) => {
            tier.forEach((node, i) => {
                const w =
                    node.kind === 'internet'
                        ? INTERNET_W
                        : Math.min(width - GUTTER, 360);
                boxes.set(node.id, { x: GUTTER, y, w, h: NODE_H });
                y +=
                    NODE_H +
                    (i === tier.length - 1 && t < tiers.length - 1
                        ? ROW_GAP
                        : GAP_Y);
            });
        });
        height = y - GAP_Y;
    }

    const path = (edge: Edge): string | null => {
        const a = boxes.get(edge.from);
        const b = boxes.get(edge.to);

        if (!a || !b) {
            return null;
        }

        if (!columns) {
            // Stacked: arcs in the left gutter between the nodes' left edges.
            const ay = a.y + a.h / 2;
            const by = b.y + b.h / 2;
            const bend = Math.min(GUTTER - 4, 10 + Math.abs(by - ay) / 12);

            return `M${a.x} ${ay}C${a.x - bend} ${ay} ${b.x - bend} ${by} ${b.x} ${by}`;
        }

        if (Math.abs(a.x - b.x) < 1) {
            // Same column: an arc on the right side.
            const x = a.x + a.w;
            const ay = a.y + a.h / 2;
            const by = b.y + b.h / 2;
            const bend = 18 + Math.abs(by - ay) / 6;

            return `M${x} ${ay}C${x + bend} ${ay} ${x + bend} ${by} ${x} ${by}`;
        }

        const [l, r] = a.x < b.x ? [a, b] : [b, a];
        const x1 = l.x + l.w;
        const y1 = l.y + l.h / 2;
        const x2 = r.x;
        const y2 = r.y + r.h / 2;
        const mid = (x1 + x2) / 2;

        return `M${x1} ${y1}C${mid} ${y1} ${mid} ${y2} ${x2} ${y2}`;
    };

    // Where a line's measured label sits: on the curve, nudged along it
    // (t from the middle outwards) until it overlaps no node or other label.
    const curve = (edge: Edge): Curve | null => {
        const a = boxes.get(edge.from);
        const b = boxes.get(edge.to);

        if (!a || !b || !columns) {
            return null;
        }

        if (Math.abs(a.x - b.x) < 1) {
            const x = a.x + a.w;
            const ay = a.y + a.h / 2;
            const by = b.y + b.h / 2;
            const bend = 18 + Math.abs(by - ay) / 6;

            return {
                p0: { x, y: ay },
                p1: { x: x + bend, y: ay },
                p2: { x: x + bend, y: by },
                p3: { x, y: by },
            };
        }

        const [l, r] = a.x < b.x ? [a, b] : [b, a];
        const x1 = l.x + l.w;
        const y1 = l.y + l.h / 2;
        const x2 = r.x;
        const y2 = r.y + r.h / 2;
        const mid = (x1 + x2) / 2;

        return {
            p0: { x: x1, y: y1 },
            p1: { x: mid, y: y1 },
            p2: { x: mid, y: y2 },
            p3: { x: x2, y: y2 },
        };
    };

    const labels: { line: Edge; box: Box; text: string }[] = [];

    for (const line of lines) {
        const c = line.measured ? curve(line) : null;

        if (!c) {
            continue;
        }

        const place = (text: string): boolean => {
            const w = Math.round(text.length * 7.3 + 18);

            for (const t of [0.5, 0.38, 0.62, 0.28, 0.72, 0.2, 0.8]) {
                const p = bezier(c, t);
                const box = {
                    x: Math.round(p.x - w / 2),
                    y: Math.round(p.y - LABEL_H / 2),
                    w,
                    h: LABEL_H,
                };
                const blocked =
                    [...boxes.values()].some((node) =>
                        overlaps(box, node, 2),
                    ) || labels.some((placed) => overlaps(box, placed.box));

                if (!blocked) {
                    labels.push({ line, box, text });

                    return true;
                }
            }

            return false;
        };

        // The whole label where it fits, else the round trip alone; the
        // list under the diagram always has the full numbers.
        if (!place(line.measured ?? '') && line.short) {
            place(line.short);
        }
    }

    const measuredLines = lines.filter((line) => line.measured);

    const kinds = new Set(lines.map((line) => line.kind));
    // Each icon once, in a fixed order, explained in the legend.
    const shownDuties = [
        DASHBOARD,
        ...(['edge', 'runtime', 'build'] as const).map((role) => ({
            key: role,
            icon: roleIcons[role],
            label: roleTitles[role],
        })),
        REGISTRY,
    ].filter((duty) =>
        tiers
            .flat()
            .some(
                (node) =>
                    node.kind !== 'internet' &&
                    node.duties.some((d) => d.key === duty.key),
            ),
    );

    return (
        <section
            aria-labelledby="fleet-topology-title"
            className="flex min-w-0 flex-col gap-6 rounded-xl border bg-card p-6 shadow-card"
        >
            <div className="flex flex-col gap-0.5">
                <h2 id="fleet-topology-title" className="type-heading">
                    How it fits together
                </h2>
                <p className="type-small text-muted-foreground">
                    {active.length === 1
                        ? 'One server does everything. Add another to share the work.'
                        : 'Who takes traffic, runs apps and builds, and how they connect.'}
                </p>
            </div>

            <div
                ref={ref}
                className="relative w-full"
                style={{ height: height || undefined }}
            >
                {width > 0 && (
                    <>
                        <svg
                            width={width}
                            height={height}
                            aria-hidden="true"
                            className="absolute inset-0 block overflow-visible"
                        >
                            {lines.map((line) => {
                                const d = path(line);

                                if (!d) {
                                    return null;
                                }

                                const lit =
                                    hover === null ||
                                    hover === line.from ||
                                    hover === line.to;

                                return (
                                    <path
                                        key={line.key}
                                        d={d}
                                        fill="none"
                                        strokeWidth={1.5}
                                        strokeLinecap="round"
                                        strokeDasharray={
                                            line.kind === 'relay'
                                                ? '4 4'
                                                : line.kind === 'idle'
                                                  ? '1 4'
                                                  : undefined
                                        }
                                        className="transition-opacity duration-base"
                                        opacity={
                                            lit
                                                ? line.kind === 'idle'
                                                    ? 0.5
                                                    : 0.85
                                                : 0.15
                                        }
                                        style={{
                                            stroke:
                                                line.kind === 'public'
                                                    ? 'var(--foreground)'
                                                    : 'var(--signal)',
                                        }}
                                    >
                                        <title>{`${names.get(line.from)} – ${names.get(line.to)}: ${line.label}`}</title>
                                    </path>
                                );
                            })}
                        </svg>
                        {labels.map(({ line, box, text }) => {
                            const lit =
                                hover === null ||
                                hover === line.from ||
                                hover === line.to;

                            return (
                                <span
                                    key={`label-${line.key}`}
                                    aria-hidden="true"
                                    title={`${names.get(line.from)} – ${names.get(line.to)}: ${line.label}`}
                                    className={cn(
                                        'absolute flex items-center justify-center rounded-full border bg-card type-mono-small whitespace-nowrap text-muted-foreground tabular-nums transition-opacity duration-base',
                                        !lit && 'opacity-30',
                                    )}
                                    style={{
                                        left: box.x,
                                        top: box.y,
                                        width: box.w,
                                        height: box.h,
                                    }}
                                >
                                    {text}
                                </span>
                            );
                        })}
                        {tiers.flat().map((node) => {
                            const box = boxes.get(node.id);

                            if (!box) {
                                return null;
                            }

                            return (
                                <div
                                    key={node.id}
                                    className="absolute"
                                    style={{
                                        left: box.x,
                                        top: box.y,
                                        width: box.w,
                                        height: box.h,
                                    }}
                                    onPointerEnter={() => setHover(node.id)}
                                    onPointerLeave={() => setHover(null)}
                                >
                                    <TopologyNode
                                        node={node}
                                        dim={
                                            hover !== null &&
                                            hover !== node.id &&
                                            !lines.some(
                                                (line) =>
                                                    (line.from === hover &&
                                                        line.to === node.id) ||
                                                    (line.to === hover &&
                                                        line.from === node.id),
                                            )
                                        }
                                    />
                                </div>
                            );
                        })}
                    </>
                )}
            </div>

            <ul className="sr-only">
                {lines.map((line) => (
                    <li key={line.key}>
                        {names.get(line.from)} to {names.get(line.to)}:{' '}
                        {line.label}
                    </li>
                ))}
            </ul>

            {measuredLines.length > 0 && (
                <ul
                    aria-label="Measured links"
                    className="grid gap-x-6 gap-y-1.5 border-t pt-4 sm:grid-cols-2"
                >
                    {measuredLines.map((line) => (
                        <li
                            key={`measured-${line.key}`}
                            title={line.label}
                            className="flex min-w-0 items-baseline justify-between gap-3 type-small"
                        >
                            <span className="truncate text-muted-foreground">
                                {names.get(line.from)} – {names.get(line.to)}
                            </span>
                            <span className="shrink-0 type-mono-small tabular-nums">
                                {line.measured}
                            </span>
                        </li>
                    ))}
                </ul>
            )}

            {(lines.length > 0 || shownDuties.length > 0) && (
                <ul
                    aria-label="Legend"
                    className="flex flex-wrap gap-x-5 gap-y-1.5 border-t pt-4 type-small text-muted-foreground"
                >
                    {shownDuties.map((duty) => {
                        const Icon = duty.icon;

                        return (
                            <li
                                key={duty.key}
                                className="inline-flex items-center gap-1.5"
                            >
                                <Icon aria-hidden="true" className="size-3" />
                                {duty.key === 'dashboard'
                                    ? 'Dashboard'
                                    : duty.label}
                            </li>
                        );
                    })}
                    {shownDuties.length > 0 && lines.length > 0 && (
                        <li aria-hidden="true" className="h-0 basis-full" />
                    )}
                    {kinds.has('public') && (
                        <LegendItem className="border-foreground">
                            Public traffic
                        </LegendItem>
                    )}
                    {kinds.has('direct') && (
                        <LegendItem className="border-(--signal)">
                            Private network, direct
                        </LegendItem>
                    )}
                    {kinds.has('relay') && (
                        <LegendItem className="border-dashed border-(--signal)">
                            Relayed, still encrypted
                        </LegendItem>
                    )}
                    {kinds.has('idle') && (
                        <LegendItem className="border-dotted border-(--signal) opacity-60">
                            Idle, connects on first use
                        </LegendItem>
                    )}
                </ul>
            )}
        </section>
    );
}

function LegendItem({
    className,
    children,
}: {
    className: string;
    children: string;
}) {
    return (
        <li className="inline-flex items-center gap-2">
            <span
                aria-hidden="true"
                className={cn('h-0 w-5 border-t-[1.5px]', className)}
            />
            {children}
        </li>
    );
}

function DutyIcons({ duties }: { duties: Duty[] }) {
    return (
        <span className="flex items-center gap-1.5">
            {duties.map((duty) => {
                const Icon = duty.icon;

                return (
                    <span
                        key={duty.key}
                        title={duty.label}
                        className="inline-flex size-5 items-center justify-center rounded-md bg-hover text-muted-foreground"
                    >
                        <Icon aria-hidden="true" className="size-3" />
                        <span className="sr-only">{duty.label}</span>
                    </span>
                );
            })}
        </span>
    );
}

function TopologyNode({ node, dim }: { node: Node; dim: boolean }) {
    const frame = cn(
        'flex h-full w-full items-center gap-3 rounded-xl border bg-card px-3 transition-opacity duration-base',
        dim && 'opacity-45',
    );

    if (node.kind === 'internet') {
        return (
            <div className={cn(frame, 'justify-center bg-recessed')}>
                <Globe
                    aria-hidden="true"
                    className="size-4 text-muted-foreground"
                />
                <span className="type-label">Internet</span>
            </div>
        );
    }

    if (node.kind === 'dashboard') {
        return (
            <div className={frame}>
                <span className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-recessed text-muted-foreground">
                    <LayoutDashboard aria-hidden="true" className="size-4" />
                </span>
                <span className="flex min-w-0 flex-col gap-1">
                    <span className="truncate type-label">Dashboard</span>
                    <DutyIcons duties={node.duties} />
                </span>
            </div>
        );
    }

    const { server } = node;
    const { state, label } = serverStatus[server.status];

    return (
        <Link
            href={show(server.id)}
            className={cn(
                frame,
                'outline-none hover:border-border-strong focus-visible:ring-2 focus-visible:ring-signal',
            )}
            title={`${server.name}: ${label}`}
            aria-label={`${server.name}, ${label}. ${node.duties.map((duty) => duty.label).join(', ')}`}
        >
            <DeviceImage
                device={deviceKind(server)}
                status={deviceStatus(server.status)}
                size={60}
                className="shrink-0"
            />
            <span className="flex min-w-0 flex-1 flex-col gap-1">
                <span className="flex min-w-0 items-center gap-2">
                    <span className="truncate type-label">{server.name}</span>
                    {server.status !== 'online' && (
                        <Status
                            state={state}
                            label={label}
                            className="shrink-0 type-small"
                        />
                    )}
                </span>
                <DutyIcons duties={node.duties} />
            </span>
        </Link>
    );
}
