import { useId } from 'react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * One settings page (design-system §13.9 "Settings shell"): title (20/28 semibold), one
 * sentence, actions right (at most one primary), then the page's cards
 * 32 px below. `level` is the title's heading level: 1 on full pages, 3 in
 * the service panel (under the panel's own headings).
 */
export function SettingsPage({
    title,
    description,
    actions,
    level = 1,
    labelId,
    className,
    children,
}: {
    title: ReactNode;
    description?: ReactNode;
    actions?: ReactNode;
    level?: 1 | 2 | 3;
    /** The title's id (default: generated). */
    labelId?: string;
    /** Merged onto the body stack (e.g. `gap-10` for team settings). */
    className?: string;
    children: ReactNode;
}) {
    const generated = useId();
    const id = labelId ?? generated;
    const Heading = `h${level}` as const;

    return (
        <section aria-labelledby={id} className="flex flex-col gap-8">
            <header className="flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
                <div className="flex min-w-0 flex-col gap-1">
                    <Heading
                        id={id}
                        className="text-[20px] leading-7 font-semibold tracking-[-0.01em]"
                    >
                        {title}
                    </Heading>
                    {description && (
                        <p className="max-w-[62ch] type-body text-muted-foreground">
                            {description}
                        </p>
                    )}
                </div>
                {actions && (
                    <div className="flex shrink-0 items-center gap-2">
                        {actions}
                    </div>
                )}
            </header>
            {className ? (
                <div className={cn('flex flex-col', className)}>{children}</div>
            ) : (
                children
            )}
        </section>
    );
}
