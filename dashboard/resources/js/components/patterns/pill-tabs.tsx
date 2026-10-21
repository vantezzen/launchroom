import { Link } from '@inertiajs/react';
import { useLayoutEffect, useRef, useState } from 'react';
import type { KeyboardEvent } from 'react';
import { cn } from '@/lib/utils';

export type PillTab = {
    label: string;
    href: string;
    active: boolean;
    count?: number;
};

/**
 * Page tabs as one segmented row (design-system §13.2): the project tabs
 * in the top bar and a server's tabs under its hero. Each tab is a link, so
 * tabs are deep links that work with back/forward; the selected pill
 * slides between tabs (120 ms). Arrow keys, Home and End move between
 * tabs. `scroll` lets the row scroll sideways on narrow screens with an
 * edge fade while more tabs are hidden.
 */
export function PillTabs({
    tabs,
    label,
    scroll = false,
    preserve = false,
    className,
}: {
    tabs: PillTab[];
    /** The navigation landmark's name ("Project", "Server"). */
    label: string;
    scroll?: boolean;
    /** Keep scroll position and page state when switching (tabs under a hero). */
    preserve?: boolean;
    className?: string;
}) {
    const scrollerRef = useRef<HTMLDivElement>(null);
    const listRef = useRef<HTMLDivElement>(null);
    const [pill, setPill] = useState<{ left: number; width: number } | null>(
        null,
    );
    const [moreRight, setMoreRight] = useState(false);
    const activeIndex = tabs.findIndex((tab) => tab.active);

    useLayoutEffect(() => {
        const list = listRef.current;

        if (!list) {
            return;
        }

        // Measure again whenever the tabs change size: web fonts swapping
        // in, counts appearing, the bar crossing its container breakpoint.
        const measure = () => {
            const target =
                activeIndex >= 0
                    ? (list.children[activeIndex + 1] as
                          | HTMLElement
                          | undefined)
                    : undefined;

            setPill(
                target && target.offsetWidth > 0
                    ? { left: target.offsetLeft, width: target.offsetWidth }
                    : null,
            );

            const scroller = scrollerRef.current;

            if (scroller) {
                setMoreRight(
                    scroller.scrollLeft + scroller.clientWidth <
                        scroller.scrollWidth - 1,
                );
            }
        };

        measure();
        const observer = new ResizeObserver(measure);
        Array.from(list.children).forEach((child) => observer.observe(child));

        if (scrollerRef.current) {
            observer.observe(scrollerRef.current);
        }

        void document.fonts?.ready.then(measure);

        return () => observer.disconnect();
    }, [activeIndex, tabs.length]);

    // A tab hidden behind the edge comes into view when it is selected.
    useLayoutEffect(() => {
        const scroller = scrollerRef.current;
        const target = listRef.current?.children[activeIndex + 1] as
            | HTMLElement
            | undefined;

        if (!scroller || !target) {
            return;
        }

        if (
            target.offsetLeft + target.offsetWidth >
            scroller.scrollLeft + scroller.clientWidth
        ) {
            scroller.scrollLeft =
                target.offsetLeft + target.offsetWidth - scroller.clientWidth;
        } else if (target.offsetLeft < scroller.scrollLeft) {
            scroller.scrollLeft = target.offsetLeft;
        }
    }, [activeIndex]);

    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        const links = Array.from(
            event.currentTarget.querySelectorAll<HTMLAnchorElement>('a'),
        );
        const index = links.indexOf(
            document.activeElement as HTMLAnchorElement,
        );

        if (index < 0) {
            return;
        }

        const next = {
            ArrowRight: (index + 1) % links.length,
            ArrowLeft: (index - 1 + links.length) % links.length,
            Home: 0,
            End: links.length - 1,
        }[event.key];

        if (next !== undefined) {
            event.preventDefault();
            links[next]?.focus();
        }
    };

    const row = (
        <div
            ref={listRef}
            onKeyDown={onKeyDown}
            className="relative flex w-max items-center gap-0.5"
        >
            <span
                aria-hidden="true"
                className={cn(
                    'absolute inset-y-0 left-0 rounded-md bg-hover transition-[transform,width,opacity] duration-fast ease-enter',
                    pill ? 'opacity-100' : 'opacity-0',
                )}
                style={
                    pill
                        ? {
                              width: pill.width,
                              transform: `translateX(${pill.left}px)`,
                          }
                        : undefined
                }
            />
            {tabs.map((tab) => (
                <Link
                    key={tab.label}
                    href={tab.href}
                    preserveScroll={preserve}
                    preserveState={preserve}
                    aria-current={tab.active ? 'page' : undefined}
                    className={cn(
                        'relative flex h-8 shrink-0 items-center gap-1.5 rounded-md px-3 type-label whitespace-nowrap transition-colors duration-fast',
                        'focus-visible:ring-2 focus-visible:ring-signal focus-visible:outline-none',
                        tab.active
                            ? 'text-foreground'
                            : 'text-muted-foreground hover:text-foreground',
                    )}
                >
                    {tab.label}
                    {tab.count !== undefined && tab.count > 0 && (
                        <span className="text-muted-foreground tabular-nums">
                            {tab.count}
                        </span>
                    )}
                </Link>
            ))}
        </div>
    );

    return (
        <nav aria-label={label} className={cn('min-w-0', className)}>
            {scroll ? (
                <div
                    ref={scrollerRef}
                    onScroll={(event) => {
                        const el = event.currentTarget;
                        setMoreRight(
                            el.scrollLeft + el.clientWidth < el.scrollWidth - 1,
                        );
                    }}
                    className={cn(
                        // Room for the focus ring inside the scroller.
                        '-m-1 [scrollbar-width:none] overflow-x-auto p-1',
                        moreRight &&
                            '[mask-image:linear-gradient(to_right,black_85%,transparent)]',
                    )}
                >
                    {row}
                </div>
            ) : (
                row
            )}
        </nav>
    );
}
