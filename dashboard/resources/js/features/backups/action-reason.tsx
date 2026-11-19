import { Link, router } from '@inertiajs/react';
import { createContext, useContext } from 'react';
import { cn } from '@/lib/utils';
import { index as backupsPage } from '@/routes/backups';
import type { BackupDisabled } from './page-types';

/**
 * In-page fixes a disabled action can point at (`fixHref` '#setup',
 * '#setup-launchroom', '#recovery-kit', '#storage'), handled by the
 * Backups page. Anywhere else they open the Backups page at that fix.
 */
export const BackupsFixContext = createContext<(hash: string) => void>((hash) =>
    router.visit(`${backupsPage().url}${hash}`),
);

/**
 * Why an action can't run, and the fix, next to the control (never only a
 * tooltip: a disabled button gets no hover). One line: the server's
 * message, then the fix as a link.
 */
export function ActionReason({
    reason,
    className,
}: {
    reason: BackupDisabled;
    className?: string;
}) {
    const go = useContext(BackupsFixContext);
    const fixClass =
        'font-medium text-signal underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none rounded-sm';

    return (
        <p className={cn('type-small text-muted-foreground', className)}>
            {reason.message}
            {reason.fixLabel !== null && reason.fixHref !== null && (
                <>
                    {' '}
                    {reason.fixHref.startsWith('#') ? (
                        <button
                            type="button"
                            className={fixClass}
                            onClick={() => go(reason.fixHref ?? '')}
                        >
                            {reason.fixLabel}
                        </button>
                    ) : (
                        <Link href={reason.fixHref} className={fixClass}>
                            {reason.fixLabel}
                        </Link>
                    )}
                </>
            )}
        </p>
    );
}
