import { useState } from 'react';
import { useMeasuredWidth } from '@/components/charts/use-measure';
import { cn } from '@/lib/utils';
import { LAND_DOTS_PATH, LAND_GRID } from './land-dots.generated';
import { project } from './projection';

export type MapLocation = {
    id: string;
    /** City or place ("Berlin"). */
    label: string;
    /** Quiet qualifier ("home", "Hetzner"). */
    sublabel?: string;
    lat: number;
    lon: number;
    /** Servers in this place; sizes the bubble. */
    servers: number;
    /** Not reporting: hollow red ring and "unreachable" in the list. */
    status?: 'ok' | 'unreachable';
    /** Receives public traffic: square ring around the bubble. */
    edge?: boolean;
};

export type MapLink = {
    from: string;
    to: string;
    /** Relayed (behind NAT): dashed arc. */
    relayed?: boolean;
};

/**
 * Where the servers are (design-system §6, direction §5.10): a dotted land map with one bubble per place (sized by server
 * count), square rings for edges, private-network links as arcs (solid
 * direct, dashed relayed) and the region list on the left with counts.
 * Calm by default: neutral dots, signal bubbles, red only for unreachable.
 * The map hides below `md`; the list stays.
 */
export function ServerMap({
    locations,
    links = [],
    height = 360,
    legend = true,
    variant = 'split',
    className,
}: {
    locations: MapLocation[];
    links?: MapLink[];
    height?: number;
    legend?: boolean;
    /** `split`: region list left, map right. `stacked`: map on top, list below (one-column slots). */
    variant?: 'split' | 'stacked';
    className?: string;
}) {
    const stacked = variant === 'stacked';
    const [ref, width] = useMeasuredWidth<HTMLDivElement>();
    const [hover, setHover] = useState<string | null>(null);

    // Fit the land grid into the box, keeping its aspect ratio.
    const unit =
        width > 0
            ? Math.min(width / LAND_GRID.cols, height / LAND_GRID.rows)
            : 0;
    const offsetX = (width - LAND_GRID.cols * unit) / 2;
    const offsetY = (height - LAND_GRID.rows * unit) / 2;
    const place = (location: MapLocation) => {
        const point = project(location.lat, location.lon);

        return { x: offsetX + point.x * unit, y: offsetY + point.y * unit };
    };
    const byId = new Map(locations.map((location) => [location.id, location]));
    const labels = placeLabels(
        locations.map((location) => {
            const { x, y } = place(location);
            const r =
                location.status === 'unreachable'
                    ? 6
                    : location.edge
                      ? 9
                      : 4 + Math.sqrt(location.servers) * 3;

            return {
                id: location.id,
                x,
                y,
                r,
                width: location.label.length * 6.4,
            };
        }),
    );
    const sorted = [...locations].sort(
        (a, b) => b.servers - a.servers || a.label.localeCompare(b.label),
    );
    const total = locations.reduce(
        (sum, location) => sum + location.servers,
        0,
    );

    return (
        <div
            className={cn(
                'grid overflow-hidden rounded-[14px] border bg-card shadow-[0_1px_2px_rgb(0_0_0/0.04)] dark:shadow-none',
                stacked
                    ? 'grid-rows-[auto_minmax(0,1fr)] content-start'
                    : 'md:grid-cols-[240px_1fr]',
                className,
            )}
        >
            <div
                className={cn(
                    'flex flex-col gap-4 p-5',
                    stacked ? 'order-2' : 'md:border-r',
                )}
            >
                <ul
                    aria-label={`${total} servers in ${locations.length} places`}
                    className="flex flex-col gap-0.5"
                >
                    {sorted.map((location) => {
                        const down = location.status === 'unreachable';

                        return (
                            <li
                                key={location.id}
                                onPointerEnter={() => setHover(location.id)}
                                onPointerLeave={() => setHover(null)}
                                className={cn(
                                    'flex h-9 items-center gap-2.5 rounded-lg px-2.5 type-body',
                                    hover === location.id && 'bg-(--hover)',
                                )}
                            >
                                <span
                                    aria-hidden="true"
                                    className={cn(
                                        'size-2 shrink-0 rounded-full',
                                        down
                                            ? 'border-[1.5px] border-(--status-error)'
                                            : 'bg-(--signal)',
                                    )}
                                />
                                <span className="truncate">
                                    {location.label}
                                </span>
                                {location.sublabel && (
                                    <span className="truncate type-small text-muted-foreground">
                                        {location.sublabel}
                                    </span>
                                )}
                                <span
                                    className={cn(
                                        'ml-auto shrink-0 type-small tabular-nums',
                                        down
                                            ? 'text-status-error'
                                            : 'text-muted-foreground',
                                    )}
                                >
                                    {down
                                        ? 'Unreachable'
                                        : `${location.servers} ${location.servers === 1 ? 'server' : 'servers'}`}
                                </span>
                            </li>
                        );
                    })}
                </ul>
                {legend &&
                    (links.length > 0 ||
                        locations.some((location) => location.edge)) && (
                        <ul className="mt-auto flex flex-col gap-1.5 border-t pt-4 type-small text-muted-foreground">
                            {links.some((link) => !link.relayed) && (
                                <li className="flex items-center gap-2.5">
                                    <span
                                        aria-hidden="true"
                                        className="h-0 w-5 border-t-[1.5px] border-(--signal)"
                                    />
                                    Direct connection
                                </li>
                            )}
                            {links.some((link) => link.relayed) && (
                                <li className="flex items-center gap-2.5">
                                    <span
                                        aria-hidden="true"
                                        className="h-0 w-5 border-t-[1.5px] border-dashed border-(--signal)"
                                    />
                                    Relayed, still encrypted
                                </li>
                            )}
                            {locations.some((location) => location.edge) && (
                                <li className="flex items-center gap-2.5">
                                    <span
                                        aria-hidden="true"
                                        className="mx-1.5 size-2.5 rounded-[2px] border-[1.5px] border-foreground"
                                    />
                                    Edge: receives public traffic
                                </li>
                            )}
                        </ul>
                    )}
            </div>
            <div
                ref={ref}
                className={cn(
                    'relative max-md:hidden',
                    stacked && 'order-1 border-b',
                )}
                style={{ height }}
            >
                {width > 0 && (
                    <svg
                        width={width}
                        height={height}
                        className="block"
                        role="img"
                        aria-label="Map of server locations"
                    >
                        <path
                            d={LAND_DOTS_PATH}
                            transform={`translate(${offsetX} ${offsetY}) scale(${unit})`}
                            fill="none"
                            strokeWidth={0.5}
                            strokeLinecap="round"
                            className="text-map-land"
                            style={{ stroke: 'currentColor' }}
                        />
                        {links.map((link) => {
                            const a = byId.get(link.from);
                            const b = byId.get(link.to);

                            if (!a || !b) {
                                return null;
                            }

                            const p = place(a);
                            const q = place(b);
                            const distance = Math.hypot(q.x - p.x, q.y - p.y);
                            const lift = Math.min(90, distance * 0.28);
                            const cx = (p.x + q.x) / 2;
                            const cy = (p.y + q.y) / 2 - lift;
                            const highlighted =
                                hover === null ||
                                hover === a.id ||
                                hover === b.id;

                            return (
                                <path
                                    key={`${link.from}-${link.to}`}
                                    d={`M${p.x} ${p.y}Q${cx} ${cy} ${q.x} ${q.y}`}
                                    fill="none"
                                    strokeWidth={1.5}
                                    strokeLinecap="round"
                                    strokeDasharray={
                                        link.relayed ? '4 4' : undefined
                                    }
                                    opacity={highlighted ? 0.75 : 0.2}
                                    style={{ stroke: 'var(--signal)' }}
                                />
                            );
                        })}
                        {sorted
                            .slice()
                            .reverse()
                            .map((location) => {
                                const { x, y } = place(location);
                                const down = location.status === 'unreachable';
                                const r = 4 + Math.sqrt(location.servers) * 3;
                                const dim =
                                    hover !== null && hover !== location.id;

                                return (
                                    <g
                                        key={location.id}
                                        opacity={dim ? 0.45 : 1}
                                        onPointerEnter={() =>
                                            setHover(location.id)
                                        }
                                        onPointerLeave={() => setHover(null)}
                                    >
                                        <title>
                                            {`${location.label}: ${down ? 'unreachable' : `${location.servers} ${location.servers === 1 ? 'server' : 'servers'}`}`}
                                        </title>
                                        {down ? (
                                            <circle
                                                cx={x}
                                                cy={y}
                                                r={4.5}
                                                fill="none"
                                                strokeWidth={2}
                                                style={{
                                                    stroke: 'var(--status-error)',
                                                }}
                                            />
                                        ) : (
                                            <>
                                                <circle
                                                    cx={x}
                                                    cy={y}
                                                    r={r}
                                                    style={{
                                                        fill: 'var(--signal)',
                                                        opacity: 0.16,
                                                    }}
                                                />
                                                <circle
                                                    cx={x}
                                                    cy={y}
                                                    r={3.5}
                                                    style={{
                                                        fill: 'var(--signal)',
                                                    }}
                                                />
                                            </>
                                        )}
                                        {location.edge && (
                                            <rect
                                                x={x - 8}
                                                y={y - 8}
                                                width={16}
                                                height={16}
                                                rx={3}
                                                fill="none"
                                                strokeWidth={1.5}
                                                style={{
                                                    stroke: 'var(--foreground)',
                                                }}
                                            />
                                        )}
                                        <text
                                            x={
                                                labels.get(location.id)?.x ??
                                                x + r + 4
                                            }
                                            y={labels.get(location.id)?.y ?? y}
                                            dy="0.32em"
                                            textAnchor={
                                                labels.get(location.id)
                                                    ?.anchor ?? 'start'
                                            }
                                            className="fill-foreground text-[11px]"
                                            paintOrder="stroke"
                                            strokeWidth={3}
                                            strokeLinejoin="round"
                                            style={{ stroke: 'var(--card)' }}
                                        >
                                            {location.label}
                                        </text>
                                    </g>
                                );
                            })}
                    </svg>
                )}
            </div>
        </div>
    );
}

type LabelBox = { x: number; y: number; anchor: 'start' | 'end' | 'middle' };

/**
 * Greedy label placement: right of the bubble, else left, above, below;
 * the first candidate that overlaps no placed label (or bubble) wins.
 */
function placeLabels(
    points: { id: string; x: number; y: number; r: number; width: number }[],
): Map<string, LabelBox> {
    const placed: { x1: number; y1: number; x2: number; y2: number }[] =
        points.map((p) => ({
            x1: p.x - p.r,
            y1: p.y - p.r,
            x2: p.x + p.r,
            y2: p.y + p.r,
        }));
    const out = new Map<string, LabelBox>();
    const height = 14;

    for (const p of [...points].sort((a, b) => b.r - a.r)) {
        const gap = p.r + 4;
        const candidates: (LabelBox & {
            box: { x1: number; y1: number; x2: number; y2: number };
        })[] = [
            {
                x: p.x + gap,
                y: p.y,
                anchor: 'start',
                box: {
                    x1: p.x + gap,
                    y1: p.y - height / 2,
                    x2: p.x + gap + p.width,
                    y2: p.y + height / 2,
                },
            },
            {
                x: p.x - gap,
                y: p.y,
                anchor: 'end',
                box: {
                    x1: p.x - gap - p.width,
                    y1: p.y - height / 2,
                    x2: p.x - gap,
                    y2: p.y + height / 2,
                },
            },
            {
                x: p.x,
                y: p.y - gap - 6,
                anchor: 'middle',
                box: {
                    x1: p.x - p.width / 2,
                    y1: p.y - gap - 6 - height / 2,
                    x2: p.x + p.width / 2,
                    y2: p.y - gap - 6 + height / 2,
                },
            },
            {
                x: p.x,
                y: p.y + gap + 6,
                anchor: 'middle',
                box: {
                    x1: p.x - p.width / 2,
                    y1: p.y + gap + 6 - height / 2,
                    x2: p.x + p.width / 2,
                    y2: p.y + gap + 6 + height / 2,
                },
            },
        ];
        const own = (b: { x1: number; y1: number; x2: number; y2: number }) =>
            b.x1 === p.x - p.r && b.y1 === p.y - p.r;
        const free =
            candidates.find(
                (candidate) =>
                    !placed.some(
                        (b) =>
                            !own(b) &&
                            candidate.box.x1 < b.x2 &&
                            candidate.box.x2 > b.x1 &&
                            candidate.box.y1 < b.y2 &&
                            candidate.box.y2 > b.y1,
                    ),
            ) ?? candidates[0];

        placed.push(free.box);
        out.set(p.id, { x: free.x, y: free.y, anchor: free.anchor });
    }

    return out;
}
