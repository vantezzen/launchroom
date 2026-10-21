import { router } from '@inertiajs/react';
import { ChevronLeft } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { ManagedByFile } from '@/features/config-as-code/managed-by-file';
import services from '@/routes/projects/services';
import { AdvancedSection } from './advanced-section';
import { BuildSection } from './build-section';
import { describeCron, parseCron } from './cron';
import { DangerZone } from './danger-zone';
import { DeploySection } from './deploy-section';
import { DomainsSection } from './domains-section';
import { GeneralSection } from './general-section';
import { NetworkingSection } from './networking-section';
import { ScalingSection } from './scaling-section';
import { ScheduleSection } from './schedule-section';
import { SettingsPageProvider } from './section';
import { allPages, areaOf, DEFAULT_SECTION, settingsAreas } from './sections';
import type { SectionId } from './sections';
import type { SettingsContext } from './settings-context';
import { settingsIndex } from './settings-index';
import type { SettingResult } from './settings-index';
import { SettingsDrillDown, SettingsSidebar } from './settings-nav';
import { SettingsSearch } from './settings-search';
import { SourceSection } from './source-section';
import { VolumesSection } from './volumes-section';

export type Navigate = (section: SectionId | null) => void;

function pageFor(
    id: SectionId,
    context: SettingsContext,
    navigate: Navigate,
): ReactNode {
    switch (id) {
        case 'general':
            return <GeneralSection context={context} navigate={navigate} />;
        case 'source':
            return <SourceSection context={context} />;
        case 'build':
            return <BuildSection context={context} />;
        case 'deploy':
            return <DeploySection context={context} />;
        case 'schedule':
            return <ScheduleSection context={context} />;
        case 'domains':
            return <DomainsSection context={context} />;
        case 'networking':
            return <NetworkingSection context={context} navigate={navigate} />;
        case 'scaling':
            return <ScalingSection context={context} />;
        case 'volumes':
            return <VolumesSection context={context} />;
        case 'advanced':
            return <AdvancedSection context={context} />;
        case 'danger':
            return <DangerZone context={context} />;
    }
}

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
        return false;
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

function plural(count: number, word: string): string {
    return `${count} ${word}${count === 1 ? '' : 's'}`;
}

function scheduleSummary(
    schedule: App.Data.Services.ScheduleSettings | null,
): string {
    if (!schedule) {
        return 'Runs continuously';
    }

    const parsed = parseCron(schedule.cron);

    return 'cron' in parsed
        ? `${describeCron(parsed.cron)} · ${schedule.timeZone.replace(/_/g, ' ')}`
        : schedule.cron;
}

/** One quiet line per page for the phone drill-down list. */
function summaries(
    context: SettingsContext,
): Partial<Record<SectionId, string>> {
    const { settings, node } = context;
    const custom = settings.domains.filter(
        (domain) => domain.kind === 'custom',
    );

    return {
        general: node.name,
        domains:
            settings.domains.length === 0
                ? 'No domains yet'
                : (custom[0]?.hostname ?? settings.domains[0].hostname),
        scaling:
            node.kind === 'database'
                ? 'One replica'
                : `${plural(settings.scaling.replicas, 'replica')} · ${settings.scaling.cpus} CPU · ${settings.scaling.memoryMb} MB`,
        volumes:
            settings.volumes.length === 0
                ? 'No volumes'
                : settings.volumes.map((volume) => volume.mountPath).join(', '),
        schedule: scheduleSummary(settings.deploy.schedule),
        advanced:
            node.kind === 'database'
                ? 'Ids, image and replicas for debugging'
                : 'Entrypoint, capabilities and ids',
        danger: settings.enabled ? 'Disable or remove' : 'Disabled',
    };
}

/**
 * Service panel → Settings (docs/08 §16): a sidebar of areas
 * (General · Build & deploy · Network · Scaling · Volumes · Advanced ·
 * Danger zone) beside one page at a time. The page is in the URL
 * (`/s/{service}/settings/{section}`, client-side so switching is instant
 * and Back works). Pages stay mounted once visited, so unsaved edits
 * survive switching (the sidebar marks them). Narrow panels and phones get
 * a drill-down list instead of the sidebar.
 */
export function SettingsTab({
    context,
    section,
}: {
    context: SettingsContext;
    section: string | null;
}) {
    const areas = settingsAreas(context);
    const pages = allPages(areas);
    const requested = pages.find((page) => page.id === section)?.id ?? null;
    const current: SectionId = requested ?? DEFAULT_SECTION;
    const [visited, setVisited] = useState<Set<SectionId>>(
        () => new Set([current]),
    );
    const [project, environment, service] = context.route;
    const root = useRef<HTMLDivElement>(null);
    // Phones: "‹ Network" from a page returns to that area's list.
    const [drillArea, setDrillArea] = useState<string | null>(null);
    const [query, setQuery] = useState('');
    const searchRef = useRef<HTMLInputElement>(null);
    const [reveal, setReveal] = useState<{
        section: SectionId;
        anchors: string[];
    } | null>(null);
    const [focusSearch, setFocusSearch] = useState(false);
    const index = settingsIndex(context, areas);

    useEffect(() => {
        setVisited((previous) =>
            previous.has(current) ? previous : new Set([...previous, current]),
        );
    }, [current]);

    const hrefFor = useCallback(
        (id: SectionId | null) =>
            id === null
                ? services.show({
                      project,
                      environment,
                      service,
                      tab: 'settings',
                  }).url
                : services.section({
                      project,
                      environment,
                      service,
                      section: id,
                  }).url,
        [project, environment, service],
    );

    const navigate: Navigate = useCallback(
        (id) => {
            router.push({
                url: hrefFor(id),
                preserveScroll: true,
                preserveState: true,
                props: (props) => {
                    const panel = (
                        props as {
                            panel?: App.Data.Canvas.ServicePanelData | null;
                        }
                    ).panel;

                    return {
                        ...props,
                        panel: panel ? { ...panel, section: id } : panel,
                    };
                },
            });
            root.current?.closest('[data-panel-scroll]')?.scrollTo({ top: 0 });
        },
        [hrefFor],
    );

    const back = areaOf(areas, current);

    const select = (result: SettingResult) => {
        setQuery('');

        if (result.target === 'variables') {
            router.visit(
                services.show({
                    project,
                    environment,
                    service,
                    tab: 'variables',
                }).url,
                { only: ['panel'], preserveState: true, preserveScroll: true },
            );

            return;
        }

        setReveal({
            section: result.target,
            anchors: [result.anchor, result.fallback].filter(
                (anchor): anchor is string => Boolean(anchor),
            ),
        });
        navigate(result.target);
    };

    // After the page is shown (it may mount first), reveal the setting.
    useEffect(() => {
        if (!reveal || reveal.section !== current || requested === null) {
            return;
        }

        let attempts = 0;
        let timer = 0;
        const run = () => {
            const page = root.current?.querySelector(
                `[data-settings-page="${current}"]`,
            );

            if (page && revealSetting(page, reveal.anchors)) {
                setReveal(null);
            } else if (attempts++ < 10) {
                timer = window.setTimeout(run, 60);
            } else {
                setReveal(null);
            }
        };
        timer = window.setTimeout(run, 30);

        return () => window.clearTimeout(timer);
    }, [reveal, current, requested]);

    // "/" anywhere in settings, ⌘F/Ctrl+F while focus is in the panel.
    useEffect(() => {
        const onKey = (event: globalThis.KeyboardEvent) => {
            const panel = root.current?.closest('aside') ?? root.current;
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
                    Boolean(panel?.contains(document.activeElement)));

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
                setFocusSearch(true);
                navigate(null);
            }
        };

        document.addEventListener('keydown', onKey);

        return () => document.removeEventListener('keydown', onKey);
    }, [navigate]);

    useEffect(() => {
        if (focusSearch && requested === null) {
            setFocusSearch(false);
            searchRef.current?.focus();
        }
    }, [focusSearch, requested]);

    return (
        <div ref={root} className="@container/settings">
            {!context.canUpdate && (
                <p className="mb-6 rounded-lg bg-recessed px-3 py-2 type-small text-muted-foreground">
                    You can view these settings. Changing them needs the
                    developer role or higher.
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
                    <div className="flex flex-col gap-4 @3xl/settings:sticky @3xl/settings:top-6">
                        <SettingsSearch
                            index={index}
                            query={query}
                            onQuery={setQuery}
                            onSelect={select}
                            inputRef={searchRef}
                        />
                        {query.trim() === '' && (
                            <div className="hidden @3xl/settings:block">
                                <SettingsSidebar
                                    areas={areas}
                                    current={current}
                                    hrefFor={hrefFor}
                                    onNavigate={navigate}
                                />
                            </div>
                        )}
                    </div>
                </aside>

                {requested === null && query.trim() === '' && (
                    <div className="@3xl/settings:hidden">
                        <SettingsDrillDown
                            areas={areas}
                            hrefFor={hrefFor}
                            onNavigate={navigate}
                            summaries={summaries(context)}
                            initialArea={drillArea}
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
                        <a
                            href={hrefFor(null)}
                            onClick={(event) => {
                                event.preventDefault();
                                setDrillArea(
                                    back && back.pages.length > 1
                                        ? back.id
                                        : null,
                                );
                                navigate(null);
                            }}
                            className="mb-4 -ml-1 inline-flex h-9 items-center gap-1 rounded-lg pr-2 pl-1 type-label text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none @3xl/settings:hidden"
                        >
                            <ChevronLeft
                                aria-hidden="true"
                                className="size-4"
                            />
                            {back && back.pages.length > 1
                                ? back.label
                                : 'All settings'}
                        </a>
                    )}
                    <div className="@container/page max-w-[760px]">
                        {pages
                            .filter(
                                (page) =>
                                    visited.has(page.id) || page.id === current,
                            )
                            .map((page) => (
                                <div
                                    key={page.id}
                                    data-settings-page={page.id}
                                    hidden={page.id !== current}
                                    className="motion-safe:animate-in motion-safe:duration-base motion-safe:ease-enter motion-safe:fade-in-0"
                                >
                                    <SettingsPageProvider
                                        id={page.id}
                                        active={page.id === current}
                                    >
                                        <ManagedByFile
                                            managed={
                                                context.settings.configFile
                                            }
                                            page={page.id}
                                        >
                                            {pageFor(
                                                page.id,
                                                context,
                                                navigate,
                                            )}
                                        </ManagedByFile>
                                    </SettingsPageProvider>
                                </div>
                            ))}
                    </div>
                </div>
            </div>
        </div>
    );
}
