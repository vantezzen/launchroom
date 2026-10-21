import { Ban, Check, ChevronDown, Pin } from 'lucide-react';
import type { ReactNode } from 'react';
import { DEVICE_RENDER, DeviceImage } from '@/components/devices';
import type { DeviceKind, DeviceStatus } from '@/components/devices';
import { DEVICE_ART } from '@/components/devices/device-art.generated';
import { Logo } from '@/components/logo';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    deviceKind,
    deviceStatus,
    formatMb,
    placeLabel,
} from '@/features/servers/format';
import { cn } from '@/lib/utils';

export type ServerRule = 'any' | 'only' | 'never';

export const ruleLabels: Record<ServerRule, string> = {
    any: 'May run here',
    only: 'Only here',
    never: 'Never here',
};

/**
 * Replicas as units: one small tile with the service's logo per replica
 * (the canvas node in miniature). Saved ones are solid; while the draft
 * differs, new ones get the dashed signal outline (the staged look,
 * design-system §5) and the ones going away stay as hollow ghosts.
 */
export function ReplicaUnits({
    count,
    previous,
    staged,
    logo,
    label,
    max = 12,
    size = 20,
}: {
    count: number;
    /** What runs there now (shown as ghosts when more than `count`). */
    previous?: number;
    staged?: boolean;
    logo: string;
    label?: string;
    max?: number;
    size?: 20 | 32;
}) {
    const ghosts =
        staged && previous !== undefined ? Math.max(0, previous - count) : 0;
    const shown = Math.min(count, max);

    return (
        <span
            role="img"
            aria-label={
                label ?? `${count} ${count === 1 ? 'replica' : 'replicas'}`
            }
            className="flex flex-wrap items-center justify-center gap-1.5"
        >
            {Array.from({ length: shown }, (_, index) => {
                const added =
                    staged && previous !== undefined && index >= previous;

                return (
                    <Logo
                        key={`u${index}`}
                        kind={logo}
                        size={size}
                        decorative
                        className={cn(
                            'shadow-card motion-safe:animate-in motion-safe:duration-base motion-safe:ease-enter motion-safe:zoom-in-75',
                            added &&
                                'border-dashed border-signal bg-signal-soft text-signal',
                        )}
                    />
                );
            })}
            {Array.from({ length: Math.min(ghosts, max) }, (_, index) => (
                <span
                    key={`g${index}`}
                    className={cn(
                        'rounded-[6px] border border-dashed border-border-strong',
                        size === 32 ? 'size-8 rounded-[8px]' : 'size-5',
                    )}
                />
            ))}
            {count > shown && (
                <span className="type-small text-muted-foreground tabular-nums">
                    +{count - shown}
                </span>
            )}
        </span>
    );
}

/**
 * Capacity bar: what other services hold (grey), what this service would
 * take (signal) and what is free (track). Amber only past the total.
 */
export function CapacityBar({
    label,
    used,
    mine,
    total,
    format,
}: {
    label: string;
    used: number;
    mine: number;
    total: number | null;
    format: (value: number) => string;
}) {
    if (total === null || total <= 0) {
        return (
            <div className="flex items-center justify-between type-small text-muted-foreground">
                <span>{label}</span>
                <span>Not reported</span>
            </div>
        );
    }

    const over = used + mine > total;
    const pct = (value: number) => `${Math.min(100, (value / total) * 100)}%`;

    return (
        <div className="flex flex-col gap-1.5">
            <div className="flex items-baseline justify-between gap-2 type-small">
                <span className="text-muted-foreground">{label}</span>
                <span
                    className={cn(
                        'tabular-nums',
                        over ? 'text-status-progress' : 'text-muted-foreground',
                    )}
                >
                    {mine > 0 && (
                        <span className="text-foreground">{format(mine)}</span>
                    )}
                    {mine > 0 ? ' · ' : ''}
                    {format(Math.max(0, total - used - mine))} free
                </span>
            </div>
            <div
                role="meter"
                aria-label={`${label}: ${format(mine)} for this service, ${format(used)} used by others, of ${format(total)}`}
                aria-valuemin={0}
                aria-valuemax={total}
                aria-valuenow={used + mine}
                className="flex h-1.5 gap-[2px] overflow-hidden rounded-full bg-hover"
            >
                {used > 0 && (
                    <span
                        className="h-full bg-chart-other"
                        style={{ width: pct(used) }}
                    />
                )}
                {mine > 0 && (
                    <span
                        className={cn(
                            'h-full transition-[width] duration-base ease-enter',
                            over ? 'bg-status-progress' : 'bg-signal',
                        )}
                        style={{ width: pct(mine) }}
                    />
                )}
            </div>
        </div>
    );
}

const DEVICE_SIZE = 168;

/** px from the stage's bottom to just above the device body (art metadata). */
function unitsBottom(kind: DeviceKind, size = DEVICE_SIZE): number {
    const art = DEVICE_ART.devices[DEVICE_RENDER[kind]];
    const [, canvasHeight] = DEVICE_ART.canvas;
    const height = (size * 10) / 16;
    const top = (art.body[1] / canvasHeight) * height;

    return 4 + height - top + 6;
}

/**
 * A device on the recessed stage with things standing on its top edge
 * (replicas, a volume): the art's body box says where that edge is.
 */
export function DeviceStage({
    device,
    status,
    dimmed = false,
    size = DEVICE_SIZE,
    className,
    children,
}: {
    device: DeviceKind;
    status: DeviceStatus;
    dimmed?: boolean;
    size?: number;
    className?: string;
    children?: ReactNode;
}) {
    return (
        <div
            className={cn(
                'relative flex h-[150px] items-end justify-center bg-recessed pb-1',
                className,
            )}
        >
            <div
                className={cn(
                    'transition-[filter,opacity] duration-base',
                    dimmed && 'opacity-45 grayscale',
                )}
            >
                <DeviceImage device={device} status={status} size={size} />
            </div>
            {children && (
                <div
                    className="absolute inset-x-0 z-10 flex justify-center px-4"
                    style={{ bottom: unitsBottom(device, size) }}
                >
                    {children}
                </div>
            )}
        </div>
    );
}

export type ServerTile = App.Data.Canvas.PlacementServerOptionData & {
    /** Draft eligibility and placement from the live preview. */
    draftEligible: boolean;
    draftReason: string | null;
    draftPlanned: number;
};

/**
 * One server as the scheduler sees it for this service: the device with
 * its LED, the replicas it would hold, its CPU and memory against what
 * others use, and the rule for this service (may / only / never).
 */
export function ServerPlacementCard({
    server,
    cpus,
    memoryMb,
    memoryOvercommit,
    cpuOvercommit,
    rule,
    onRule,
    staged,
    pinned,
    disabled,
    logo,
}: {
    server: ServerTile;
    cpus: number;
    memoryMb: number;
    memoryOvercommit: number;
    cpuOvercommit: number | null;
    rule: ServerRule;
    onRule?: (rule: ServerRule) => void;
    staged: boolean;
    pinned: boolean;
    disabled: boolean;
    /** The service's logo key, for the replica tiles. */
    logo: string;
}) {
    const planned = server.draftPlanned;
    const memoryTotal =
        server.memoryMb === null
            ? null
            : Math.max(
                  0,
                  Math.round(server.memoryMb * memoryOvercommit) -
                      server.reservedMemoryMb,
              );
    const cpuTotal =
        server.cpus === null ? null : server.cpus * (cpuOvercommit ?? 1);
    const place = placeLabel(server);
    const eligible = server.draftEligible;

    return (
        <li
            className={cn(
                'flex flex-col overflow-hidden rounded-xl border bg-card shadow-card transition-[border-color,opacity] duration-base',
                planned > 0 &&
                    (staged ? 'border-signal/60' : 'border-border-strong'),
            )}
        >
            <DeviceStage
                device={deviceKind(server)}
                status={deviceStatus(server.status)}
                dimmed={!eligible}
            >
                {planned > 0 ? (
                    <ReplicaUnits
                        count={planned}
                        previous={server.replicas}
                        staged={staged}
                        logo={logo}
                        size={planned <= 4 ? 32 : 20}
                        label={`${planned} ${planned === 1 ? 'replica' : 'replicas'} on ${server.name}`}
                    />
                ) : server.replicas > 0 && staged ? (
                    <ReplicaUnits
                        count={0}
                        previous={server.replicas}
                        staged
                        logo={logo}
                        size={32}
                        label="Replicas leave this server"
                    />
                ) : null}
            </DeviceStage>
            <div className="flex flex-1 flex-col gap-3 p-4">
                <div className="flex min-w-0 flex-col gap-0.5">
                    <span className="flex items-center gap-1.5 truncate type-body font-medium">
                        {server.name}
                        {pinned && (
                            <Pin
                                aria-label="Pinned"
                                className="size-3.5 text-muted-foreground"
                            />
                        )}
                    </span>
                    <span className="truncate type-small text-muted-foreground">
                        {pinned
                            ? 'Holds its data'
                            : eligible
                              ? planned > 0
                                  ? `${planned} ${planned === 1 ? 'replica' : 'replicas'}${place ? ` · ${place}` : ''}`
                                  : (place ?? 'Has room')
                              : (server.draftReason ?? 'Not eligible')}
                    </span>
                </div>
                <div className="flex flex-col gap-2.5">
                    <CapacityBar
                        label="CPU"
                        used={server.allocatedCpus}
                        mine={planned * cpus}
                        total={cpuTotal}
                        format={(value) =>
                            `${Number(value.toFixed(2))} ${value === 1 ? 'core' : 'cores'}`
                        }
                    />
                    <CapacityBar
                        label="Memory"
                        used={server.allocatedMemoryMb}
                        mine={planned * memoryMb}
                        total={memoryTotal}
                        format={(value) => formatMb(value) ?? '0 MB'}
                    />
                </div>
            </div>
            {onRule && (
                <div className="border-t px-2 py-1.5">
                    <RuleMenu
                        server={server.name}
                        rule={rule}
                        onRule={onRule}
                        disabled={disabled}
                    />
                </div>
            )}
        </li>
    );
}

function RuleMenu({
    server,
    rule,
    onRule,
    disabled,
}: {
    server: string;
    rule: ServerRule;
    onRule: (rule: ServerRule) => void;
    disabled: boolean;
}) {
    const icon: Record<ServerRule, ReactNode> = {
        any: <Check className="size-3.5" />,
        only: <Pin className="size-3.5" />,
        never: <Ban className="size-3.5" />,
    };

    return (
        <DropdownMenu>
            <DropdownMenuTrigger
                disabled={disabled}
                aria-label={`Rule for ${server}: ${ruleLabels[rule]}`}
                className={cn(
                    'flex h-8 w-full items-center gap-2 rounded-md px-2 type-small transition-colors duration-fast hover:bg-hover focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:pointer-events-none',
                    rule === 'any'
                        ? 'text-muted-foreground'
                        : 'text-foreground',
                )}
            >
                <span aria-hidden="true">{icon[rule]}</span>
                {ruleLabels[rule]}
                {!disabled && (
                    <ChevronDown
                        aria-hidden="true"
                        className="ml-auto size-3.5 text-faint"
                    />
                )}
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="w-56">
                <DropdownMenuLabel className="type-small font-normal text-muted-foreground">
                    This service on {server}
                </DropdownMenuLabel>
                <DropdownMenuRadioGroup
                    value={rule}
                    onValueChange={(value) => onRule(value as ServerRule)}
                >
                    {(Object.keys(ruleLabels) as ServerRule[]).map((value) => (
                        <DropdownMenuRadioItem key={value} value={value}>
                            {ruleLabels[value]}
                        </DropdownMenuRadioItem>
                    ))}
                </DropdownMenuRadioGroup>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/**
 * A size per replica as a slider over sensible steps, ending at the
 * biggest server: the thumb sits against real capacity. The value and the
 * total for all replicas read beside it.
 */
export function ResourceSlider({
    id,
    label,
    steps,
    value,
    onChange,
    format,
    total,
    hint,
    disabled,
    warn,
}: {
    id: string;
    label: string;
    steps: number[];
    value: number;
    onChange: (value: number) => void;
    format: (value: number) => string;
    /** "× 3 replicas = 1.5 cores". */
    total?: string;
    hint?: ReactNode;
    disabled?: boolean;
    warn?: boolean;
}) {
    const index = Math.max(
        0,
        steps.findIndex((step) => step >= value - 1e-9),
    );
    const pct = steps.length > 1 ? (index / (steps.length - 1)) * 100 : 100;

    return (
        <div className="flex flex-col gap-3">
            <div className="flex items-end justify-between gap-4">
                <label htmlFor={id} className="flex flex-col gap-0.5">
                    <span className="type-label text-muted-foreground">
                        {label}
                    </span>
                    <span className="type-stat tabular-nums">
                        {format(value)}
                    </span>
                </label>
                {total && (
                    <span className="pb-1 type-small text-muted-foreground tabular-nums">
                        {total}
                    </span>
                )}
            </div>
            <input
                id={id}
                type="range"
                min={0}
                max={steps.length - 1}
                step={1}
                value={index}
                disabled={disabled}
                aria-valuetext={format(value)}
                onChange={(event) =>
                    onChange(steps[Number(event.target.value)] ?? value)
                }
                style={{
                    background: `linear-gradient(to right, ${warn ? 'var(--status-progress)' : 'var(--signal)'} ${pct}%, var(--hover) ${pct}%)`,
                }}
                className={cn(
                    'h-1.5 w-full cursor-pointer appearance-none rounded-full outline-none disabled:cursor-not-allowed disabled:opacity-60',
                    '[&::-webkit-slider-thumb]:size-[18px] [&::-webkit-slider-thumb]:appearance-none [&::-webkit-slider-thumb]:rounded-full [&::-webkit-slider-thumb]:border [&::-webkit-slider-thumb]:border-border-strong [&::-webkit-slider-thumb]:bg-card [&::-webkit-slider-thumb]:shadow-[0_1px_3px_rgb(0_0_0/0.2)] [&::-webkit-slider-thumb]:transition-transform active:[&::-webkit-slider-thumb]:scale-110',
                    '[&::-moz-range-thumb]:size-[18px] [&::-moz-range-thumb]:rounded-full [&::-moz-range-thumb]:border [&::-moz-range-thumb]:border-border-strong [&::-moz-range-thumb]:bg-card',
                    'focus-visible:[&::-webkit-slider-thumb]:ring-2 focus-visible:[&::-webkit-slider-thumb]:ring-ring focus-visible:[&::-webkit-slider-thumb]:ring-offset-2',
                )}
            />
            <div
                className="flex justify-between type-small text-faint tabular-nums"
                aria-hidden="true"
            >
                <span>{format(steps[0])}</span>
                <span>{format(steps[steps.length - 1])}</span>
            </div>
            {hint && (
                <p
                    className={cn(
                        'type-small',
                        warn ? 'text-status-progress' : 'text-muted-foreground',
                    )}
                >
                    {hint}
                </p>
            )}
        </div>
    );
}

/** Mini pictures for Spread vs Pack: three servers with replica blocks. */
export function StrategyPicture({ strategy }: { strategy: 'spread' | 'pack' }) {
    const layout = strategy === 'spread' ? [1, 1, 1] : [3, 0, 0];

    return (
        <span
            aria-hidden="true"
            className="flex items-end gap-2 rounded-lg bg-recessed px-3 pt-3 pb-2.5"
        >
            {layout.map((count, index) => (
                <span
                    key={index}
                    className="flex w-12 flex-col items-center gap-1.5"
                >
                    <span className="flex h-4 items-end gap-0.5">
                        {Array.from({ length: count }, (_, unit) => (
                            <span
                                key={unit}
                                className="h-3.5 w-2.5 rounded-[3px] bg-signal"
                            />
                        ))}
                    </span>
                    <span className="h-3 w-full rounded-[3px] border border-border-strong bg-card" />
                </span>
            ))}
        </span>
    );
}
