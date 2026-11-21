import { Link } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import type { ReactNode } from 'react';
import {
    SidebarGroup,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn } from '@/lib/utils';
import type { NavItem } from '@/types';

export type NavMainItem = NavItem & {
    /** Quiet trailing text ("2/5", a count). */
    meta?: string;
    /** Quiet signal dot; the string is its screen-reader text. */
    dot?: string;
    /** Path prefix that also marks the item as current ("/settings"). */
    activePrefix?: string;
    /** The meta is a count that wants a look (alerts, fixes), not progress. */
    signal?: boolean;
    /** Opens in a new tab (Docs); never "current". */
    external?: boolean;
};

/** The row's text, then its count or dot; on the icon rail a corner mark. */
function ItemBody({ item }: { item: NavMainItem }): ReactNode {
    return (
        <>
            {item.icon && <item.icon aria-hidden="true" />}
            <span className="flex-1 truncate">{item.title}</span>
            {item.external && (
                <ArrowUpRight
                    aria-hidden="true"
                    className="ml-auto size-3.5! text-muted-foreground"
                />
            )}
            {item.meta && (
                <span className="ml-auto type-small text-muted-foreground tabular-nums">
                    {item.meta}
                </span>
            )}
            {item.dot && (
                <span className="ml-auto size-1.5 shrink-0 rounded-full bg-signal">
                    <span className="sr-only">({item.dot})</span>
                </span>
            )}
            {(item.signal || item.dot) && (
                // The rail hides the count; a corner mark keeps the cue.
                <span
                    aria-hidden="true"
                    className={cn(
                        'pointer-events-none absolute top-1 right-1 hidden size-1.5 rounded-full group-data-[collapsible=icon]:block',
                        item.dot ? 'bg-signal' : 'bg-foreground',
                    )}
                />
            )}
            {item.external && (
                <span className="sr-only">(opens in a new tab)</span>
            )}
        </>
    );
}

/**
 * One group of sidebar links (docs/08 §1). `label` names the group for
 * screen readers; the sidebar shows no group headings.
 */
export function NavMain({
    items,
    label,
    className,
}: {
    items: NavMainItem[];
    label?: string;
    className?: string;
}) {
    const { currentUrl, isCurrentOrParentUrl } = useCurrentUrl();

    if (items.length === 0) {
        return null;
    }

    return (
        <SidebarGroup className={cn('py-0', className)}>
            <SidebarMenu aria-label={label}>
                {items.map((item) => {
                    const href =
                        typeof item.href === 'string'
                            ? item.href
                            : item.href.url;
                    const active =
                        !item.external &&
                        (isCurrentOrParentUrl(item.href) ||
                            (item.activePrefix !== undefined &&
                                currentUrl.startsWith(item.activePrefix)));
                    const tooltip = item.meta
                        ? `${item.title} (${item.meta})`
                        : item.dot
                          ? `${item.title} (${item.dot})`
                          : item.title;

                    return (
                        <SidebarMenuItem key={item.title}>
                            <SidebarMenuButton
                                asChild
                                isActive={active}
                                tooltip={{ children: tooltip }}
                                className="relative"
                            >
                                {item.external ? (
                                    <a
                                        href={href}
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        <ItemBody item={item} />
                                    </a>
                                ) : (
                                    <Link
                                        href={item.href}
                                        prefetch
                                        aria-current={
                                            active ? 'page' : undefined
                                        }
                                    >
                                        <ItemBody item={item} />
                                    </Link>
                                )}
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    );
                })}
            </SidebarMenu>
        </SidebarGroup>
    );
}
