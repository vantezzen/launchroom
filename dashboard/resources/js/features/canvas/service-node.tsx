import { Handle, Position } from '@xyflow/react';
import {
    ArrowUpRight,
    Ellipsis,
    HardDrive,
    Rocket,
    ScrollText,
    Settings,
    SquareTerminal,
    TriangleAlert,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { NodeProps } from '@xyflow/react';
import { createContext, memo, useContext } from 'react';
import type { MouseEvent, ReactNode } from 'react';
import { AlertsChip } from '@/components/attention-chip';
import { Sparkline } from '@/components/charts/sparkline';
import { Status } from '@/components/status';
import { secondarySignals } from '@/features/service-panel/panel-signals';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { BackupHealthStatus } from '@/features/backups/backup-health';
import { sharedElement } from '@/lib/motion';
import { cn } from '@/lib/utils';
import { NODE_WIDTH } from './auto-layout';
import { useServiceMetrics } from './canvas-metrics';
import type { ServiceFlowNode } from './types';
import {
    compactAge,
    formatCompact,
    formatMemory,
    formatShare,
} from '@/lib/format';
import { ServiceGlyph } from '@/components/service-glyph';
import { StagedChip } from '@/features/staged-changes/effects';
import { openStagedChanges } from '@/features/staged-changes/staged-changes-sheet';
import { describeCron, parseCron } from '@/features/service-settings/cron';

/**
 * What a node's toolbar can do, provided by the canvas (service-canvas.tsx):
 * open a panel tab, open the node's menu at a point. `canConsole` follows
 * the panel's own rule for its Console tab.
 */
export type NodeActions = {
    openService: (serviceId: string, tab?: string) => void;
    openMenu: (serviceId: string, position: { x: number; y: number }) => void;
    canConsole: boolean;
};

export const NodeActionsContext = createContext<NodeActions | null>(null);

/**
 * The error rate past which a node's traffic line turns amber (the
 * default of the `error_rate_high` alert, docs/15 §3.4) and red (where that
 * alert turns critical). The number is always shown, so colour is never
 * the only cue.
 */
const ERROR_WARN_PCT = 5;
const ERROR_CRITICAL_PCT = 25;

/** "×2 on fra-1, hel-1", "Scaled to zero". */
function placementLine(node: App.Data.Canvas.CanvasNodeData): string | null {
    if (node.replicas === 0) {
        return 'Scaled to zero';
    }

    if (node.server) {
        return node.replicas > 1
            ? `×${node.replicas} on ${node.server}`
            : node.server;
    }

    return node.replicas > 1 ? `×${node.replicas}` : null;
}

/** "Weekdays at 03:30" for a cron's expression; the expression itself if it can't be put in words. */
function scheduleLine(cron: string | null): string | null {
    if (cron === null) {
        return null;
    }

    const parsed = parseCron(cron);

    return 'cron' in parsed ? describeCron(parsed.cron) : cron;
}

/**
 * A service on the canvas (design-system §6 "Canvas node", direction §9.4):
 * logo, name and one subtitle; the status line with its open alerts as an
 * attention chip; one key fact (address or backup); a footer strip: for
 * services with public traffic the requests of the last hour (sparkline,
 * req/min) and the error rate, else the CPU sparkline and memory; then
 * placement. Hovering, focusing or selecting a node shows its toolbar
 * (panel tabs, the live URL, the menu). Selected: 2 px foreground ring. Staged:
 * dashed signal outline + chip, and what is staged in words ("1 variable
 * and 2 settings", opens the review). A staged removal: dimmed, name struck
 * through, "Removed when applied". Degraded: amber ring + chip. Failed:
 * only the status line turns red.
 */
export const ServiceNode = memo(function ServiceNode({
    data,
    selected,
}: NodeProps<ServiceFlowNode>) {
    const usage = useServiceMetrics(data.id);
    const actions = useContext(NodeActionsContext);
    const staged = data.staged !== null;
    const degraded = data.placementIssue !== null;
    const fact =
        data.domain ?? data.privateAddress?.replace(/^https?:\/\//, '');
    const placement = placementLine(data);
    const schedule = data.kind === 'cron' ? scheduleLine(data.schedule) : null;
    const cpu = usage?.cpuCores ?? [];
    const hasCpu = cpu.some((value) => value !== null && value > 0);
    const memory = usage?.currentMemMb ?? null;
    // Public services: traffic of the last hour instead of CPU and memory.
    const traffic =
        usage?.requests && usage.requests.totals.requests > 0
            ? usage.requests
            : null;
    const errorRate = traffic?.totals.errorRatePct ?? null;
    const showFooter =
        traffic !== null ||
        hasCpu ||
        memory !== null ||
        placement !== null ||
        data.volume !== null ||
        schedule !== null;

    return (
        <div
            className={cn(
                'service-node group/node relative flex flex-col rounded-[14px] border bg-card text-left shadow-card',
                'transition-[box-shadow,outline-color,opacity] duration-fast ease-enter motion-reduce:transition-none',
                selected && 'ring-2 ring-foreground',
                !selected && degraded && 'ring-2 ring-status-progress/60',
                staged &&
                    'outline-[1.5px] outline-offset-[3px] outline-signal outline-dashed',
                data.removed && 'opacity-60',
            )}
            style={{ width: NODE_WIDTH }}
        >
            <Handle
                type="target"
                position={Position.Left}
                isConnectable={data.connectable}
            />

            {actions && !data.removed && (
                <NodeToolbar node={data} actions={actions} visible={selected} />
            )}

            <div className="flex flex-col gap-3 p-4">
                <div className="flex items-start gap-3">
                    <ServiceGlyph
                        logo={data.logo}
                        style={
                            selected ? undefined : sharedElement('svc', data.id)
                        }
                    />
                    <div className="flex min-w-0 flex-1 flex-col">
                        <span
                            className={cn(
                                'truncate type-label text-[14px] leading-5 font-semibold',
                                data.removed &&
                                    'line-through decoration-muted-foreground',
                            )}
                        >
                            {data.name}
                        </span>
                        <span className="truncate type-small text-muted-foreground">
                            {data.subtitle}
                        </span>
                    </div>
                    {staged && data.staged && (
                        <StagedChip effect={data.staged} />
                    )}
                    {!staged && degraded && (
                        <span className="inline-flex h-5 shrink-0 items-center gap-1 rounded-full bg-status-progress-soft px-2 type-small text-status-progress">
                            <TriangleAlert
                                aria-hidden="true"
                                className="size-3"
                            />
                            {data.placementIssue?.kind ===
                            'stateful_server_down'
                                ? 'Server down'
                                : 'Degraded'}
                        </span>
                    )}
                </div>

                <div className="flex min-w-0 flex-col gap-1">
                    <div className="flex min-w-0 items-center gap-2">
                        <Status
                            state={data.status.state}
                            label={data.status.label}
                            detail={
                                data.status.since
                                    ? compactAge(data.status.since)
                                    : undefined
                            }
                            className="min-w-0 type-small"
                        />
                        {data.openAlerts.length > 0 && (
                            <AlertsChip
                                // Not the alert the status already says.
                                alerts={secondarySignals(
                                    data.status.reason,
                                    data.openAlerts,
                                    null,
                                )}
                                size="sm"
                                className="nodrag nopan ml-auto"
                                stopPropagation
                            />
                        )}
                    </div>
                    {data.removed ? (
                        <span className="type-small text-status-error">
                            Removed when applied
                        </span>
                    ) : (
                        data.stagedSummary && (
                            <button
                                type="button"
                                title="Review the staged changes"
                                className="nodrag nopan inline-flex min-w-0 items-center gap-1.5 self-start rounded-sm type-small text-signal hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                onClick={(event) => {
                                    event.stopPropagation();
                                    openStagedChanges();
                                }}
                            >
                                <span
                                    aria-hidden="true"
                                    className="size-1.5 shrink-0 rounded-full border border-dashed border-signal"
                                />
                                <span className="truncate">
                                    {data.stagedSummary}
                                </span>
                                <span className="sr-only"> staged</span>
                            </button>
                        )
                    )}
                    {(data.kind === 'database' || data.volume !== null) &&
                    data.backup ? (
                        <BackupHealthStatus
                            health={data.backup}
                            className="type-small text-muted-foreground [&_svg]:size-3"
                        />
                    ) : (
                        fact && (
                            <span className="truncate type-mono-small text-muted-foreground">
                                {fact}
                            </span>
                        )
                    )}
                </div>
            </div>

            {showFooter && (
                <div className="flex h-9 items-center gap-2 border-t px-4 type-small text-muted-foreground tabular-nums">
                    {traffic && (
                        <>
                            <Sparkline
                                values={traffic.requestsPerSecond}
                                label={`${data.name} requests, last hour`}
                                height={16}
                                min={0}
                                className="w-16 shrink-0"
                            />
                            <span className="shrink-0">
                                {formatCompact(
                                    traffic.totals.requestsPerSecond * 60,
                                )}{' '}
                                req/min
                            </span>
                            {errorRate !== null && (
                                <span
                                    className={cn(
                                        'inline-flex shrink-0 items-center gap-1',
                                        errorRate >= ERROR_CRITICAL_PCT
                                            ? 'text-status-error'
                                            : errorRate >= ERROR_WARN_PCT &&
                                                  'text-status-progress',
                                    )}
                                >
                                    {errorRate >= ERROR_WARN_PCT && (
                                        <TriangleAlert
                                            aria-hidden="true"
                                            className="size-3"
                                        />
                                    )}
                                    {formatShare(errorRate)} errors
                                </span>
                            )}
                        </>
                    )}
                    {!traffic && hasCpu && (
                        <Sparkline
                            values={cpu}
                            label={`${data.name} CPU, last hour`}
                            height={16}
                            min={0}
                            className="w-16 shrink-0"
                        />
                    )}
                    {!traffic && memory !== null && (
                        <span className="shrink-0">{formatMemory(memory)}</span>
                    )}
                    {!traffic && data.volume && memory === null && (
                        <span className="flex min-w-0 items-center gap-1">
                            <HardDrive
                                aria-hidden="true"
                                className="size-3 shrink-0"
                            />
                            <span className="truncate font-mono">
                                {data.volume}
                            </span>
                        </span>
                    )}
                    {schedule && (
                        <span className="min-w-0 truncate">{schedule}</span>
                    )}
                    {placement && (
                        <span className="ml-auto min-w-0 truncate">
                            {placement}
                        </span>
                    )}
                </div>
            )}

            <Handle
                type="source"
                position={Position.Right}
                isConnectable={data.connectable}
            />
        </div>
    );
});

/** The panel tabs the toolbar opens, in the panel's order. */
const TOOLBAR_TABS: { tab: string; label: string; icon: LucideIcon }[] = [
    { tab: 'deployments', label: 'Deployments', icon: Rocket },
    { tab: 'logs', label: 'Logs', icon: ScrollText },
    { tab: 'console', label: 'Console', icon: SquareTerminal },
    { tab: 'settings', label: 'Settings', icon: Settings },
];

/**
 * The node's floating toolbar (design-system §13.8 "Canvas node"): icon
 * buttons for the Deployments, Logs, Console and Settings tabs of the
 * panel, the live URL and the node's menu, one click from the canvas. Each
 * names itself in a tooltip. It sits inside the node so moving the pointer
 * onto it keeps it open, fades in on hover, keyboard focus or selection,
 * and never starts a drag or opens the panel by itself.
 */
function NodeToolbar({
    node,
    actions,
    visible,
}: {
    node: App.Data.Canvas.CanvasNodeData;
    actions: NodeActions;
    visible: boolean;
}) {
    const stop = (event: MouseEvent) => event.stopPropagation();
    const button =
        'inline-flex size-7 items-center justify-center rounded-md text-muted-foreground hover:bg-(--hover) hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-(--signal)';
    const tabs = TOOLBAR_TABS.filter(
        ({ tab }) => tab !== 'console' || actions.canConsole,
    );

    return (
        <div
            role="toolbar"
            aria-label={`${node.name} actions`}
            className={cn(
                'service-node-toolbar nodrag nopan absolute -top-10 right-0 z-10 pb-2',
                'opacity-0 transition-opacity duration-fast ease-enter motion-reduce:transition-none',
                'group-hover/node:opacity-100 focus-within:opacity-100',
                visible && 'opacity-100',
            )}
            onClick={stop}
            onPointerDown={stop}
            onDoubleClick={stop}
        >
            <div className="flex items-center gap-0.5 rounded-lg border bg-card p-0.5 shadow-float">
                {tabs.map(({ tab, label, icon: Icon }) => (
                    <ToolbarButton
                        key={tab}
                        className={button}
                        label={label}
                        onClick={() => actions.openService(node.id, tab)}
                    >
                        <Icon aria-hidden="true" className="size-3.5" />
                    </ToolbarButton>
                ))}
                {node.domain && (
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <a
                                href={`https://${node.domain}`}
                                target="_blank"
                                rel="noreferrer"
                                className={button}
                                aria-label={`Open ${node.domain} in a new tab`}
                            >
                                <ArrowUpRight
                                    aria-hidden="true"
                                    className="size-3.5"
                                />
                            </a>
                        </TooltipTrigger>
                        <TooltipContent>Open {node.domain}</TooltipContent>
                    </Tooltip>
                )}
                <ToolbarButton
                    className={button}
                    label="More actions"
                    ariaLabel={`More actions for ${node.name}`}
                    onClick={(event) => {
                        const rect =
                            event.currentTarget.getBoundingClientRect();
                        actions.openMenu(node.id, {
                            x: rect.left,
                            y: rect.bottom + 4,
                        });
                    }}
                >
                    <Ellipsis aria-hidden="true" className="size-4" />
                </ToolbarButton>
            </div>
        </div>
    );
}

function ToolbarButton({
    label,
    ariaLabel = label,
    className,
    onClick,
    children,
}: {
    label: string;
    ariaLabel?: string;
    className: string;
    onClick: (event: MouseEvent<HTMLButtonElement>) => void;
    children: ReactNode;
}) {
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <button
                    type="button"
                    aria-label={ariaLabel}
                    className={className}
                    onClick={(event) => {
                        event.stopPropagation();
                        onClick(event);
                    }}
                >
                    {children}
                </button>
            </TooltipTrigger>
            <TooltipContent>{label}</TooltipContent>
        </Tooltip>
    );
}
