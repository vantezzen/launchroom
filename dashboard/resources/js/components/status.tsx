import { TriangleAlert, X } from 'lucide-react';
import type { ReactNode } from 'react';
import { MetaDivider } from '@/components/patterns/meta-line';
import { ProgressRing } from '@/components/progress-ring';
import { cn } from '@/lib/utils';

/**
 * Visual state behind a status word (design-system §3.3):
 * - `active`   filled green dot: Live, Running, Online, Succeeded
 * - `progress` amber spinner: Deploying, Building, Waiting
 * - `failed`   red ✕: Failed, Last deploy failed, Unreachable
 * - `warning`  amber ⚠: degraded, single point of failure, stale backup
 * - `idle`     hollow grey dot: Stopped, Superseded, Skipped, Not deployed
 * - `staged`   hollow signal dot: Ready to promote, has staged changes
 */
export type StatusState =
    | 'active'
    | 'progress'
    | 'failed'
    | 'idle'
    | 'warning'
    | 'staged';

const tone: Record<StatusState, string> = {
    active: 'text-status-ok',
    progress: 'text-status-progress',
    failed: 'text-status-error',
    warning: 'text-status-progress',
    idle: 'text-status-idle',
    staged: 'text-signal',
};

const soft: Record<StatusState, string> = {
    active: 'bg-status-ok-soft text-status-ok',
    progress: 'bg-status-progress-soft text-status-progress',
    failed: 'bg-status-error-soft text-status-error',
    warning: 'bg-status-progress-soft text-status-progress',
    idle: 'bg-hover text-muted-foreground',
    staged: 'bg-signal-soft text-signal',
};

/** The non-text cue for a state: dot, spinner, ✕ or ⚠ (aria-hidden). */
export function StatusIcon({
    state,
    className,
}: {
    state: StatusState;
    className?: string;
}) {
    switch (state) {
        case 'progress':
            return <ProgressRing className={cn('size-3.5', className)} />;
        case 'failed':
            return (
                <X
                    aria-hidden="true"
                    strokeWidth={2.5}
                    className={cn('size-3.5 shrink-0', tone.failed, className)}
                />
            );
        case 'warning':
            return (
                <TriangleAlert
                    aria-hidden="true"
                    className={cn('size-3.5 shrink-0', tone.warning, className)}
                />
            );
        default:
            return (
                <span
                    aria-hidden="true"
                    className={cn(
                        'flex size-3.5 shrink-0 items-center justify-center',
                        className,
                    )}
                >
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
}

type StatusProps = {
    state: StatusState;
    /** The status word from §3.3 ("Live", "Deploying", "Unreachable"). */
    label: string;
    /** Quiet detail after the word ("Building, 41s", "for 8 min"). */
    detail?: ReactNode;
    className?: string;
};

/**
 * State with an icon and a word: colour never stands alone
 * (design-system §3.3).
 */
export function Status({ state, label, detail, className }: StatusProps) {
    return (
        <span
            className={cn(
                'inline-flex min-w-0 items-center gap-1.5 type-body',
                className,
            )}
        >
            <StatusIcon state={state} />
            <span className="truncate">{label}</span>
            {detail && (
                <>
                    <MetaDivider />
                    <span className="truncate text-muted-foreground">
                        {detail}
                    </span>
                </>
            )}
        </span>
    );
}

/**
 * Pill variant for page headers and cards: soft background in the status
 * tone, icon + word.
 */
export function StatusPill({ state, label, detail, className }: StatusProps) {
    return (
        <span
            className={cn(
                'inline-flex h-6 max-w-full min-w-0 items-center gap-1.5 rounded-full pr-2.5 pl-2 type-label',
                soft[state],
                className,
            )}
        >
            <StatusIcon state={state} className="size-3" />
            <span className="truncate">{label}</span>
            {detail && (
                <>
                    <MetaDivider className="bg-current opacity-30" />
                    <span className="truncate font-normal">{detail}</span>
                </>
            )}
        </span>
    );
}

type Mapped = { state: StatusState; label: string };

/**
 * Every existing status enum mapped onto the unified vocabulary (§3.3).
 * Pages use these instead of local label tables.
 */
export const deploymentStatus: Record<App.Enums.DeploymentStatus, Mapped> = {
    queued: { state: 'progress', label: 'Queued' },
    building: { state: 'progress', label: 'Deploying' },
    releasing: { state: 'progress', label: 'Deploying' },
    rolling_out: { state: 'progress', label: 'Deploying' },
    health_checking: { state: 'progress', label: 'Deploying' },
    routing: { state: 'progress', label: 'Deploying' },
    ready: { state: 'staged', label: 'Ready to promote' },
    active: { state: 'active', label: 'Live' },
    failed: { state: 'failed', label: 'Failed' },
    cancelled: { state: 'idle', label: 'Cancelled' },
    superseded: { state: 'idle', label: 'Superseded' },
};

export const serverStatus: Record<App.Enums.ServerStatus, Mapped> = {
    online: { state: 'active', label: 'Online' },
    pending_approval: { state: 'staged', label: 'Waiting for approval' },
    unreachable: { state: 'failed', label: 'Unreachable' },
    offline: { state: 'failed', label: 'Offline' },
    revoked: { state: 'idle', label: 'Revoked' },
};

export const stepStatus: Record<App.Enums.DeploymentStepStatus, Mapped> = {
    running: { state: 'progress', label: 'Running' },
    succeeded: { state: 'active', label: 'Succeeded' },
    failed: { state: 'failed', label: 'Failed' },
    skipped: { state: 'idle', label: 'Skipped' },
};

export const backupRunStatus: Record<
    App.Enums.Backups.BackupRunStatus,
    Mapped
> = {
    pending: { state: 'progress', label: 'Waiting' },
    running: { state: 'progress', label: 'Backing up' },
    succeeded: { state: 'active', label: 'Succeeded' },
    failed: { state: 'failed', label: 'Failed' },
};

export const previewStatus: Record<App.Enums.Previews.PreviewStatus, Mapped> = {
    skipped: { state: 'idle', label: 'Skipped' },
    awaiting_approval: { state: 'staged', label: 'Waiting for approval' },
    queued: { state: 'progress', label: 'Queued' },
    preparing: { state: 'progress', label: 'Deploying' },
    running: { state: 'active', label: 'Live' },
    failed: { state: 'failed', label: 'Failed' },
    closed: { state: 'idle', label: 'Closed' },
    expired: { state: 'idle', label: 'Expired' },
};

export const healthCheckState: Record<App.Enums.HealthCheckState, Mapped> = {
    ok: { state: 'active', label: 'OK' },
    warning: { state: 'warning', label: 'Warning' },
    critical: { state: 'failed', label: 'Failing' },
    unknown: { state: 'idle', label: 'Unknown' },
};
