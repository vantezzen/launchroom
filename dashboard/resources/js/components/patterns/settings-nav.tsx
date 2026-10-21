import { Link } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import { cn } from '@/lib/utils';

export type SettingsNavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    active: boolean;
    /** Quiet signal dot after the label; the string is its screen-reader text ("Update ready"). */
    dot?: string;
};

export type SettingsNavGroup = {
    /** Group label ("Team", "This launchroom", "You"); omit for one group. */
    label?: string;
    items: SettingsNavItem[];
};

/**
 * The left column of the shared settings layout (design-system §4.2,
 * direction §5.12): grouped links on desktop, one scrollable row of pills
 * on phones.
 */
export function SettingsNav({
    groups,
    label = 'Settings',
}: {
    groups: SettingsNavGroup[];
    label?: string;
}) {
    const visible = groups.filter((group) => group.items.length > 0);

    return (
        <nav
            aria-label={label}
            className="[view-transition-name:lr-settings-nav]"
        >
            {/* Phones: one row of pills. */}
            <ul className="-mx-4 flex [scrollbar-width:none] gap-1 overflow-x-auto px-4 pb-1 md:hidden">
                {visible.flatMap((group) =>
                    group.items.map((item) => (
                        <li key={`${group.label}-${item.title}`}>
                            <Link
                                href={item.href}
                                aria-current={item.active ? 'page' : undefined}
                                className={cn(
                                    'flex h-9 items-center gap-1.5 rounded-full border px-3.5 type-label whitespace-nowrap transition-colors duration-fast',
                                    item.active
                                        ? 'border-foreground bg-foreground text-background'
                                        : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {item.title}
                                {item.dot && <NavDot label={item.dot} />}
                            </Link>
                        </li>
                    )),
                )}
            </ul>

            {/* Desktop: grouped list. */}
            <div className="hidden flex-col gap-5 md:flex">
                {visible.map((group, index) => (
                    <div
                        key={group.label ?? index}
                        className="flex flex-col gap-0.5"
                    >
                        {group.label && (
                            <p className="px-2.5 pb-1 type-small text-muted-foreground">
                                {group.label}
                            </p>
                        )}
                        <ul className="flex flex-col gap-0.5">
                            {group.items.map((item) => (
                                <li key={item.title}>
                                    <Link
                                        href={item.href}
                                        aria-current={
                                            item.active ? 'page' : undefined
                                        }
                                        className={cn(
                                            'flex h-8 items-center gap-2 rounded-md px-2.5 type-body transition-colors duration-fast',
                                            item.active
                                                ? 'bg-hover font-medium text-foreground'
                                                : 'text-muted-foreground hover:bg-hover hover:text-foreground',
                                        )}
                                    >
                                        {item.title}
                                        {item.dot && (
                                            <NavDot label={item.dot} />
                                        )}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </div>
                ))}
            </div>
        </nav>
    );
}

function NavDot({ label }: { label: string }) {
    return (
        <span className="ml-auto size-1.5 shrink-0 rounded-full bg-signal">
            <span className="sr-only">({label})</span>
        </span>
    );
}
