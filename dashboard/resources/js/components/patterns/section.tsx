import type { ComponentProps, ReactNode } from 'react';
import { cn } from '@/lib/utils';

type SectionProps = Omit<ComponentProps<'section'>, 'title'> & {
    title?: ReactNode;
    /** One sentence (or a short qualifier such as "Last 30 days"). */
    description?: ReactNode;
    /** Right-aligned section actions (ghost/outline buttons, a select). */
    actions?: ReactNode;
    /** Heading level; sections directly under the page header are h2. */
    level?: 2 | 3;
    children?: ReactNode;
};

/**
 * A titled block of a page (design-system §4.2): heading row, then the
 * content 14 px below. Sections are spaced by the `Page` stack (40 px).
 */
export function Section({
    title,
    description,
    actions,
    level = 2,
    className,
    children,
    ...props
}: SectionProps) {
    const Heading = level === 2 ? 'h2' : 'h3';

    return (
        <section
            data-slot="section"
            className={cn('flex min-w-0 flex-col gap-3.5', className)}
            {...props}
        >
            {(title || actions) && (
                <div className="flex flex-wrap items-end justify-between gap-x-4 gap-y-2">
                    <div className="flex min-w-0 flex-col gap-0.5">
                        {title && (
                            <Heading className="type-heading">{title}</Heading>
                        )}
                        {description && (
                            <p className="type-body text-muted-foreground">
                                {description}
                            </p>
                        )}
                    </div>
                    {actions && (
                        <div className="flex shrink-0 items-center gap-2">
                            {actions}
                        </div>
                    )}
                </div>
            )}
            {children}
        </section>
    );
}
