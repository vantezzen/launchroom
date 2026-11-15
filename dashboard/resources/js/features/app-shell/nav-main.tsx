import { Link } from '@inertiajs/react';
import {
    SidebarGroup,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import type { NavItem } from '@/types';

export type NavMainItem = NavItem & {
    /** Quiet trailing text ("2/5"). */
    meta?: string;
    /** Quiet signal dot; the string is its screen-reader text. */
    dot?: string;
    /** Path prefix that also marks the item as current ("/settings"). */
    activePrefix?: string;
};

export function NavMain({ items }: { items: NavMainItem[] }) {
    const { currentUrl, isCurrentOrParentUrl } = useCurrentUrl();

    return (
        <SidebarGroup className="py-0">
            <SidebarMenu>
                {items.map((item) => (
                    <SidebarMenuItem key={item.title}>
                        <SidebarMenuButton
                            asChild
                            isActive={
                                isCurrentOrParentUrl(item.href) ||
                                (item.activePrefix !== undefined &&
                                    currentUrl.startsWith(item.activePrefix))
                            }
                            tooltip={{ children: item.title }}
                        >
                            <Link href={item.href} prefetch>
                                {item.icon && <item.icon />}
                                <span className="flex-1">{item.title}</span>
                                {item.meta && (
                                    <span className="ml-auto type-small text-muted-foreground tabular-nums">
                                        {item.meta}
                                    </span>
                                )}
                                {item.dot && (
                                    <span className="ml-auto size-1.5 shrink-0 rounded-full bg-signal">
                                        <span className="sr-only">
                                            ({item.dot})
                                        </span>
                                    </span>
                                )}
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                ))}
            </SidebarMenu>
        </SidebarGroup>
    );
}
