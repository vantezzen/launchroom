import { SquareTerminal } from 'lucide-react';
import type { ReactNode } from 'react';
import { Callout } from '@/components/patterns/callout';
import { CopyValue } from '@/components/copy-value';

/**
 * Where a move can stall on the new machine (joining, the hand-over, the
 * takeover): the one command that continues it from wherever it stopped,
 * run in the install folder there. Safe to run again.
 */
export function ContinueCommand({
    command,
    title = 'Continue on the new machine',
    children,
}: {
    command: string | null | undefined;
    title?: string;
    /** When to run it, one sentence. */
    children?: ReactNode;
}) {
    if (!command) {
        return null;
    }

    return (
        <Callout
            title={title}
            icon={<SquareTerminal aria-hidden="true" />}
            className="bg-transparent"
        >
            <p>
                {children ??
                    'Run this in the install folder on the new machine. It picks the move up where it stopped and is safe to run again.'}
            </p>
            <CopyValue
                value={command}
                label="continue command"
                className="bg-card text-foreground"
            />
        </Callout>
    );
}

/**
 * A step's main button with the reason it can't run yet right beside it
 * (design-system §6: a blocked action stays in place and says why).
 */
export function ActionRow({
    children,
    reason,
}: {
    children: ReactNode;
    /** Why the button is disabled; null when it can run. */
    reason?: ReactNode;
}) {
    return (
        <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-4">
            <div className="shrink-0">{children}</div>
            {reason && (
                <p className="type-small text-muted-foreground">{reason}</p>
            )}
        </div>
    );
}
