import { router } from '@inertiajs/react';
import { Fragment, useEffect, useRef } from 'react';
import { toast } from 'sonner';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { analyticsHref, serviceAnalyticsHref } from '@/lib/links';
import { prefersReducedMotion } from '@/lib/motion';
import { project as projectDeployments } from '@/routes/deployments';
import {
    canvas,
    logs as projectLogs,
    index as projectsIndex,
} from '@/routes/projects';
import services from '@/routes/projects/services';
import { index as serversIndex } from '@/routes/servers';

export const ROUTE =
    /^\/p\/(prj_[0-9A-Z]{26})(?:\/(env_[0-9A-Z]{26}))?(?:\/s\/(svc_[0-9A-Z]{26}))?/;

/** The open service panel's live address (canvas page props), if any. */
export type OpenService = { name: string; domain: string | null } | null;

function isTyping(target: EventTarget | null): boolean {
    const element = target as HTMLElement | null;

    return Boolean(
        element?.closest('input, textarea, select, [contenteditable="true"]'),
    );
}

/** Opens the live URL in a new tab, or says why it can't. */
export function openLiveUrl(service: { name: string; domain: string | null }) {
    if (service.domain) {
        window.open(`https://${service.domain}`, '_blank', 'noopener');
    } else {
        toast(`${service.name} has no public address.`);
    }
}

/**
 * Keyboard shortcuts (docs/08 §4). Shortcuts only navigate or open things:
 * nothing destructive sits on a single key. `g` is a leader for one second.
 * With a service panel open, `g l` goes to its Logs tab, `g a` to its
 * Analytics (Traffic with a domain, else Resources) and `o` opens its live
 * URL; otherwise `g l` and `g a` go to the environment's Logs and
 * Analytics. `g m` (Metrics before D75) opens Resources the same way.
 */
export function useShortcuts({
    togglePalette,
    openPalette,
    openSheet,
    openService,
}: {
    togglePalette: () => void;
    openPalette: (search: string) => void;
    openSheet: () => void;
    openService: () => OpenService;
}) {
    const handlers = useRef({
        togglePalette,
        openPalette,
        openSheet,
        openService,
    });

    useEffect(() => {
        handlers.current = {
            togglePalette,
            openPalette,
            openSheet,
            openService,
        };
    });

    useEffect(() => {
        let leader = 0;

        const onKey = (event: KeyboardEvent) => {
            const on = handlers.current;

            if (
                (event.metaKey || event.ctrlKey) &&
                event.key.toLowerCase() === 'k'
            ) {
                event.preventDefault();
                on.togglePalette();

                return;
            }

            if (
                isTyping(event.target) ||
                event.metaKey ||
                event.ctrlKey ||
                event.altKey ||
                event.defaultPrevented ||
                document.querySelector('[role="dialog"][data-state="open"]')
            ) {
                return;
            }

            const match = ROUTE.exec(window.location.pathname);
            const [project, environment, service] = [
                match?.[1],
                match?.[2],
                match?.[3],
            ];

            if (event.key === '?') {
                event.preventDefault();
                on.openSheet();

                return;
            }

            if (event.key === 'g') {
                leader = Date.now();

                return;
            }

            if (Date.now() - leader > 1000) {
                if (event.key === 'o' && service) {
                    const open = on.openService();

                    if (open) {
                        event.preventDefault();
                        openLiveUrl(open);
                    }
                }

                return;
            }

            leader = 0;
            const panelTab = (tab: string) => {
                if (project && environment && service) {
                    router.visit(
                        services.show({ project, environment, service, tab })
                            .url,
                        // The canvas stays; only the panel reloads (as a click on its tab).
                        {
                            only: ['panel'],
                            preserveState: true,
                            preserveScroll: true,
                            viewTransition: !prefersReducedMotion(),
                        },
                    );
                }
            };

            switch (event.key) {
                case 'p':
                    router.visit(projectsIndex().url);
                    break;
                case 's':
                    router.visit(serversIndex().url);
                    break;
                case 'c':
                    if (!project || !environment) {
                        return;
                    }

                    router.visit(canvas([project, environment]).url);
                    break;
                case 'd':
                    if (!project) {
                        return;
                    }

                    router.visit(projectDeployments(project).url);
                    break;
                case 'l':
                    if (!project || !environment) {
                        return;
                    }

                    if (service) {
                        panelTab('logs');
                    } else {
                        router.visit(projectLogs([project, environment]).url);
                    }

                    break;
                case 'a':
                case 'm':
                    if (!project || !environment) {
                        return;
                    }

                    router.visit(
                        service && event.key === 'a'
                            ? serviceAnalyticsHref({
                                  project,
                                  environment,
                                  service,
                                  hasDomain: Boolean(on.openService()?.domain),
                              })
                            : analyticsHref({
                                  project,
                                  environment,
                                  service,
                                  view:
                                      event.key === 'm'
                                          ? 'resources'
                                          : 'traffic',
                              }),
                    );

                    break;
                case 'v':
                    if (project && environment && service) {
                        router.visit(
                            services.section({
                                project,
                                environment,
                                service,
                                section: 'variables',
                            }).url,
                        );
                    } else {
                        on.openPalette('Variables');
                    }

                    break;
                default:
                    return;
            }

            event.preventDefault();
        };

        document.addEventListener('keydown', onKey);

        return () => document.removeEventListener('keydown', onKey);
    }, []);
}

const isMac =
    typeof navigator !== 'undefined' &&
    /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent);

const GROUPS: { title: string; rows: [string[], string][] }[] = [
    {
        title: 'Anywhere',
        rows: [
            [[isMac ? '⌘' : 'Ctrl', 'K'], 'Search and run commands'],
            [['g', 'p'], 'Projects'],
            [['g', 's'], 'Servers'],
            [['?'], 'This list'],
        ],
    },
    {
        title: 'In a project',
        rows: [
            [['g', 'c'], 'Canvas'],
            [['g', 'd'], 'Deployments'],
            [['g', 'l'], 'Logs of this environment'],
            [['g', 'a'], 'Analytics of this environment'],
            [['Shift', 'D'], 'Review staged changes on the canvas'],
        ],
    },
    {
        title: 'With a service open',
        rows: [
            [['g', 'l'], 'Its logs'],
            [['g', 'a'], 'Its analytics'],
            [['g', 'v'], 'Its variables'],
            [['o'], 'Open its live URL'],
            [['Esc'], 'Close the panel'],
        ],
    },
    {
        title: 'In logs and settings',
        rows: [[['/'], 'Search']],
    },
];

/** `?`: every shortcut on one sheet. */
export function ShortcutsDialog({
    open,
    onOpenChange,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogTitle>Keyboard shortcuts</DialogTitle>
                <DialogDescription className="sr-only">
                    Press g, then the letter.
                </DialogDescription>
                <div className="flex flex-col gap-5">
                    {GROUPS.map((group) => (
                        <section
                            key={group.title}
                            className="flex flex-col gap-1.5"
                        >
                            <h3 className="type-small text-muted-foreground">
                                {group.title}
                            </h3>
                            <dl className="flex flex-col">
                                {group.rows.map(([keys, label]) => (
                                    <div
                                        key={`${group.title}-${label}`}
                                        className="flex items-center justify-between gap-4 py-1"
                                    >
                                        <dt className="type-body">{label}</dt>
                                        <dd className="flex shrink-0 items-center gap-1">
                                            {keys.map((key, index) => (
                                                <Fragment key={key}>
                                                    {index > 0 &&
                                                        keys[0] === 'g' && (
                                                            <span className="type-small text-muted-foreground">
                                                                then
                                                            </span>
                                                        )}
                                                    <kbd className="inline-flex h-6 min-w-6 items-center justify-center rounded-sm border px-1.5 font-sans type-small text-muted-foreground">
                                                        {key}
                                                    </kbd>
                                                </Fragment>
                                            ))}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                        </section>
                    ))}
                </div>
            </DialogContent>
        </Dialog>
    );
}
