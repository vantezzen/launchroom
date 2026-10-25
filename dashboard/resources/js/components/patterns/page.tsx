import type { ComponentProps, ReactNode } from 'react';
import { cn } from '@/lib/utils';

export type PageSize = 'wide' | 'narrow' | 'settings' | 'full';

/**
 * Gutters shared by every contained page (design-system §4.2):
 * 32·32·64 desktop, 24·24·48 tablet, 20·16·48 phone (top·sides·bottom).
 */
export const pageGutters =
    'px-4 pt-5 pb-12 sm:px-6 sm:pt-6 lg:px-8 lg:pt-8 lg:pb-16';

const widths: Record<Exclude<PageSize, 'full'>, string> = {
    wide: 'max-w-[1280px]',
    narrow: 'max-w-[768px]',
    settings: 'max-w-[1040px]',
};

type PageProps = Omit<ComponentProps<'div'>, 'children'> & {
    /**
     * `wide` lists, overviews and detail pages; `narrow` guided flows;
     * `settings` around a `SettingsShell` (components/settings); `full`
     * only the canvas and the logs explorer.
     */
    size?: PageSize;
    children?: ReactNode;
};

/**
 * The only way to lay out a page (design-system §4.2). Every block of the
 * page sits in this one container; the stack gap (40 px) is the section
 * spacing, so children don't add their own outer margins.
 *
 * The container carries the page's view-transition name: on Inertia visits
 * it cross-fades with a 4 px settle while the sidebar and top bar stay.
 */
export function Page({
    size = 'wide',
    className,
    children,
    ...props
}: PageProps) {
    if (size === 'full') {
        return (
            <div
                data-slot="page"
                data-size="full"
                className={cn(
                    'relative flex min-h-0 min-w-0 flex-1 flex-col [view-transition-name:lr-page]',
                    className,
                )}
                {...props}
            >
                {children}
            </div>
        );
    }

    if (size === 'settings') {
        // The shell lays out its own sidebar and page column.
        return (
            <div
                data-slot="page"
                data-size="settings"
                className={cn(
                    'w-full min-w-0 [view-transition-name:lr-page]',
                    pageGutters,
                )}
                {...props}
            >
                <div
                    className={cn(
                        'mx-auto w-full min-w-0',
                        widths.settings,
                        className,
                    )}
                >
                    {children}
                </div>
            </div>
        );
    }

    return (
        <div
            data-slot="page"
            data-size={size}
            className={cn(
                'w-full min-w-0 [view-transition-name:lr-page]',
                pageGutters,
            )}
            {...props}
        >
            <div
                className={cn(
                    'mx-auto flex w-full min-w-0 flex-col gap-10',
                    widths[size],
                    className,
                )}
            >
                {children}
            </div>
        </div>
    );
}

type PageHeaderProps = {
    title: ReactNode;
    /** One line, ≤ 80 characters; may carry live counts. */
    description?: ReactNode;
    /** Right-aligned, bottom-aligned with the title. At most one primary. */
    actions?: ReactNode;
    /** Picture before the title (logo tile, device, status pill row). */
    media?: ReactNode;
    /** Content between the description and the next block (chips, meta). */
    children?: ReactNode;
    /** `display` for heroes only (get started, server detail, setup). */
    variant?: 'title' | 'display';
    className?: string;
};

/**
 * Page header anatomy (design-system §4.2): title, one-line description,
 * actions right. Always the first child of `Page`.
 */
export function PageHeader({
    title,
    description,
    actions,
    media,
    children,
    variant = 'title',
    className,
}: PageHeaderProps) {
    return (
        <header
            data-slot="page-header"
            className={cn(
                'flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between sm:gap-6',
                className,
            )}
        >
            <div className="flex min-w-0 items-center gap-4">
                {media && <div className="shrink-0">{media}</div>}
                <div className="flex min-w-0 flex-col gap-1">
                    <h1
                        className={cn(
                            'min-w-0 break-words',
                            variant === 'display'
                                ? 'type-display'
                                : 'type-title',
                        )}
                    >
                        {title}
                    </h1>
                    {description && (
                        <div className="type-body text-muted-foreground">
                            {description}
                        </div>
                    )}
                    {children}
                </div>
            </div>
            {actions && (
                <div className="flex shrink-0 flex-wrap items-center gap-2">
                    {actions}
                </div>
            )}
        </header>
    );
}
