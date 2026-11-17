import { Check, CircleDashed, X } from 'lucide-react';
import { formatDuration, relativeTime } from '@/lib/format';

/**
 * A cron service's recent runs, newest first (G19): when each started, how
 * long it took and how it ended, in words for a failed run ("Exited with
 * code 1"). Each state has its own icon and word, not only a colour.
 */
export function CronRuns({ runs }: { runs: App.Data.Services.CronRunData[] }) {
    if (runs.length === 0) {
        return null;
    }

    return (
        <div className="flex flex-col gap-1 rounded-lg bg-muted px-3 py-2 type-small text-muted-foreground">
            <p className="text-foreground">Recent runs</p>
            <ul className="flex flex-col gap-0.5">
                {runs.map((run, index) => (
                    <li
                        key={`${run.startedAt ?? run.finishedAt ?? ''}-${index}`}
                        className="flex min-w-0 items-start gap-2"
                    >
                        <RunIcon run={run} />
                        <span className="min-w-0">{describe(run)}</span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

function RunIcon({ run }: { run: App.Data.Services.CronRunData }) {
    const className = 'mt-0.5 size-3.5 shrink-0';

    if (run.running) {
        return <CircleDashed aria-hidden="true" className={className} />;
    }

    return run.failed ? (
        <X aria-hidden="true" className={`${className} text-status-error`} />
    ) : (
        <Check aria-hidden="true" className={className} />
    );
}

function describe(run: App.Data.Services.CronRunData): string {
    const when = run.startedAt
        ? `Started ${relativeTime(run.startedAt)}`
        : null;
    const took =
        run.startedAt && run.finishedAt
            ? formatDuration(
                  Math.max(
                      0,
                      Math.round(
                          (new Date(run.finishedAt).getTime() -
                              new Date(run.startedAt).getTime()) /
                              1000,
                      ),
                  ),
              )
            : null;

    if (run.running) {
        return `${when ?? 'Started'}, still running`;
    }

    const outcome = run.failed
        ? `failed${run.exitMeaning ? `: ${run.exitMeaning}` : ''}`
        : 'succeeded';
    const head = when ?? `Ended ${relativeTime(run.finishedAt ?? '')}`;

    return `${head}, ${outcome}${took ? ` after ${took}` : ''}`;
}
