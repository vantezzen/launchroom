import { Check, Minus, RotateCcw, X } from 'lucide-react';
import { StatusIcon } from '@/components/status';
import type { StatusState } from '@/components/status';
import { Status } from '@/components/status';
import { TimeAgo } from '@/components/time-ago';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import type { CopyRun, CopyStep } from './api';

const heading: Record<App.Enums.Transfers.CopyRunStatus, string> = {
    exporting: 'Copying',
    importing: 'Copying',
    succeeded: 'Copied',
    failed: 'Copy failed',
    cancelled: 'Copy cancelled',
};

function runState(status: App.Enums.Transfers.CopyRunStatus): StatusState {
    switch (status) {
        case 'succeeded':
            return 'active';
        case 'failed':
            return 'failed';
        case 'cancelled':
            return 'idle';
        default:
            return 'progress';
    }
}

/**
 * The marker of a step: ✓ done, spinner now, ✕ failed, – skipped, a hollow
 * ring waiting. Colour only says what the shape already says.
 */
function StepMarker({ state }: { state: CopyStep['state'] }) {
    const base =
        'relative z-10 flex size-5 shrink-0 items-center justify-center rounded-full';

    switch (state) {
        case 'done':
            return (
                <span className={cn(base, 'bg-foreground text-background')}>
                    <Check
                        aria-hidden="true"
                        className="size-3"
                        strokeWidth={3}
                    />
                </span>
            );
        case 'current':
            return (
                <span className={cn(base, 'bg-card')}>
                    <StatusIcon state="progress" className="size-4" />
                </span>
            );
        case 'failed':
            return (
                <span
                    className={cn(
                        base,
                        'bg-status-error-soft text-status-error',
                    )}
                >
                    <X aria-hidden="true" className="size-3" strokeWidth={3} />
                </span>
            );
        case 'skipped':
        case 'cancelled':
            return (
                <span
                    className={cn(base, 'border bg-card text-muted-foreground')}
                >
                    <Minus
                        aria-hidden="true"
                        className="size-3"
                        strokeWidth={2.5}
                    />
                </span>
            );
        default:
            return (
                <span
                    className={cn(
                        base,
                        'border-[1.5px] border-border-strong bg-card',
                    )}
                />
            );
    }
}

const stateWords: Record<CopyStep['state'], string> = {
    done: 'done',
    current: 'in progress',
    waiting: 'waiting',
    failed: 'failed',
    skipped: 'skipped',
    cancelled: 'cancelled',
};

/** The steps of a copy as a vertical list with a connecting line. */
export function CopySteps({ steps }: { steps: CopyStep[] }) {
    return (
        <ol className="flex flex-col" aria-label="Steps">
            {steps.map((step, index) => (
                <li
                    key={step.key}
                    className="relative flex gap-3 pb-3 last:pb-0"
                >
                    {index < steps.length - 1 && (
                        <span
                            aria-hidden="true"
                            className={cn(
                                'absolute top-5 bottom-0 left-[9.5px] w-px',
                                step.state === 'done'
                                    ? 'bg-foreground/40'
                                    : 'bg-border',
                            )}
                        />
                    )}
                    <StepMarker state={step.state} />
                    <div className="flex min-w-0 flex-col gap-0.5 pt-px">
                        <span
                            className={cn(
                                'type-body',
                                step.state === 'waiting' &&
                                    'text-muted-foreground',
                                step.state === 'failed' && 'text-status-error',
                            )}
                        >
                            {step.label}
                            <span className="sr-only">
                                , {stateWords[step.state]}
                            </span>
                        </span>
                        {step.detail !== null && (
                            <span
                                className="type-small break-words text-muted-foreground tabular-nums"
                                aria-live={
                                    step.state === 'current'
                                        ? 'polite'
                                        : undefined
                                }
                            >
                                {step.detail}
                            </span>
                        )}
                    </div>
                </li>
            ))}
        </ol>
    );
}

/**
 * A copy from another environment in the Move data section (docs/14 §14.2):
 * where from, the steps, Cancel before the data loads, and after a failed
 * import the safety snapshot to restore.
 */
export function CopyRunRow({
    run,
    cancelling,
    canRestore,
    onCancel,
    onRestore,
}: {
    run: CopyRun;
    cancelling: boolean;
    canRestore: boolean;
    onCancel: () => void;
    onRestore: (snapshot: string) => void;
}) {
    const into = run.mountPath ?? null;

    return (
        <li className="flex flex-col gap-3 px-4 py-4">
            <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                <Status
                    state={runState(run.status)}
                    label={heading[run.status]}
                    detail={`from ${run.sourceEnvironmentName}${into !== null ? ` into ${into}` : ''}`}
                />
                <span className="type-small text-muted-foreground">
                    {run.actorName !== null && <>{run.actorName}, </>}
                    {run.createdAt !== null && (
                        <TimeAgo value={run.createdAt} />
                    )}
                </span>
            </div>

            <CopySteps steps={run.steps} />

            {run.warnings.length > 0 && (
                <ul className="flex flex-col gap-0.5">
                    {run.warnings.map((warning) => (
                        <li
                            key={warning}
                            className="type-small text-muted-foreground"
                        >
                            {warning}
                        </li>
                    ))}
                </ul>
            )}

            {run.error !== null && (
                <p className="type-mono-small break-words text-muted-foreground">
                    {run.error}
                </p>
            )}

            {(run.cancellable ||
                (run.safetySnapshot !== null && canRestore)) && (
                <div className="flex flex-wrap gap-2">
                    {run.cancellable && (
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={cancelling}
                            onClick={onCancel}
                        >
                            {cancelling ? (
                                <Spinner />
                            ) : (
                                <X aria-hidden="true" />
                            )}
                            Cancel copy
                        </Button>
                    )}
                    {run.safetySnapshot !== null && canRestore && (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => onRestore(run.safetySnapshot ?? '')}
                        >
                            <RotateCcw aria-hidden="true" />
                            Restore the snapshot from before
                        </Button>
                    )}
                </div>
            )}
            {run.safetySnapshot !== null && !canRestore && (
                <p className="type-small text-muted-foreground">
                    A snapshot from before the copy was kept. An owner or admin
                    can restore it.
                </p>
            )}
        </li>
    );
}
