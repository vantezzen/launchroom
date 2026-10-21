import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { KeyboardEvent, MouseEvent } from 'react';
import { cn } from '@/lib/utils';
import { useDirtySections } from './dirty';
import type { SectionId, SettingsArea } from './sections';
import { areaOf } from './sections';

type NavProps = {
    areas: SettingsArea[];
    /** The open page (null: the default page). */
    current: SectionId;
    hrefFor: (section: SectionId) => string;
    onNavigate: (section: SectionId) => void;
};

const itemClass =
    'group flex h-9 w-full items-center gap-2.5 rounded-lg px-2.5 text-left type-body transition-colors duration-fast focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none';

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

/** ↑/↓ move between items, Home/End jump; → opens an area, ← goes back. */
function onListKeys(
    event: KeyboardEvent<HTMLElement>,
    {
        onBack,
        onOpen,
    }: { onBack?: () => void; onOpen?: (el: HTMLElement) => void },
) {
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
        case 'ArrowRight':
            if (onOpen && index >= 0 && items[index].dataset.area) {
                event.preventDefault();
                onOpen(items[index]);
            }
            break;
        case 'ArrowLeft':
            if (onBack) {
                event.preventDefault();
                onBack();
            }
            break;
    }
}

function DirtyDot() {
    return (
        <span className="ml-auto flex items-center gap-1.5">
            <span
                aria-hidden="true"
                className="size-1.5 rounded-full bg-signal"
            />
            <span className="sr-only">(unsaved changes)</span>
        </span>
    );
}

/**
 * The Settings tab's sidebar (docs/08 §16): areas with icons;
 * an area with several pages shows a chevron and opens a sub-menu with a
 * back arrow and the area's title. Opening an area also opens its first
 * page. The open page's area is shown when the panel opens.
 */
export function SettingsSidebar({
    areas,
    current,
    hrefFor,
    onNavigate,
}: NavProps) {
    const currentArea = areaOf(areas, current);
    const [open, setOpen] = useState<string | null>(
        currentArea && currentArea.pages.length > 1 ? currentArea.id : null,
    );
    const dirty = useDirtySections();
    const listRef = useRef<HTMLUListElement>(null);
    const focusNext = useRef<string | null>(null);
    const area = areas.find(
        (item) => item.id === open && item.pages.length > 1,
    );

    // A page of another area opened from elsewhere (General's tiles, a deep link).
    const currentAreaId = currentArea?.id ?? null;
    const currentAreaGroup = (currentArea?.pages.length ?? 0) > 1;

    useEffect(() => {
        if (currentAreaId !== null) {
            setOpen(currentAreaGroup ? currentAreaId : null);
        }
    }, [currentAreaId, currentAreaGroup]);

    useEffect(() => {
        const target = focusNext.current;

        if (!target || !listRef.current) {
            return;
        }

        focusNext.current = null;
        const selector =
            target === 'first'
                ? '[data-nav-item]'
                : `[data-nav-item][data-area="${target}"]`;
        listRef.current.querySelector<HTMLElement>(selector)?.focus();
    }, [open]);

    const openArea = (id: string, viaKeyboard = false) => {
        const target = areas.find((item) => item.id === id);

        if (!target) {
            return;
        }

        if (viaKeyboard) {
            focusNext.current = 'first';
        }

        setOpen(id);

        if (!target.pages.some((page) => page.id === current)) {
            onNavigate(target.pages[0].id);
        }
    };

    const back = () => {
        focusNext.current = open;
        setOpen(null);
    };

    if (area) {
        return (
            <nav
                aria-label={`${area.label} settings`}
                className="flex flex-col gap-1"
            >
                <div className="relative mb-1 flex h-9 items-center justify-center">
                    <button
                        type="button"
                        onClick={back}
                        aria-label="Back to all settings"
                        className="absolute left-0 flex size-8 items-center justify-center rounded-lg text-muted-foreground transition-colors duration-fast hover:bg-hover hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        <ChevronLeft aria-hidden="true" className="size-4" />
                    </button>
                    <span className="type-label text-muted-foreground">
                        {area.label}
                    </span>
                </div>
                <ul
                    ref={listRef}
                    key={area.id}
                    className="flex flex-col gap-0.5 motion-safe:animate-in motion-safe:duration-base motion-safe:ease-enter motion-safe:fade-in-0 motion-safe:slide-in-from-right-2"
                    onKeyDown={(event) => onListKeys(event, { onBack: back })}
                >
                    {area.pages.map((page) => {
                        const active = page.id === current;
                        const Icon = page.icon;

                        return (
                            <li key={page.id}>
                                <a
                                    data-nav-item
                                    href={hrefFor(page.id)}
                                    aria-current={active ? 'page' : undefined}
                                    onClick={(event) => {
                                        if (plainClick(event)) {
                                            event.preventDefault();
                                            onNavigate(page.id);
                                        }
                                    }}
                                    className={cn(
                                        itemClass,
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
                                    {dirty.has(page.id) && <DirtyDot />}
                                </a>
                            </li>
                        );
                    })}
                </ul>
            </nav>
        );
    }

    return (
        <nav aria-label="Settings" className="flex flex-col">
            <ul
                ref={listRef}
                className="flex flex-col gap-0.5 motion-safe:animate-in motion-safe:duration-base motion-safe:ease-enter motion-safe:fade-in-0 motion-safe:slide-in-from-left-2"
                onKeyDown={(event) =>
                    onListKeys(event, {
                        onOpen: (element) =>
                            openArea(element.dataset.area ?? '', true),
                    })
                }
            >
                {areas.map((item) => {
                    const Icon = item.icon;
                    const group = item.pages.length > 1;
                    const active = item.pages.some(
                        (page) => page.id === current,
                    );
                    const hasDirty = item.pages.some((page) =>
                        dirty.has(page.id),
                    );

                    return (
                        <li key={item.id}>
                            {group ? (
                                <button
                                    type="button"
                                    data-nav-item
                                    data-area={item.id}
                                    aria-haspopup="true"
                                    onClick={() => openArea(item.id)}
                                    className={cn(
                                        itemClass,
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
                                        {item.label}
                                    </span>
                                    {hasDirty && <DirtyDot />}
                                    <ChevronRight
                                        aria-hidden="true"
                                        className={cn(
                                            'size-4 shrink-0 text-faint transition-transform duration-fast group-hover:translate-x-0.5',
                                            !hasDirty && 'ml-auto',
                                        )}
                                    />
                                </button>
                            ) : (
                                <a
                                    data-nav-item
                                    href={hrefFor(item.pages[0].id)}
                                    aria-current={active ? 'page' : undefined}
                                    onClick={(event) => {
                                        if (plainClick(event)) {
                                            event.preventDefault();
                                            onNavigate(item.pages[0].id);
                                        }
                                    }}
                                    className={cn(
                                        itemClass,
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
                                        {item.label}
                                    </span>
                                    {hasDirty && <DirtyDot />}
                                </a>
                            )}
                        </li>
                    );
                })}
            </ul>
        </nav>
    );
}

/**
 * Narrow panels and phones: the sidebar as a drill-down list of large
 * rows; an area opens its pages, a page replaces the list (with a back row).
 */
export function SettingsDrillDown({
    areas,
    hrefFor,
    onNavigate,
    summaries,
    initialArea,
}: Omit<NavProps, 'current'> & {
    /** One quiet line per page ("2 domains", "3 replicas"). */
    summaries: Partial<Record<SectionId, string>>;
    initialArea: string | null;
}) {
    const [open, setOpen] = useState<string | null>(initialArea);
    const dirty = useDirtySections();
    const area = areas.find(
        (item) => item.id === open && item.pages.length > 1,
    );
    const rows = area
        ? area.pages.map((page) => ({
              key: page.id,
              label: page.label,
              icon: page.icon,
              summary: summaries[page.id],
              page: page.id,
              group: false,
              dirty: dirty.has(page.id),
          }))
        : areas.map((item) => ({
              key: item.id,
              label: item.label,
              icon: item.icon,
              summary:
                  item.pages.length > 1
                      ? item.pages.map((page) => page.label).join(' · ')
                      : summaries[item.pages[0].id],
              page: item.pages[0].id,
              group: item.pages.length > 1,
              dirty: item.pages.some((page) => dirty.has(page.id)),
          }));

    return (
        <nav
            aria-label={area ? `${area.label} settings` : 'Settings'}
            className="flex flex-col gap-3"
        >
            {area && (
                <button
                    type="button"
                    onClick={() => setOpen(null)}
                    className="-ml-1 inline-flex h-9 items-center gap-1 self-start rounded-lg pr-2 pl-1 type-label text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    <ChevronLeft aria-hidden="true" className="size-4" />
                    All settings
                </button>
            )}
            {area && <h3 className="type-heading">{area.label}</h3>}
            <ul
                key={area?.id ?? 'root'}
                className="flex flex-col divide-y overflow-hidden rounded-xl border bg-card shadow-card motion-safe:animate-in motion-safe:duration-base motion-safe:fade-in-0"
            >
                {rows.map((row) => {
                    const Icon = row.icon;
                    const content = (
                        <>
                            <span
                                aria-hidden="true"
                                className="flex size-9 shrink-0 items-center justify-center rounded-lg border bg-recessed text-muted-foreground"
                            >
                                <Icon className="size-4" />
                            </span>
                            <span className="flex min-w-0 flex-1 flex-col">
                                <span className="type-body font-medium">
                                    {row.label}
                                </span>
                                {row.summary && (
                                    <span className="truncate type-small text-muted-foreground">
                                        {row.summary}
                                    </span>
                                )}
                            </span>
                            {row.dirty && <DirtyDot />}
                            <ChevronRight
                                aria-hidden="true"
                                className="size-4 shrink-0 text-faint"
                            />
                        </>
                    );
                    const rowClass =
                        'flex min-h-16 w-full items-center gap-3 px-4 py-3 text-left transition-colors duration-fast hover:bg-hover focus-visible:bg-hover focus-visible:outline-none';

                    return (
                        <li key={row.key}>
                            {row.group ? (
                                <button
                                    type="button"
                                    className={rowClass}
                                    onClick={() => setOpen(row.key)}
                                >
                                    {content}
                                </button>
                            ) : (
                                <a
                                    href={hrefFor(row.page)}
                                    className={rowClass}
                                    onClick={(event) => {
                                        if (plainClick(event)) {
                                            event.preventDefault();
                                            onNavigate(row.page);
                                        }
                                    }}
                                >
                                    {content}
                                </a>
                            )}
                        </li>
                    );
                })}
            </ul>
        </nav>
    );
}
