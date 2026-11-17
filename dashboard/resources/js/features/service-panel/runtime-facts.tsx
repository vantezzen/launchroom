import { CircleAlert, RotateCcw } from 'lucide-react';
import { relativeTime } from '@/lib/format';

/**
 * The live version's restarts and last exit, with the reason in words
 * (G7): "Restarted 3 times since this version went live (out of memory)",
 * then "Last exit 2 hours ago, replica 2 on server-2. Exited with code 137:
 * it ran out of memory." A crash the restart policy healed stays visible.
 */
export function RuntimeFacts({
    facts,
}: {
    facts: App.Data.Canvas.ServiceRuntimeFactsData;
}) {
    const when = facts.lastExitAt ? relativeTime(facts.lastExitAt) : null;
    const replica = facts.lastExitWhere
        ? facts.lastExitWhere.charAt(0).toLowerCase() +
          facts.lastExitWhere.slice(1)
        : null;
    const where = [when, replica].filter(Boolean).join(', ');

    const finding = facts.finding;

    return (
        <div className="flex flex-col gap-2">
            {finding && (
                <div className="flex items-start gap-2 rounded-lg bg-status-progress-soft px-3 py-2 type-small text-muted-foreground">
                    <CircleAlert
                        aria-hidden="true"
                        className="mt-0.5 size-3.5 shrink-0 text-status-progress"
                    />
                    <div className="flex min-w-0 flex-col gap-0.5">
                        <p className="text-foreground">
                            Seen in the logs: {finding.title}
                        </p>
                        <p>{`${finding.cause} ${finding.fix}`}</p>
                        <p
                            className="truncate type-mono-small"
                            title={finding.line}
                        >
                            {finding.line}
                        </p>
                    </div>
                </div>
            )}
            {(facts.restartText || facts.lastExit || facts.recentCrashes) && (
                <div className="flex items-start gap-2 rounded-lg bg-muted px-3 py-2 type-small text-muted-foreground">
                    <RotateCcw
                        aria-hidden="true"
                        className="mt-0.5 size-3.5 shrink-0"
                    />
                    <div className="flex min-w-0 flex-col gap-0.5">
                        {facts.restartText && (
                            <p className="text-foreground">
                                {facts.restartText}
                            </p>
                        )}
                        {facts.recentCrashes && (
                            <p
                                className={
                                    facts.restartText ? '' : 'text-foreground'
                                }
                            >
                                {facts.recentCrashes}
                            </p>
                        )}
                        {facts.lastExit && (
                            <p>
                                {`Last exit${where ? ` ${where}` : ''}. ${facts.lastExit}`}
                                {facts.advice && ` ${facts.advice}`}
                            </p>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}
