import { BaseEdge, EdgeLabelRenderer, getBezierPath } from '@xyflow/react';
import { Copy, TriangleAlert } from 'lucide-react';
import type { EdgeProps } from '@xyflow/react';
import { cn } from '@/lib/utils';
import type { ConnectionFlowEdge } from './types';

/**
 * "DATABASE_URL", or "DB_* (6)" when a connection injects a family of
 * variables.
 */
export function edgeLabel(variables: string[]): string {
    if (variables.length === 0) {
        return '';
    }

    if (variables.length === 1) {
        return variables[0];
    }

    const prefixes = variables.map((key) => key.split('_')[0]);
    const shared = prefixes.every((prefix) => prefix === prefixes[0]);

    return shared
        ? `${prefixes[0]}_* (${variables.length})`
        : `${variables[0]} +${variables.length - 1}`;
}

/**
 * A connection edge with its injected variables as the label (docs/08
 * §3.3). Process links of workers/crons are dotted and unlabelled.
 */
export function ConnectionEdge({
    id,
    sourceX,
    sourceY,
    targetX,
    targetY,
    sourcePosition,
    targetPosition,
    markerEnd,
    data,
}: EdgeProps<ConnectionFlowEdge>) {
    const [path, labelX, labelY] = getBezierPath({
        sourceX,
        sourceY,
        targetX,
        targetY,
        sourcePosition,
        targetPosition,
    });

    const label =
        data?.type === 'connection' ? edgeLabel(data.variables) : null;

    return (
        <>
            <BaseEdge id={id} path={path} markerEnd={markerEnd} />
            {label && data && (
                <EdgeLabelRenderer>
                    <button
                        type="button"
                        className={cn(
                            'nodrag nopan pointer-events-auto absolute flex flex-col items-center rounded-full border bg-card px-2 py-0.5 type-mono-small text-muted-foreground shadow-card',
                            'transition-colors duration-fast hover:border-border-strong hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                            data.staged &&
                                'border-dashed border-signal text-signal',
                            data.badge && 'rounded-[10px]',
                            data.badge?.tone === 'danger' &&
                                'border-status-error/50',
                        )}
                        style={{
                            transform: `translate(-50%, -50%) translate(${labelX}px, ${labelY}px)`,
                        }}
                        aria-label={`Connection: ${data.variables.join(', ')}${data.staged ? ' (staged)' : ''}${data.badge ? ` (${data.badge.label})` : ''}`}
                        onClick={(event) => {
                            const rect =
                                event.currentTarget.getBoundingClientRect();
                            data.onOpen?.(data.id, {
                                x: rect.left + rect.width / 2,
                                y: rect.bottom,
                            });
                        }}
                    >
                        {label}
                        {data.badge && (
                            <span
                                className={cn(
                                    'mt-0.5 flex items-center gap-1 font-sans type-small',
                                    data.badge.tone === 'danger'
                                        ? 'text-status-error'
                                        : 'text-muted-foreground',
                                )}
                            >
                                {data.badge.tone === 'danger' ? (
                                    <TriangleAlert
                                        aria-hidden="true"
                                        className="size-3"
                                    />
                                ) : (
                                    <Copy
                                        aria-hidden="true"
                                        className="size-3"
                                    />
                                )}
                                {data.badge.tone === 'danger'
                                    ? 'shared'
                                    : 'cloned'}
                            </span>
                        )}
                    </button>
                </EdgeLabelRenderer>
            )}
        </>
    );
}
