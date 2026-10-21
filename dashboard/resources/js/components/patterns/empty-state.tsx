import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type EmptyStateProps = {
    /** 48 px muted lucide icon… */
    icon?: LucideIcon;
    /** …or a ghost of the object that will appear (dashed card, silhouette). */
    ghost?: ReactNode;
    /** Optional short title above the line. */
    title?: ReactNode;
    /** One line: what will be here + how it gets here. */
    children: ReactNode;
    /** One action (usually a Button). */
    action?: ReactNode;
    /** Optional quiet secondary link. */
    secondary?: ReactNode;
    /** `framed` draws a dashed outline (inside a page); `plain` for sheets and cards. */
    variant?: 'framed' | 'plain';
    className?: string;
};

/**
 * Empty state (design-system §6, direction §9.16): icon or ghost, one line,
 * one action. "No previews yet. Open a pull request to get one."
 */
export function EmptyState({
    icon: Icon,
    ghost,
    title,
    children,
    action,
    secondary,
    variant = 'framed',
    className,
}: EmptyStateProps) {
    return (
        <div
            data-slot="empty-state"
            className={cn(
                'flex flex-col items-center justify-center gap-4 px-6 py-14 text-center',
                variant === 'framed' && 'rounded-xl border border-dashed',
                className,
            )}
        >
            {ghost ??
                (Icon && (
                    <Icon
                        aria-hidden="true"
                        strokeWidth={1.25}
                        className="size-12 text-faint"
                    />
                ))}
            <div className="flex max-w-[52ch] flex-col gap-1">
                {title && <p className="type-heading">{title}</p>}
                <p className="type-body text-muted-foreground">{children}</p>
            </div>
            {(action || secondary) && (
                <div className="flex flex-wrap items-center justify-center gap-3">
                    {action}
                    {secondary}
                </div>
            )}
        </div>
    );
}
