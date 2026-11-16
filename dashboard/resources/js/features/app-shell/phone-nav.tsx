import { Link, usePage } from '@inertiajs/react';
import { Activity, FolderKanban, Search, Server } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { openCommandPalette } from '@/features/command-palette/command-palette';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn } from '@/lib/utils';
import { index as activity } from '@/routes/activity';
import { index as projects } from '@/routes/projects';
import { index as servers } from '@/routes/servers';
import { badge } from './nav-items';

const itemClass =
    'relative flex min-w-0 flex-1 flex-col items-center justify-center gap-0.5 type-small transition-colors duration-fast focus-visible:ring-2 focus-visible:ring-signal focus-visible:outline-none focus-visible:ring-inset';

function Item({
    icon: Icon,
    label,
    active,
    count,
}: {
    icon: LucideIcon;
    label: string;
    active: boolean;
    count?: string;
}): ReactNode {
    return (
        <>
            <span className="relative">
                <Icon
                    aria-hidden="true"
                    className="size-5"
                    strokeWidth={active ? 2.25 : 1.75}
                />
                {count && (
                    <span
                        aria-hidden="true"
                        className="absolute -top-1.5 left-3.5 min-w-4 rounded-full bg-foreground px-1 text-center text-[10px] leading-4 font-medium text-background tabular-nums"
                    >
                        {count}
                    </span>
                )}
            </span>
            <span className={cn('truncate', active && 'font-medium')}>
                {label}
            </span>
        </>
    );
}

/**
 * Phones (below md): a fixed bottom bar with the places people hop
 * between, Projects, Servers and Activity (open alerts as a count), and
 * Search (⌘K). 56 px plus the safe-area inset; `--phone-nav-h` (app.css)
 * keeps page content, floating bars and toasts above it. Sheets and
 * dialogs sit above it (z-50), so a full-screen sheet covers it. The
 * mark in the top bar opens the sheet with every other page.
 */
export function PhoneNav() {
    const { openAlertCount } = usePage().props;
    const { currentUrl } = useCurrentUrl();
    const links = [
        {
            label: 'Projects',
            href: projects().url,
            icon: FolderKanban,
            // Project pages live under /p/.
            active:
                currentUrl.startsWith('/projects') ||
                currentUrl.startsWith('/p/'),
        },
        {
            label: 'Servers',
            href: servers().url,
            icon: Server,
            active: currentUrl.startsWith('/servers'),
        },
        {
            label: 'Activity',
            href: activity().url,
            icon: Activity,
            active: currentUrl.startsWith('/activity'),
            count: badge(openAlertCount),
        },
    ];

    return (
        <nav
            data-phone-nav
            aria-label="Main"
            className="fixed inset-x-0 bottom-0 z-40 flex h-(--phone-nav-h) border-t bg-background pb-[env(safe-area-inset-bottom)] md:hidden"
        >
            {links.map((link) => (
                <Link
                    key={link.label}
                    href={link.href}
                    prefetch
                    aria-current={link.active ? 'page' : undefined}
                    aria-label={
                        link.count
                            ? `${link.label}, ${link.count} open ${link.count === '1' ? 'alert' : 'alerts'}`
                            : undefined
                    }
                    className={cn(
                        itemClass,
                        link.active
                            ? 'text-foreground'
                            : 'text-muted-foreground',
                    )}
                >
                    <Item
                        icon={link.icon}
                        label={link.label}
                        active={link.active}
                        count={link.count}
                    />
                </Link>
            ))}
            <button
                type="button"
                onClick={() => openCommandPalette()}
                className={cn(itemClass, 'text-muted-foreground')}
            >
                <Item icon={Search} label="Search" active={false} />
            </button>
        </nav>
    );
}
