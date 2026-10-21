import { Link } from '@inertiajs/react';
import { Fragment, createContext, useContext, useState } from 'react';
import type { ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';

type Slot = {
    node: HTMLElement | null;
    setNode: (node: HTMLElement | null) => void;
};

const TopBarSlotContext = createContext<Slot | null>(null);

/**
 * Lives in the persistent layouts: lets a page put its secondary actions
 * into the top bar with `<TopBarActions>` (design-system §4.1).
 */
export function TopBarSlotProvider({ children }: { children: ReactNode }) {
    const [node, setNode] = useState<HTMLElement | null>(null);

    return (
        <TopBarSlotContext.Provider value={{ node, setNode }}>
            {children}
        </TopBarSlotContext.Provider>
    );
}

/**
 * Page-level secondary actions, rendered at the right of the top bar.
 * Primary actions stay in the `PageHeader`.
 */
export function TopBarActions({ children }: { children: ReactNode }) {
    const slot = useContext(TopBarSlotContext);

    if (!slot?.node) {
        return null;
    }

    return createPortal(children, slot.node);
}

type TopBarProps = {
    /** Left side: breadcrumb (team pages) or switchers + tabs (project pages). */
    children?: ReactNode;
    /** Right side, before the page's `<TopBarActions>`. */
    actions?: ReactNode;
    /** Rendered under the bar (e.g. "auto-deploy paused"). */
    below?: ReactNode;
    className?: string;
};

/**
 * One top bar for every page (design-system §4.1): 52 px, hairline bottom
 * border, `px-6` (16 px on phones). It never animates on page changes.
 */
export function TopBar({ children, actions, below, className }: TopBarProps) {
    const slot = useContext(TopBarSlotContext);

    return (
        <header
            data-slot="top-bar"
            className={cn(
                'flex shrink-0 flex-col border-b bg-background [view-transition-name:lr-top-bar] md:rounded-t-xl',
                className,
            )}
        >
            <div className="@container flex h-[52px] min-w-0 items-center gap-2 px-4 sm:px-6">
                <SidebarTrigger className="-ml-1.5 shrink-0 text-muted-foreground" />
                <div className="flex min-w-0 flex-1 items-center gap-2">
                    {children}
                </div>
                <div className="flex shrink-0 items-center gap-2">
                    {actions}
                    <div
                        ref={slot?.setNode}
                        className="flex items-center gap-2 empty:hidden"
                    />
                </div>
            </div>
            {below}
        </header>
    );
}

/** Breadcrumb for team pages: `Servers / fra-1`. The last item is the page. */
export function TopBarBreadcrumbs({
    breadcrumbs,
}: {
    breadcrumbs: BreadcrumbItem[];
}) {
    if (breadcrumbs.length === 0) {
        return null;
    }

    return (
        <nav aria-label="Breadcrumb" className="min-w-0">
            <ol className="flex min-w-0 items-center gap-1.5 type-label">
                {breadcrumbs.map((item, index) => {
                    const last = index === breadcrumbs.length - 1;

                    return (
                        <Fragment key={index}>
                            <li
                                className={cn(
                                    'min-w-0 truncate',
                                    // Phones keep the current page and its parent only.
                                    index < breadcrumbs.length - 2 &&
                                        'hidden sm:block',
                                )}
                            >
                                {last ? (
                                    <span
                                        aria-current="page"
                                        className="text-foreground"
                                    >
                                        {item.title}
                                    </span>
                                ) : (
                                    <Link
                                        href={item.href}
                                        className="rounded-sm text-muted-foreground transition-colors duration-fast hover:text-foreground"
                                    >
                                        {item.title}
                                    </Link>
                                )}
                            </li>
                            {!last && (
                                <li
                                    aria-hidden="true"
                                    className={cn(
                                        'text-faint',
                                        index < breadcrumbs.length - 2 &&
                                            'hidden sm:block',
                                    )}
                                >
                                    /
                                </li>
                            )}
                        </Fragment>
                    );
                })}
            </ol>
        </nav>
    );
}
