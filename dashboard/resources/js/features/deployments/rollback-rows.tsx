import { ArrowDown } from 'lucide-react';
import { StatusIcon } from '@/components/status';
import { relativeTime, shortSha } from '@/lib/format';
import { firstLine } from './format';

/** What a rollback row shows of a deployment (a summary or a ref). */
export type RollbackSide = {
    commitSha: string | null;
    commitMessage: string | null;
    queuedAt: string;
};

function Row({
    label,
    side,
    live,
}: {
    label: string;
    side: RollbackSide;
    live: boolean;
}) {
    return (
        <div className="flex min-w-0 flex-col gap-0.5">
            <span className="type-small text-muted-foreground">{label}</span>
            <span className="flex min-w-0 items-center gap-1.5 type-small">
                <StatusIcon
                    state={live ? 'active' : 'idle'}
                    className="size-3 shrink-0"
                />
                {side.commitSha && (
                    <span className="shrink-0 type-mono-small">
                        {shortSha(side.commitSha)}
                    </span>
                )}
                <span className="truncate">
                    {firstLine(side.commitMessage) ?? 'No commit message'}
                </span>
                <span className="ml-auto shrink-0 pl-2 text-muted-foreground">
                    {relativeTime(side.queuedAt)}
                </span>
            </span>
        </div>
    );
}

/**
 * Rollback confirm body (DPL-8, flows §7): what serves now, then what
 * traffic goes back to, so the choice is visible and not only described.
 */
export function RollbackRows({
    current,
    target,
}: {
    current: RollbackSide | null;
    target: RollbackSide;
}) {
    return (
        <div className="flex flex-col gap-1.5 rounded-lg border bg-recessed px-3 py-2.5">
            {current && (
                <>
                    <Row label="Now live" side={current} live />
                    <ArrowDown
                        aria-hidden="true"
                        className="size-3.5 text-muted-foreground"
                    />
                </>
            )}
            <Row label="Roll back to" side={target} live={false} />
        </div>
    );
}
