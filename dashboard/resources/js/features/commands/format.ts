import { presented } from '@/components/status';
import { formatDuration } from '@/lib/format';
import { vocabulary } from '@/lib/vocabulary';

type Run = App.Data.Commands.CommandRunData;

export const runStatus = presented(vocabulary.Commands.CommandRunStatus);

/** "10 minutes", "1 hour": the "Stops after" choices. */
export function timeoutLabel(seconds: number): string {
    if (seconds % 3600 === 0) {
        const hours = seconds / 3600;

        return hours === 1 ? '1 hour' : `${hours} hours`;
    }

    const minutes = Math.round(seconds / 60);

    return minutes === 1 ? '1 minute' : `${minutes} minutes`;
}

/** Who ran it, as a list row says it. */
export function ranBy(run: Run): string {
    const name = run.ranBy ?? 'A former member';

    return run.viaApi ? `${name} with an API token` : name;
}

/** Short duration for list rows: "0.4s" under a second, else "1m 2s". */
export function shortRunDuration(run: Run): string | null {
    if (run.durationMs === null) {
        return null;
    }

    return run.durationMs < 1000
        ? `${(run.durationMs / 1000).toFixed(1)}s`
        : formatDuration(Math.round(run.durationMs / 1000));
}

/**
 * One sentence on how a run ended, when the status word alone doesn't say
 * it; null for a clean success and while it runs.
 */
export function outcome(run: Run): string | null {
    switch (run.status) {
        case 'failed':
            return `The command exited with code ${run.exitCode ?? '?'}.`;
        case 'timed_out':
            return `It was stopped after ${timeoutLabel(run.timeoutSeconds)}, its time limit.`;
        case 'cancelled':
            return run.cancelledBy
                ? `Cancelled by ${run.cancelledBy}.`
                : 'It was cancelled.';
        case 'error':
            return run.error ?? 'The server couldn’t run it.';
        default:
            return null;
    }
}
