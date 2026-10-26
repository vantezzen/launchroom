import { router } from '@inertiajs/react';
import { ChevronLeft } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import type { SettingResult } from './search';
import {
    SettingsDrillDown,
    SettingsLink,
    SettingsSidebar,
} from './settings-nav';
import { SettingsSearch } from './settings-search';
import type { SettingsNavGroup } from './types';

const FOCUSABLE = [
    'input:not([disabled]):not([type="hidden"])',
    'textarea:not([disabled])',
    'select:not([disabled])',
    'button:not([disabled])',
    '[role="slider"]',
    'a[href]',
    '[tabindex]:not([tabindex="-1"])',
].join(',');

const FLASH_MS = 1600;

type Reveal = { page: string; anchors: string[] };

/**
 * What to do once the next page is shown. Page-per-route surfaces remount
 * the shell on a visit, so this outlives the component: a chosen search
 * result is revealed, or the search field focused (phones, `/` on a page).
 */
let pending: { reveal: Reveal | null; focusSearch: boolean; at: number } = {
    reveal: null,
    focusSearch: false,
    at: 0,
};

/** Hands over only what was asked for during the last few seconds. */
function fresh(): typeof pending {
    return Date.now() - pending.at < 5000
        ? pending
        : { reveal: null, focusSearch: false, at: 0 };
}

function typing(target: EventTarget | null): boolean {
    const element = target as HTMLElement | null;

    return Boolean(
        element &&
        (element.isContentEditable ||
            ['INPUT', 'TEXTAREA', 'SELECT'].includes(element.tagName)),
    );
}

/**
 * A search result landed: scroll its row into the middle, tint it briefly
 * (`data-setting-flash`, app.css) and focus its control. Rows that aren't
 * drawn right now fall back to their card, then the page's top.
 */
function revealSetting(page: Element, anchors: string[]): boolean {
    const element = anchors
        .map((anchor) => page.querySelector(`[data-setting="${anchor}"]`))
        .find((found): found is HTMLElement => found instanceof HTMLElement);

    if (!element) {
        return anchors.length === 0;
    }

    const reduced = window.matchMedia(
        '(prefers-reduced-motion: reduce)',
    ).matches;
    element.scrollIntoView({
        block: 'center',
        behavior: reduced ? 'auto' : 'smooth',
    });
    element.setAttribute('data-setting-flash', '');
    window.setTimeout(
        () => element.removeAttribute('data-setting-flash'),
        FLASH_MS,
    );
    const control = element.matches(FOCUSABLE)
        ? element
        : element.querySelector<HTMLElement>(FOCUSABLE);
    control?.focus({ preventScroll: true });

    return true;
}

export type SettingsShellProps<Id extends string> = {
    /** The nav landmark's name ("Settings", "Project settings"). */
    label: string;
    groups: SettingsNavGroup<Id>[];
    /** The page shown beside the sidebar (on the list URL: the default). */
    current: Id;
    /** The page in the URL; null on the list URL (phones show the list). */
    requested: Id | null;
    /** A page's URL; null is the list URL. */
    hrefFor: (page: Id | null) => string;
    /**
     * Client-side switching (pages stay mounted, the service panel). Without
     * it, pages are Inertia visits (one route per page).
     */
    onNavigate?: (page: Id | null) => void;
    /** The search index (`buildSettingsIndex`). */
    index: SettingResult[];
    /** A chosen result whose target isn't a page here. */
    onSelectOther?: (result: SettingResult) => void;
    /** What "No setting matches" suggests instead. */
    emptyHint?: string;
    /** Pages with unsaved edits (signal dots). */
    dirty?: ReadonlySet<string>;
    /** A line above everything (read-only access). */
    notice?: ReactNode;
    /**
     * The open page. Client-side surfaces render every visited page here
     * with `data-settings-page={id}` and `hidden` on the closed ones.
     */
    children: ReactNode;
};

/**
 * The shared settings layout (design-system §13.9 "Settings shell"): a search field and a
 * sidebar of titled groups beside one page at a time, from 768 px of
 * container width; below, a drill-down list of cards, and a page with an
 * "All settings" back row. Used by the account and team settings, project
 * settings and the service panel's Settings tab, so all three look and
 * behave the same.
 */
export function SettingsShell<Id extends string>({
    label,
    groups,
    current,
    requested,
    hrefFor,
    onNavigate,
    index,
    onSelectOther,
    emptyHint,
    dirty,
    notice,
    children,
}: SettingsShellProps<Id>) {
    const root = useRef<HTMLDivElement>(null);
    const searchRef = useRef<HTMLInputElement>(null);
    const [query, setQuery] = useState('');
    const [reveal, setReveal] = useState<Reveal | null>(() => fresh().reveal);
    const [focusSearch, setFocusSearch] = useState(() => fresh().focusSearch);
    const pages = groups.flatMap((group) => group.pages.map((p) => p.id));

    const navigate = useCallback(
        (page: Id | null) => {
            if (onNavigate) {
                onNavigate(page);
            } else {
                router.visit(hrefFor(page));
            }
        },
        [onNavigate, hrefFor],
    );

    const select = (result: SettingResult) => {
        setQuery('');

        if (!pages.includes(result.target as Id)) {
            onSelectOther?.(result);

            return;
        }

        const next: Reveal = {
            page: result.target,
            anchors: [result.anchor, result.fallback].filter(
                (anchor): anchor is string => Boolean(anchor),
            ),
        };
        setReveal(next);

        if (result.target !== requested) {
            pending = { ...pending, reveal: next, at: Date.now() };
            navigate(result.target as Id);
        }
    };

    // After the page is shown (it may load or mount first), reveal the setting.
    useEffect(() => {
        if (!reveal || reveal.page !== current || requested === null) {
            return;
        }

        let attempts = 0;
        let timer = 0;
        const done = () => {
            pending = { ...pending, reveal: null };
            setReveal(null);
        };
        const run = () => {
            const page =
                root.current?.querySelector(
                    `[data-settings-page="${current}"]`,
                ) ?? root.current;

            if (page && revealSetting(page, reveal.anchors)) {
                done();
            } else if (attempts++ < 10) {
                timer = window.setTimeout(run, 60);
            } else {
                done();
            }
        };
        timer = window.setTimeout(run, 30);

        return () => window.clearTimeout(timer);
    }, [reveal, current, requested]);

    // "/" anywhere in settings, ⌘F/Ctrl+F while focus is in the settings.
    useEffect(() => {
        const onKey = (event: globalThis.KeyboardEvent) => {
            const scope = root.current?.closest('aside') ?? root.current;
            const slash =
                event.key === '/' &&
                !event.metaKey &&
                !event.ctrlKey &&
                !event.altKey &&
                !typing(event.target);
            const find =
                (event.metaKey || event.ctrlKey) &&
                !event.shiftKey &&
                !event.altKey &&
                event.key.toLowerCase() === 'f' &&
                (document.activeElement === document.body ||
                    Boolean(scope?.contains(document.activeElement)));

            if (
                (!slash && !find) ||
                event.defaultPrevented ||
                !root.current?.offsetParent ||
                document.querySelector('[role="dialog"][data-state="open"]')
            ) {
                return;
            }

            event.preventDefault();
            const input = searchRef.current;

            if (input && input.offsetParent !== null) {
                input.focus();
                input.select();
            } else {
                // Phones on a page: back to the list, where the field is.
                pending = { ...pending, focusSearch: true, at: Date.now() };
                setFocusSearch(true);
                navigate(null);
            }
        };

        document.addEventListener('keydown', onKey);

        return () => document.removeEventListener('keydown', onKey);
    }, [navigate]);

    useEffect(() => {
        if (focusSearch && requested === null) {
            pending = { ...pending, focusSearch: false };
            setFocusSearch(false);
            searchRef.current?.focus();
        }
    }, [focusSearch, requested]);

    const searching = query.trim() !== '';

    return (
        <div ref={root} className="@container/settings">
            {notice && (
                <p className="mb-6 rounded-lg bg-recessed px-3 py-2 type-small text-muted-foreground">
                    {notice}
                </p>
            )}
            <div className="flex flex-col gap-6 @3xl/settings:grid @3xl/settings:grid-cols-[208px_minmax(0,1fr)] @3xl/settings:gap-10">
                <aside
                    className={
                        requested === null
                            ? 'block'
                            : 'hidden @3xl/settings:block'
                    }
                >
                    <div className="flex flex-col gap-4 [view-transition-name:lr-settings-nav] @3xl/settings:sticky @3xl/settings:top-6">
                        <SettingsSearch
                            index={index}
                            query={query}
                            onQuery={setQuery}
                            onSelect={select}
                            inputRef={searchRef}
                            emptyHint={emptyHint}
                        />
                        {!searching && (
                            <div className="hidden @3xl/settings:block">
                                <SettingsSidebar
                                    label={label}
                                    groups={groups}
                                    current={current}
                                    hrefFor={hrefFor}
                                    onNavigate={onNavigate}
                                    dirty={dirty}
                                />
                            </div>
                        )}
                    </div>
                </aside>

                {requested === null && !searching && (
                    <div className="@3xl/settings:hidden">
                        <SettingsDrillDown
                            label={label}
                            groups={groups}
                            hrefFor={hrefFor}
                            onNavigate={onNavigate}
                            dirty={dirty}
                        />
                    </div>
                )}

                <div
                    className={
                        requested === null
                            ? 'hidden min-w-0 @3xl/settings:block'
                            : 'min-w-0'
                    }
                >
                    {requested !== null && (
                        <SettingsLink
                            href={hrefFor(null)}
                            onNavigate={onNavigate && (() => onNavigate(null))}
                            className="mb-4 -ml-1 inline-flex h-9 items-center gap-1 rounded-lg pr-2 pl-1 type-label text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none @3xl/settings:hidden"
                        >
                            <ChevronLeft
                                aria-hidden="true"
                                className="size-4"
                            />
                            All settings
                        </SettingsLink>
                    )}
                    <div className="@container/page max-w-[760px]">
                        {children}
                    </div>
                </div>
            </div>
        </div>
    );
}
