import { Handle, Position } from '@xyflow/react';
import { HardDrive, TriangleAlert } from 'lucide-react';
import type { NodeProps } from '@xyflow/react';
import { memo } from 'react';
import { Sparkline } from '@/components/charts/sparkline';
import { Status } from '@/components/status';
import { BackupHealthStatus } from '@/features/backups/backup-health';
import { sharedElement } from '@/lib/motion';
import { cn } from '@/lib/utils';
import { NODE_WIDTH } from './auto-layout';
import { formatMemory, useServiceMetrics } from './canvas-metrics';
import { compactAge } from './format';
import { ServiceGlyph } from './service-glyph';
import type { ServiceFlowNode } from './types';

/** The staged chip: one word (design-system §3.3). */
export const stagedChip: Record<App.Enums.ChangeEffect, string> = {
    new: 'New',
    remove: 'Removed',
    rebuild: 'Changed',
    restart: 'Changed',
    route: 'Changed',
    none: 'Changed',
};

export function StagedChip({ effect }: { effect: App.Enums.ChangeEffect }) {
    return (
        <span className="inline-flex h-5 shrink-0 items-center rounded-full border border-dashed border-signal bg-signal-soft px-2 type-small text-signal">
            {stagedChip[effect]}
            <span className="sr-only"> (staged)</span>
        </span>
    );
}

/** "×2 on fra-1, hel-1", "Scaled to zero". */
export function placementLine(
    node: App.Data.Canvas.CanvasNodeData,
): string | null {
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

/**
 * A service on the canvas (design-system §6 "Canvas node", direction §9.4):
 * logo, name and one subtitle; the status line; one key fact (address or
 * backup); a footer strip with the CPU sparkline, memory and placement.
 * Selected: 2 px foreground ring. Staged: dashed signal outline + chip.
 * Degraded: amber ring + chip. Failed: only the status line turns red.
 */
export const ServiceNode = memo(function ServiceNode({
    data,
    selected,
}: NodeProps<ServiceFlowNode>) {
    const usage = useServiceMetrics(data.id);
    const staged = data.staged !== null;
    const degraded = data.placementIssue !== null;
    const fact =
        data.domain ?? data.privateAddress?.replace(/^https?:\/\//, '');
    const placement = placementLine(data);
    const cpu = usage?.cpuCores ?? [];
    const hasCpu = cpu.some((value) => value !== null && value > 0);
    const memory = usage?.currentMemMb ?? null;
    const showFooter =
        hasCpu ||
        memory !== null ||
        placement !== null ||
        data.volume !== null ||
        data.kind === 'cron';

    return (
        <div
            className={cn(
                'service-node flex flex-col rounded-[14px] border bg-card text-left shadow-card',
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

            <div className="flex flex-col gap-3 p-4">
                <div className="flex items-start gap-3">
                    <ServiceGlyph
                        logo={data.logo}
                        style={
                            selected ? undefined : sharedElement('svc', data.id)
                        }
                    />
                    <div className="flex min-w-0 flex-1 flex-col">
                        <span className="truncate type-label text-[14px] leading-5 font-semibold">
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
                    <Status
                        state={data.status.state}
                        label={data.status.label}
                        detail={
                            data.status.since
                                ? compactAge(data.status.since)
                                : undefined
                        }
                        className="type-small"
                    />
                    {data.kind === 'database' && data.backup ? (
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
                    {hasCpu && (
                        <Sparkline
                            values={cpu}
                            label={`${data.name} CPU, last hour`}
                            height={16}
                            min={0}
                            className="w-16 shrink-0"
                        />
                    )}
                    {memory !== null && (
                        <span className="shrink-0">{formatMemory(memory)}</span>
                    )}
                    {data.volume && memory === null && (
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
                    {data.kind === 'cron' && <span>Cron</span>}
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
