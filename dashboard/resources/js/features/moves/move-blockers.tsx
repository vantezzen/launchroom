import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Callout } from '@/components/patterns/callout';
import type { MoveBlockerData } from '@/features/moves/types';

/**
 * Services still on the old server that stop the last step of a move (the
 * hand-over, Retire): each with what keeps it there and a link to it.
 */
export function MoveBlockers({
    blockers,
    children,
}: {
    blockers: MoveBlockerData[];
    /** What they stop and what to do, one or two sentences. */
    children: ReactNode;
}) {
    const server = blockers[0]?.server ?? 'the old server';

    return (
        <Callout
            tone="warning"
            title={
                blockers.length === 1
                    ? `One service is still on ${server}`
                    : `${blockers.length} services are still on ${server}`
            }
        >
            <p>{children}</p>
            <ul className="flex flex-col divide-y rounded-lg border bg-card">
                {blockers.map((blocker) => (
                    <li
                        key={`${blocker.project}:${blocker.environment}:${blocker.service}:${blocker.reason}`}
                        className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 px-3 py-2.5"
                    >
                        <span className="min-w-0 text-foreground">
                            {blocker.message}
                        </span>
                        {blocker.href !== null && (
                            <Link
                                href={blocker.href}
                                className="shrink-0 font-medium text-foreground underline underline-offset-4"
                            >
                                Open {blocker.service}
                            </Link>
                        )}
                    </li>
                ))}
            </ul>
        </Callout>
    );
}
