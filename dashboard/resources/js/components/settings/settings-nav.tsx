import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { useId } from 'react';
import type { KeyboardEvent, MouseEvent, ReactNode } from 'react';
import { cn } from '@/lib/utils';
import type { SettingsNavGroup, SettingsNavPage } from './types';

type NavProps<Id extends string> = {
    /** The landmark's name ("Settings", "Project settings"). */
    label: string;
    groups: SettingsNavGroup<Id>[];
    hrefFor: (page: Id) => string;
    /**
     * Client-side switching (the service panel): plain clicks call this
     * instead of following the link. Without it every page is an Inertia
     * link (page-per-route surfaces).
     */
    onNavigate?: (page: Id) => void;
    /** Pages with unsaved edits (a signal dot). */
    dirty?: ReadonlySet<string>;
};

/** Plain clicks navigate client-side; modified clicks open the link. */
function plainClick(event: MouseEvent): boolean {
    return !(
        event.metaKey ||
        event.ctrlKey ||
        event.shiftKey ||
        event.altKey ||
        event.button !== 0
    );
}

/** ↑/↓ move between pages, Home/End jump to the first and last. */
function onListKeys(event: KeyboardEvent<HTMLElement>) {
    const items = Array.from(
        event.currentTarget.querySelectorAll<HTMLElement>('[data-nav-item]'),
    );
    const index = items.indexOf(document.activeElement as HTMLElement);
    const focus = (i: number) =>
        items[(i + items.length) % items.length]?.focus();

    switch (event.key) {
        case 'ArrowDown':
            event.preventDefault();
            focus(index + 1);
            break;
        case 'ArrowUp':
            event.preventDefault();
            focus(index - 1);
            break;
        case 'Home':
            event.preventDefault();
            focus(0);
            break;
        case 'End':
            event.preventDefault();
            focus(items.length - 1);
            break;
    }
}

/**
 * A link to a page or the list: an Inertia `Link` on page-per-route
 * surfaces, a plain anchor with client-side switching otherwise.
 */
export function SettingsLink({
    href,
    onNavigate,
    className,
    children,
    ...props
}: {
    href: string;
    onNavigate?: () => void;
    className?: string;
    children: ReactNode;
    'aria-current'?: 'page';
    'data-nav-item'?: boolean;
}) {
    if (!onNavigate) {
        return (
            <Link href={href} className={className} {...props}>
                {children}
            </Link>
        );
    }

    return (
        <a
            href={href}
            className={className}
            onClick={(event) => {
                if (plainClick(event)) {
                    event.preventDefault();
                    onNavigate();
                }
            }}
            {...props}
        >
            {children}
        </a>
    );
}

function Dot({ label }: { label: string }) {
    return (
        <span className="ml-auto flex items-center gap-1.5">
            <span
                aria-hidden="true"
                className="size-1.5 rounded-full bg-signal"
            />
            <span className="sr-only">({label})</span>
        </span>
    );
}

function dotFor<Id extends string>(
    page: SettingsNavPage<Id>,
    dirty?: ReadonlySet<string>,
): string | undefined {
    return dirty?.has(page.id) ? 'unsaved changes' : page.dot;
}

/**
 * The settings sidebar (design-system §13.9 "Settings shell"): every page in one list, in
 * groups with a small title, so all settings are visible at a glance.
 */
export function SettingsSidebar<Id extends string>({
    label,
    groups,
    current,
    hrefFor,
    onNavigate,
    dirty,
}: NavProps<Id> & {
    /** The open page (on the list URL: the default page). */
    current: Id;
}) {
    const id = useId();

    return (
        <nav
            aria-label={label}
            className="flex flex-col gap-4"
            onKeyDown={(event) => onListKeys(event)}
        >
            {groups.map((group) => {
                const titleId = `${id}-${group.id}`;

                return (
                    <div key={group.id} className="flex flex-col">
                        {group.label !== null && (
                            <h3
                                id={titleId}
                                className="px-2.5 pt-1 pb-1.5 type-small font-medium text-faint"
                            >
                                {group.label}
                            </h3>
                        )}
                        <ul
                            aria-labelledby={
                                group.label === null ? undefined : titleId
                            }
                            className="flex flex-col gap-0.5"
                        >
                            {group.pages.map((page) => {
                                const active = page.id === current;
                                const Icon = page.icon;
                                const dot = dotFor(page, dirty);

                                return (
                                    <li key={page.id}>
                                        <SettingsLink
                                            data-nav-item
                                            href={hrefFor(page.id)}
                                            aria-current={
                                                active ? 'page' : undefined
                                            }
                                            onNavigate={
                                                onNavigate &&
                                                (() => onNavigate(page.id))
                                            }
                                            className={cn(
                                                'group flex h-9 w-full items-center gap-2.5 rounded-lg px-2.5 text-left type-body transition-colors duration-fast focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                                                active
                                                    ? 'bg-hover font-medium text-foreground'
                                                    : 'text-muted-foreground hover:bg-hover/70 hover:text-foreground',
                                            )}
                                        >
                                            <Icon
                                                aria-hidden="true"
                                                className="size-4 shrink-0"
                                            />
                                            <span className="truncate">
                                                {page.label}
                                            </span>
                                            {dot && <Dot label={dot} />}
                                        </SettingsLink>
                                    </li>
                                );
                            })}
                        </ul>
                    </div>
                );
            })}
        </nav>
    );
}

/**
 * Phones and narrow containers: the same groups as cards of large rows
 * with a one-line summary; a page replaces the list (with a back row).
 */
export function SettingsDrillDown<Id extends string>({
    label,
    groups,
    hrefFor,
    onNavigate,
    dirty,
}: NavProps<Id>) {
    const id = useId();

    return (
        <nav aria-label={label} className="flex flex-col gap-5">
            {groups.map((group) => {
                const titleId = `${id}-${group.id}`;

                return (
                    <div key={group.id} className="flex flex-col gap-2">
                        {group.label !== null && (
                            <h3
                                id={titleId}
                                className="type-small font-medium text-faint"
                            >
                                {group.label}
                            </h3>
                        )}
                        <ul
                            aria-labelledby={
                                group.label === null ? undefined : titleId
                            }
                            className="flex flex-col divide-y overflow-hidden rounded-xl border bg-card shadow-card"
                        >
                            {group.pages.map((page) => {
                                const Icon = page.icon;
                                const dot = dotFor(page, dirty);

                                return (
                                    <li key={page.id}>
                                        <SettingsLink
                                            href={hrefFor(page.id)}
                                            onNavigate={
                                                onNavigate &&
                                                (() => onNavigate(page.id))
                                            }
                                            className="flex min-h-16 w-full items-center gap-3 px-4 py-3 text-left transition-colors duration-fast hover:bg-hover focus-visible:bg-hover focus-visible:outline-none"
                                        >
                                            <span
                                                aria-hidden="true"
                                                className="flex size-9 shrink-0 items-center justify-center rounded-lg border bg-recessed text-muted-foreground"
                                            >
                                                <Icon className="size-4" />
                                            </span>
                                            <span className="flex min-w-0 flex-1 flex-col">
                                                <span className="type-body font-medium">
                                                    {page.label}
                                                </span>
                                                {page.summary && (
                                                    <span className="truncate type-small text-muted-foreground">
                                                        {page.summary}
                                                    </span>
                                                )}
                                            </span>
                                            {dot && <Dot label={dot} />}
                                            <ChevronRight
                                                aria-hidden="true"
                                                className="size-4 shrink-0 text-faint"
                                            />
                                        </SettingsLink>
                                    </li>
                                );
                            })}
                        </ul>
                    </div>
                );
            })}
        </nav>
    );
}
