import { StepIcon, STEP_STATUS_LABEL } from '@/components/steps/step-icon';
import { Progress } from '@/components/ui/progress';
import { useNow } from '@/hooks/use-now';
import { cn } from '@/lib/utils';
import { elapsedLine, runLabel, runPercent, runSteps } from './overview-model';
import type { BackupItem, BackupRunProgress } from './page-types';

/**
 * A running or queued backup under its row (design: the first-deploy
 * card): the steps with the running one's bytes, the time so far against
 * the usual time, and a bar only when the total is real. Queued runs say
 * what they wait for instead.
 */
export function RunProgress({
    kind,
    run,
    className,
}: {
    kind: BackupItem['kind'];
    run: BackupRunProgress;
    className?: string;
}) {
    const now = useNow(run.startedAt !== null);
    const percent = runPercent(run);
    const elapsed = elapsedLine(run, now);
    const queued = run.phase === 'queued' || run.phase === 'waiting';

    return (
        <div
            className={cn(
                'flex flex-col gap-2 rounded-lg bg-recessed px-3.5 py-3',
                className,
            )}
            aria-live="polite"
        >
            {queued ? (
                <p className="type-small text-muted-foreground">
                    {runLabel(run)}.{' '}
                    {run.waitingFor !== null
                        ? run.waitingBehind != null
                            ? `${run.waitingBehind} is backing up on ${run.waitingFor} first. One backup or restore runs per server at a time.`
                            : `One backup or restore runs on ${run.waitingFor} at a time, so this one starts after the current one.`
                        : 'It starts in a moment.'}
                </p>
            ) : (
                <ol className="flex flex-col gap-1.5">
                    {runSteps(kind, run).map((step) => (
                        <li
                            key={step.id}
                            className="flex min-w-0 items-center gap-2.5"
                        >
                            <StepIcon status={step.status} size={16} />
                            <span className="sr-only">
                                {STEP_STATUS_LABEL[step.status]}:
                            </span>
                            <span
                                className={cn(
                                    'min-w-0 flex-1 type-small',
                                    step.status === 'pending'
                                        ? 'text-muted-foreground'
                                        : 'text-foreground',
                                )}
                            >
                                {step.name}
                            </span>
                            {step.detail !== null && (
                                <span className="shrink-0 type-small text-muted-foreground tabular-nums">
                                    {step.detail}
                                </span>
                            )}
                        </li>
                    ))}
                </ol>
            )}
            {percent !== null && (
                <Progress
                    value={percent}
                    tone="progress"
                    aria-label="Backup progress"
                />
            )}
            {elapsed !== null && (
                <p className="type-small text-muted-foreground tabular-nums">
                    {elapsed}
                </p>
            )}
        </div>
    );
}
