import { TriangleAlert, X } from 'lucide-react';
import type { ReactNode } from 'react';
import { MetaDivider } from '@/components/patterns/meta-line';
import { ProgressRing } from '@/components/progress-ring';
import { cn } from '@/lib/utils';
import { vocabulary } from '@/lib/vocabulary';

/**
 * Visual state behind a status word (design-system §3.3):
 * - `active`   filled green dot: Live, Running, Online, Succeeded
 * - `progress` amber spinner: Deploying, Building, Waiting
 * - `failed`   red ✕: Failed, Last deploy failed, Unreachable
 * - `warning`  amber ⚠: degraded, single point of failure, stale backup
 * - `idle`     hollow grey dot: Stopped, Superseded, Skipped, Not deployed
 * - `staged`   hollow signal dot: Ready to promote, has staged changes
 */
export type StatusState = App.Enums.StatusTone;

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
    /** Quiet detail after the word, behind a divider ("Building | 41s"). */
    detail?: ReactNode;
    /** How long it has been so: one phrase with the word ("Offline for 21d"). */
    since?: string | null;
    className?: string;
};

/**
 * State with an icon and a word: colour never stands alone
 * (design-system §3.3).
 */
export function Status({
    state,
    label,
    detail,
    since,
    className,
}: StatusProps) {
    return (
        <span
            className={cn(
                'inline-flex min-w-0 items-center gap-1.5 type-body',
                className,
            )}
        >
            <StatusIcon state={state} />
            {/* With a detail, the word stays whole and the detail is cut. */}
            <span className={detail ? 'shrink-0' : 'truncate'}>
                {label}
                {since && (
                    <span className="text-muted-foreground"> for {since}</span>
                )}
            </span>
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
export function StatusPill({
    state,
    label,
    detail,
    since,
    className,
}: StatusProps) {
    return (
        <span
            className={cn(
                'inline-flex h-6 max-w-full min-w-0 items-center gap-1.5 rounded-full pr-2.5 pl-2 type-label',
                soft[state],
                className,
            )}
        >
            <StatusIcon state={state} className="size-3" />
            <span className={detail ? 'shrink-0' : 'truncate'}>
                {label}
                {since && <span className="font-normal"> for {since}</span>}
            </span>
            {detail && (
                <>
                    <MetaDivider className="bg-current opacity-30" />
                    <span className="truncate font-normal">{detail}</span>
                </>
            )}
        </span>
    );
}

export type Mapped = { state: StatusState; label: string };

type Presented<K extends string> = Record<
    K,
    { label: string; tone: StatusState }
>;

/** The word and state of every case, from the PHP enum's `label()` and `tone()`. */
export function presented<K extends string>(
    table: Presented<K>,
): Record<K, Mapped> {
    return Object.fromEntries(
        Object.entries<{ label: string; tone: StatusState }>(table).map(
            ([key, { label, tone }]) => [key, { state: tone, label }],
        ),
    ) as Record<K, Mapped>;
}

export const deploymentStatus = presented(vocabulary.DeploymentStatus);
export const serverStatus = presented(vocabulary.ServerStatus);
export const healthCheckState = presented(vocabulary.HealthCheckState);
export const dnsCheckStatus = presented(vocabulary.Domains.DnsCheckStatus);
export const backupRunStatus = presented(vocabulary.Backups.BackupRunStatus);
export const previewStatus = presented(vocabulary.Previews.PreviewStatus);
