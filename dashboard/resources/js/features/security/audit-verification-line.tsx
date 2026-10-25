import { ShieldAlert, ShieldCheck } from 'lucide-react';
import { TimeAgo } from '@/components/time-ago';
import { cn } from '@/lib/utils';

/**
 * "Audit log verified ✓ up to <time>" (docs/09 R34): an icon and words,
 * never colour alone. A failed check names the problem when the viewer may
 * see it (instance owners).
 */
export function AuditVerificationLine({
    verification,
    className,
}: {
    verification: App.Data.Security.AuditVerificationData | null;
    className?: string;
}) {
    if (verification === null) {
        return (
            <span
                className={cn(
                    'inline-flex items-center gap-1.5 type-small text-muted-foreground',
                    className,
                )}
            >
                <ShieldCheck aria-hidden="true" className="size-3.5" />
                Audit log not checked yet
            </span>
        );
    }

    if (!verification.ok) {
        return (
            <span
                className={cn(
                    'inline-flex items-center gap-1.5 type-small text-status-error',
                    className,
                )}
            >
                <ShieldAlert aria-hidden="true" className="size-3.5" />
                <span>
                    Audit log check failed
                    {verification.problem && ':'}
                </span>
                {verification.problem && (
                    <span className="text-muted-foreground">
                        {verification.problem}
                    </span>
                )}
            </span>
        );
    }

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 type-small text-muted-foreground',
                className,
            )}
        >
            <ShieldCheck
                aria-hidden="true"
                className="size-3.5 text-status-ok"
            />
            {verification.verifiedUpTo ? (
                <span>
                    Audit log verified up to{' '}
                    <TimeAgo value={verification.verifiedUpTo} />
                </span>
            ) : (
                <span>Audit log intact, first checkpoint pending</span>
            )}
        </span>
    );
}
