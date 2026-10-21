import { LoaderCircle, TriangleAlert, X } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';

/**
 * Status icon + word, as in the dashboard (docs/design-system.md §3.3):
 * colour never stands alone.
 */
export type StatusState = 'active' | 'progress' | 'failed' | 'idle' | 'warning' | 'staged';

export function StatusIcon({ state, className }: { state: StatusState; className?: string }) {
    if (state === 'progress') {
        return (
            <LoaderCircle
                aria-hidden="true"
                className={cn('size-3.5 shrink-0 text-status-progress motion-safe:animate-spin', className)}
            />
        );
    }
    if (state === 'failed') {
        return (
            <X aria-hidden="true" strokeWidth={2.5} className={cn('size-3.5 shrink-0 text-status-error', className)} />
        );
    }
    if (state === 'warning') {
        return <TriangleAlert aria-hidden="true" className={cn('size-3.5 shrink-0 text-status-progress', className)} />;
    }
    return (
        <span aria-hidden="true" className={cn('flex size-3.5 shrink-0 items-center justify-center', className)}>
            <span
                className={cn(
                    'size-2 rounded-full',
                    state === 'active'
                        ? 'bg-status-ok'
                        : state === 'staged'
                          ? 'border-[1.5px] border-signal'
                          : 'border-[1.5px] border-status-idle',
                )}
            />
        </span>
    );
}

export function Status({
    state,
    label,
    detail,
    className,
}: {
    state: StatusState;
    label: string;
    detail?: ReactNode;
    className?: string;
}) {
    return (
        <span className={cn('inline-flex min-w-0 items-center gap-1.5', className)}>
            <StatusIcon state={state} />
            <span className="truncate">{label}</span>
            {detail ? <span className="truncate text-muted-foreground">· {detail}</span> : null}
        </span>
    );
}

export function StatusPill({ state, label, className }: { state: StatusState; label: string; className?: string }) {
    const soft: Record<StatusState, string> = {
        active: 'bg-status-ok-soft text-status-ok',
        progress: 'bg-status-progress-soft text-status-progress',
        failed: 'bg-status-error-soft text-status-error',
        warning: 'bg-status-progress-soft text-status-progress',
        idle: 'bg-hover text-muted-foreground',
        staged: 'bg-signal-soft text-signal',
    };
    return (
        <span
            className={cn(
                'inline-flex h-6 items-center gap-1.5 rounded-full pr-2.5 pl-2 type-label',
                soft[state],
                className,
            )}
        >
            <StatusIcon state={state} className="size-3" />
            {label}
        </span>
    );
}
