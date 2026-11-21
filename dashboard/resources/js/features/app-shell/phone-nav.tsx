import { Link, usePage } from '@inertiajs/react';
import { ArrowUpRight, Ellipsis, Search } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetTitle,
} from '@/components/ui/sheet';
import { openCommandPalette } from '@/features/command-palette/command-palette';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn } from '@/lib/utils';
import { phoneNav, teamNavItems } from './nav-items';
import type { TeamNavItem } from './nav-items';

const itemClass =
    'relative flex min-w-0 flex-1 flex-col items-center justify-center gap-0.5 type-small transition-colors duration-fast focus-visible:ring-2 focus-visible:ring-signal focus-visible:outline-none focus-visible:ring-inset';

function Item({
    icon: Icon,
    label,
    active,
    count,
    dot,
}: {
    icon: LucideIcon;
    label: string;
    active: boolean;
    count?: string;
    dot?: boolean;
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
                {!count && dot && (
                    <span
                        aria-hidden="true"
                        className="absolute -top-0.5 -right-1 size-2 rounded-full border-2 border-background bg-signal"
                    />
                )}
            </span>
            <span className={cn('truncate', active && 'font-medium')}>
                {label}
            </span>
        </>
    );
}

const urlOf = (item: TeamNavItem) =>
    typeof item.href === 'string' ? item.href : item.href.url;

/** "Activity, 3" / "Settings, Update ready" for screen readers. */
const spoken = (item: TeamNavItem) =>
    [item.title, item.meta, item.dot].filter(Boolean).join(', ');

/**
 * Phones (below md, D114): a fixed bottom bar with the daily pages
 * (Projects, Servers, Logs; Analytics without Logs), Search (⌘K) and More,
 * a sheet with every other page in sidebar order. Counts stay on their
 * page; More carries a mark while something inside it has one. 56 px plus
 * the safe-area inset; `--phone-nav-h` (app.css) keeps page content,
 * floating bars and toasts above it. Sheets and dialogs sit above it
 * (z-50). The mark in the top bar still opens the full navigation sheet
 * with the team and the account.
 */
export function PhoneNav() {
    const props = usePage().props;
    const { currentUrl } = useCurrentUrl();
    const [more, setMore] = useState(false);
    const { bar, more: rest } = phoneNav(teamNavItems(props));

    const isActive = (item: TeamNavItem) => {
        if (item.external) {
            return false;
        }

        const url = urlOf(item);

        return (
            currentUrl.startsWith(url) ||
            (item.activePrefix !== undefined &&
                currentUrl.startsWith(item.activePrefix)) ||
            // Project pages live under /p/.
            (url === '/projects' && currentUrl.startsWith('/p/'))
        );
    };
    const moreActive = rest.some(isActive);
    const moreMarked = rest.filter((item) => item.signal || item.dot);

    return (
        <>
            <nav
                data-phone-nav
                aria-label="Main"
                className="fixed inset-x-0 bottom-0 z-40 flex h-(--phone-nav-h) border-t bg-background pb-[env(safe-area-inset-bottom)] md:hidden"
            >
                {bar.map((link) => {
                    const active = isActive(link);

                    return (
                        <Link
                            key={link.title}
                            href={link.href}
                            prefetch
                            aria-current={active ? 'page' : undefined}
                            aria-label={link.meta ? spoken(link) : undefined}
                            className={cn(
                                itemClass,
                                active
                                    ? 'text-foreground'
                                    : 'text-muted-foreground',
                            )}
                        >
                            <Item
                                icon={link.icon ?? Ellipsis}
                                label={link.title}
                                active={active}
                                count={link.meta}
                            />
                        </Link>
                    );
                })}
                <button
                    type="button"
                    onClick={() => openCommandPalette()}
                    className={cn(itemClass, 'text-muted-foreground')}
                >
                    <Item icon={Search} label="Search" active={false} />
                </button>
                <button
                    type="button"
                    onClick={() => setMore(true)}
                    aria-haspopup="dialog"
                    aria-expanded={more}
                    aria-label={
                        moreMarked.length > 0
                            ? `More: ${moreMarked.map(spoken).join('; ')}`
                            : 'More'
                    }
                    className={cn(
                        itemClass,
                        moreActive || more
                            ? 'text-foreground'
                            : 'text-muted-foreground',
                    )}
                >
                    <Item
                        icon={Ellipsis}
                        label="More"
                        active={moreActive}
                        dot={moreMarked.length > 0}
                    />
                </button>
            </nav>

            <Sheet open={more} onOpenChange={setMore}>
                <SheetContent
                    side="bottom"
                    className="gap-0 rounded-t-2xl pb-[env(safe-area-inset-bottom)] md:hidden"
                >
                    <div className="px-5 pt-5 pb-2">
                        <SheetTitle className="type-heading">More</SheetTitle>
                        <SheetDescription className="sr-only">
                            Every other page of this team.
                        </SheetDescription>
                    </div>
                    <ul className="flex flex-col px-2 pb-3">
                        {rest.map((item) => {
                            const active = isActive(item);
                            const Icon = item.icon ?? Ellipsis;
                            const body = (
                                <>
                                    <Icon
                                        aria-hidden="true"
                                        className="size-5 shrink-0 text-muted-foreground"
                                    />
                                    <span className="flex-1 truncate">
                                        {item.title}
                                    </span>
                                    {item.meta && (
                                        <span className="type-small text-muted-foreground tabular-nums">
                                            {item.meta}
                                        </span>
                                    )}
                                    {item.dot && (
                                        <span className="size-1.5 shrink-0 rounded-full bg-signal">
                                            <span className="sr-only">
                                                ({item.dot})
                                            </span>
                                        </span>
                                    )}
                                    {item.external && (
                                        <>
                                            <ArrowUpRight
                                                aria-hidden="true"
                                                className="size-4 text-muted-foreground"
                                            />
                                            <span className="sr-only">
                                                (opens in a new tab)
                                            </span>
                                        </>
                                    )}
                                </>
                            );
                            const rowClass = cn(
                                'flex h-12 items-center gap-3 rounded-lg px-3 type-body transition-colors duration-fast hover:bg-hover focus-visible:bg-hover focus-visible:outline-none',
                                active && 'bg-hover font-medium',
                            );

                            return (
                                <li key={item.title}>
                                    {item.external ? (
                                        <a
                                            href={urlOf(item)}
                                            target="_blank"
                                            rel="noreferrer"
                                            className={rowClass}
                                            onClick={() => setMore(false)}
                                        >
                                            {body}
                                        </a>
                                    ) : (
                                        <Link
                                            href={item.href}
                                            prefetch
                                            aria-current={
                                                active ? 'page' : undefined
                                            }
                                            className={rowClass}
                                            onClick={() => setMore(false)}
                                        >
                                            {body}
                                        </Link>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                </SheetContent>
            </Sheet>
        </>
    );
}
