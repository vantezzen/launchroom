import { LoaderCircle } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type LiveWaitProps = {
    /** Device art (LED off) or a logo. */
    media?: ReactNode;
    /** What's happening now: "Waiting for fra-1 to connect". */
    status: ReactNode;
    /** Roughly how long: "Usually under a minute". */
    hint?: ReactNode;
    /** Seconds after which `fallback` appears. */
    timeoutSeconds?: number;
    /** The "not working?" path. */
    fallback?: ReactNode;
    className?: string;
};

/**
 * Every wait is live (design-system §1.6): what's happening, roughly how
 * long, updates by itself, and a "not working?" path after a timeout.
 */
export function LiveWait({
    media,
    status,
    hint,
    timeoutSeconds = 90,
    fallback,
    className,
}: LiveWaitProps) {
    const [overdue, setOverdue] = useState(false);

    useEffect(() => {
        const timer = window.setTimeout(
            () => setOverdue(true),
            timeoutSeconds * 1000,
        );

        return () => window.clearTimeout(timer);
    }, [timeoutSeconds]);

    return (
        <div
            className={cn(
                'flex flex-col items-center gap-4 rounded-xl bg-recessed px-6 py-8 text-center',
                className,
            )}
        >
            {media}
            <div className="flex flex-col items-center gap-1">
                <p
                    role="status"
                    aria-live="polite"
                    className="flex items-center gap-2 type-label"
                >
                    <LoaderCircle
                        aria-hidden="true"
                        className="size-4 text-status-progress motion-safe:animate-spin"
                    />
                    {status}
                </p>
                {hint && (
                    <p className="type-small text-muted-foreground">{hint}</p>
                )}
            </div>
            {overdue && fallback && (
                <div className="animate-in type-body text-muted-foreground duration-base fade-in-0">
                    {fallback}
                </div>
            )}
        </div>
    );
}
