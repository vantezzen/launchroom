import { router, usePage } from '@inertiajs/react';
import { useQuery } from '@tanstack/react-query';
import {
    ArrowUpRight,
    BarChart3,
    Boxes,
    Database,
    FolderKanban,
    Gauge,
    History,
    Keyboard,
    Layers,
    LayoutDashboard,
    Link2,
    Plus,
    RefreshCw,
    Rocket,
    RotateCcw,
    RotateCw,
    ScrollText,
    Server,
    Settings,
    SquarePlus,
    Undo2,
    UserPlus,
    Variable,
    Waypoints,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Logo } from '@/components/logo';
import { ProjectIcon } from '@/components/project-icon';
import { Status, deploymentStatus, serverStatus } from '@/components/status';
import {
    CommandDialog,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
    CommandSeparator,
    CommandShortcut,
} from '@/components/ui/command';
import { requestJson } from '@/lib/http';
import { analyticsHref, logsHref } from '@/lib/links';
import { prefersReducedMotion } from '@/lib/motion';
import {
    project as projectDeployments,
    rollback,
    show as deploymentShow,
} from '@/routes/deployments';
import { index as paletteIndex } from '@/routes/palette';
import {
    canvas,
    importMethod as newProject,
    previews as projectPreviews,
    settings as projectSettings,
} from '@/routes/projects';
import services from '@/routes/projects/services';
import { create as serversCreate, show as serverShow } from '@/routes/servers';
import { edit as teamSettings } from '@/routes/team';
import { teamNavItems } from '@/features/app-shell/nav-items';
import { traceHref } from '@/features/request-trace/links';
import { normaliseTrace } from '@/features/request-trace/trace-model';
import type { TeamNavItem } from '@/features/app-shell/nav-items';
import { readRecent, recordVisit } from './recent';
import { SETTINGS_PAGES } from './settings-pages';
import type { RecentVisit } from './recent';
import { openLiveUrl, ROUTE, ShortcutsDialog, useShortcuts } from './shortcuts';
import type { OpenService } from './shortcuts';
import {
    redeployService,
    restartService,
} from '@/features/service-lifecycle/actions';
import { statusOf } from '@/features/service-status/live-status';
import { useLiveStatus } from '@/features/service-status/store';

type Palette = App.Data.CommandPalette.PaletteData;
type Item = App.Data.CommandPalette.PaletteItemData;

const OPEN_EVENT = 'launchroom:command-palette';
const SHORTCUTS_EVENT = 'launchroom:shortcuts';

/** Opens ⌘K from anywhere (e.g. a top-bar button). */
export function openCommandPalette(search = '') {
    window.dispatchEvent(new CustomEvent(OPEN_EVENT, { detail: search }));
}

/** Opens the keyboard shortcuts sheet (`?`) from anywhere (the account menu). */
export function openShortcuts() {
    window.dispatchEvent(new CustomEvent(SHORTCUTS_EVENT));
}

/**
 * The palette index (`/-/palette`): with `environment`, that environment's
 * project reports its services' statuses there instead of production's.
 */
function usePaletteIndex(environment: string | null, enabled: boolean) {
    return useQuery({
        queryKey: ['command-palette', environment],
        queryFn: ({ signal }) =>
            requestJson<Palette>(
                'get',
                paletteIndex(
                    environment ? { query: { environment } } : undefined,
                ).url,
                undefined,
                signal,
            ),
        enabled,
        staleTime: 30_000,
    });
}

type CanvasProps = {
    abilities?: App.Data.Canvas.ProjectAbilitiesData;
    nodes?: App.Data.Canvas.CanvasNodeData[];
    panel?: App.Data.Canvas.ServicePanelData | null;
    detail?: { deployment?: App.Data.Deployments.DeploymentSummaryData };
};

type Context = {
    project: string | null;
    environment: string | null;
    service: string | null;
    onCanvas: boolean;
    deployment: App.Data.Deployments.DeploymentSummaryData | null;
};

function useContext(): Context {
    const page = usePage<CanvasProps>();
    const match = ROUTE.exec(page.url.split('?')[0]);

    return {
        project: match?.[1] ?? null,
        environment: match?.[2] ?? null,
        service: match?.[3] ?? null,
        onCanvas: page.component === 'projects/canvas',
        deployment: page.props.detail?.deployment ?? null,
    };
}

type Confirm = { label: string; run: () => void; icon: typeof Rocket };

/** Status in the unified vocabulary (design-system §3.3), icon + word. */
function ItemStatus({ item }: { item: Item }) {
    // The open environment's services: the live-status store's word, the
    // one the canvas and the top bar show right now (D108).
    const live = useLiveStatus(
        item.kind === 'service' ? item.environmentId : null,
    );
    const nodeStatus =
        (item.kind === 'service' ? statusOf(live, item.id) : null) ??
        item.nodeStatus;
    const mapped = nodeStatus
        ? { state: nodeStatus.state, label: nodeStatus.label }
        : item.kind === 'deployment'
          ? deploymentStatus[item.status as App.Enums.DeploymentStatus]
          : item.kind === 'server'
            ? serverStatus[item.status as App.Enums.ServerStatus]
            : undefined;

    if (!mapped) {
        return null;
    }

    return (
        <Status
            state={mapped.state}
            label={mapped.label}
            className="ml-auto shrink-0 type-small text-muted-foreground"
        />
    );
}

const hrefOf = (href: TeamNavItem['href']) =>
    typeof href === 'string' ? href : href.url;

const URGENCY: Record<string, number> = {
    failed: 4,
    warning: 3,
    progress: 2,
    active: 1,
    idle: 0,
};

const urgency = (item: Item) => URGENCY[item.nodeStatus?.state ?? 'idle'];

/** Something runs (or should): Restart and Redeploy make sense. */
const deployed = (item: Item) =>
    item.nodeStatus !== null && item.nodeStatus.state !== 'idle';

/**
 * ⌘K (docs/08 §8, flows §17). Inside a project its services come first,
 * with the status their nodes show (problems on top); with a service open,
 * its commands follow (Logs, Analytics, Open ↗, Redeploy, Restart, Variables,
 * Data). Typing finds every service and its commands ("api logs"), projects,
 * deployments and servers. "Recent" lists places visited lately. Redeploy,
 * Restart and Roll back confirm inline. Variable values never appear here.
 * Keyboard shortcuts and the `?` sheet live in ./shortcuts.
 */
export function CommandPalette() {
    const [open, setOpen] = useState(false);
    const [sheet, setSheet] = useState(false);
    const [search, setSearch] = useState('');
    const [confirm, setConfirm] = useState<Confirm | null>(null);
    const [recent, setRecent] = useState<RecentVisit[]>([]);
    const context = useContext();
    const page = usePage<CanvasProps>();
    const { data } = usePaletteIndex(context.environment, open);

    // The open service on the canvas, for `o` and `a`.
    const props = useRef(page.props);
    useEffect(() => {
        props.current = page.props;
    });
    const openService = useCallback((): OpenService => {
        const { nodes, panel } = props.current;
        const node = panel
            ? nodes?.find((item) => item.id === panel.serviceId)
            : undefined;

        return node
            ? {
                  name: node.name,
                  domain: node.domain,
                  analyticsView: node.analyticsView,
              }
            : null;
    }, []);

    useShortcuts({
        togglePalette: () => setOpen((current) => !current),
        openPalette: (value) => openCommandPalette(value),
        openSheet: () => setSheet(true),
        openService,
    });

    useEffect(() => {
        const onOpen = (event: Event) => {
            setSearch((event as CustomEvent<string>).detail ?? '');
            setOpen(true);
        };

        const onShortcuts = () => setSheet(true);

        window.addEventListener(OPEN_EVENT, onOpen);
        window.addEventListener(SHORTCUTS_EVENT, onShortcuts);

        return () => {
            window.removeEventListener(OPEN_EVENT, onOpen);
            window.removeEventListener(SHORTCUTS_EVENT, onShortcuts);
        };
    }, []);

    useEffect(() => recordVisit(page), [page]);

    useEffect(() => {
        if (open) {
            setRecent(readRecent());
        } else {
            setConfirm(null);
        }
    }, [open]);

    const go = (url: string, panelOnly = false) => {
        setOpen(false);
        router.visit(
            url,
            panelOnly
                ? {
                      only: ['panel'],
                      preserveState: true,
                      preserveScroll: true,
                      viewTransition: !prefersReducedMotion(),
                  }
                : undefined,
        );
    };

    // The confirm row shows the action's own icon (design-system §13.4).
    const ask = (label: string, run: () => void, icon: typeof Rocket) => {
        setConfirm({ label, run, icon });
        setSearch('');
    };

    const searching = search.trim() !== '';
    // A pasted ray (about 300 characters, or a header line) opens its trace.
    const pastedTrace = useMemo(() => {
        const trace = normaliseTrace(search);

        return trace !== null &&
            (trace.length >= 100 ||
                /^lr\d+\./.test(trace) ||
                /x-launchroom-ray|launchroom-trace/i.test(search))
            ? trace
            : null;
    }, [search]);
    const allServices = data?.services ?? [];
    const projectServices = useMemo(
        () =>
            (data?.services ?? [])
                .filter((item) => item.projectId === context.project)
                .sort((a, b) => urgency(b) - urgency(a)),
        [data, context.project],
    );
    const openServiceItem = allServices.find(
        (item) =>
            item.id === context.service && item.projectId === context.project,
    );
    const currentProjectEnvironments = (data?.environments ?? []).filter(
        (item) =>
            item.projectId === context.project &&
            item.environmentId !== context.environment,
    );
    const currentProject = data?.projects.find(
        (item) => item.id === context.project,
    );

    /** A service's panel (in this environment when it's this project's). */
    const panelUrl = (item: Item, tab?: string) => {
        if (!item.projectId || !item.environmentId) {
            return null;
        }

        return services.show({
            project: item.projectId,
            environment: item.environmentId,
            service: item.id,
            ...(tab ? { tab } : {}),
        }).url;
    };
    const inPlace = (item: Item) =>
        context.onCanvas &&
        item.projectId === context.project &&
        item.environmentId === context.environment;

    const urlFor = (item: Item): string | null => {
        switch (item.kind) {
            case 'project':
            case 'environment':
                return item.projectId && item.environmentId
                    ? canvas([item.projectId, item.environmentId]).url
                    : null;
            case 'service':
                return panelUrl(item);
            case 'deployment':
                return item.projectId && item.environmentId
                    ? deploymentShow({
                          project: item.projectId,
                          environment: item.environmentId,
                          deployment: item.id,
                      }).url
                    : null;
            case 'server':
                return serverShow(item.id).url;
            default:
                return null;
        }
    };

    const itemIcon = (item: Item): ReactNode => {
        switch (item.kind) {
            case 'project':
                return item.iconUrl ? (
                    <ProjectIcon
                        name={item.label}
                        iconUrl={item.iconUrl}
                        className="size-4 rounded-[4px] p-0"
                    />
                ) : (
                    <FolderKanban aria-hidden="true" />
                );
            case 'service':
                return item.logo ? (
                    <Logo kind={item.logo} size={20} decorative />
                ) : (
                    <Boxes aria-hidden="true" />
                );
            case 'deployment':
                return <Rocket aria-hidden="true" />;
            case 'server':
                return <Server aria-hidden="true" />;
            default:
                return <Layers aria-hidden="true" />;
        }
    };

    const itemRow = (item: Item, prefix = '', detail = item.detail) => {
        const url = urlFor(item);

        if (url === null) {
            return null;
        }

        return (
            <CommandItem
                key={`${prefix}${item.kind}-${item.id}`}
                value={`${prefix}${item.kind} ${item.label} ${detail ?? ''} ${item.id}`}
                onSelect={() =>
                    go(url, item.kind === 'service' && inPlace(item))
                }
            >
                {itemIcon(item)}
                <span className="truncate">{item.label}</span>
                {detail && (
                    <span className="truncate type-small text-muted-foreground">
                        {detail}
                    </span>
                )}
                <ItemStatus item={item} />
            </CommandItem>
        );
    };

    /**
     * A service's commands. `own` marks the open service: its rows show the
     * shortcuts that act on it.
     */
    const serviceCommands = (item: Item, own: boolean): ReactNode[] => {
        const project = item.projectId;
        const environment = item.environmentId;

        if (!project || !environment) {
            return [];
        }

        const args = { project, environment, service: item.id };
        const words = (verb: string, extra = '') =>
            `${verb} ${item.label} ${extra} ${item.id}`;
        const keywords = (verb: string, ...more: string[]) => [
            item.label,
            `${item.label} ${verb}`,
            ...more,
        ];
        const tab = (id: string) => panelUrl(item, id) ?? '';
        const rows: ReactNode[] = [
            <CommandItem
                key={`logs-${item.id}`}
                value={words('logs')}
                keywords={keywords('logs', 'runtime', 'tail', 'live')}
                onSelect={() => go(tab('logs'), inPlace(item))}
            >
                <ScrollText aria-hidden="true" />
                Logs of {item.label}
                {own && <CommandShortcut>g l</CommandShortcut>}
            </CommandItem>,
            <CommandItem
                key={`analytics-${item.id}`}
                value={words('analytics')}
                keywords={keywords(
                    'analytics',
                    'metrics',
                    'traffic',
                    'requests',
                    'cpu',
                    'memory',
                    'usage',
                    'resources',
                )}
                onSelect={() =>
                    go(
                        analyticsHref({
                            project,
                            environment,
                            service: item.id,
                            view: item.analyticsView ?? undefined,
                        }),
                    )
                }
            >
                <BarChart3 aria-hidden="true" />
                Analytics of {item.label}
                {own && <CommandShortcut>g a</CommandShortcut>}
            </CommandItem>,
        ];

        if (item.domain) {
            const domain = item.domain;
            rows.push(
                <CommandItem
                    key={`open-${item.id}`}
                    value={words('open', domain)}
                    keywords={keywords('open', 'visit', 'url', 'site')}
                    onSelect={() => {
                        setOpen(false);
                        openLiveUrl({ name: item.label, domain });
                    }}
                >
                    <ArrowUpRight aria-hidden="true" />
                    Open {domain}
                    {own && <CommandShortcut>o</CommandShortcut>}
                </CommandItem>,
            );
        }

        if (data?.canDeploy && deployed(item)) {
            rows.push(
                <CommandItem
                    key={`redeploy-${item.id}`}
                    value={words('redeploy')}
                    keywords={keywords('redeploy', 'deploy')}
                    onSelect={() =>
                        ask(
                            `Redeploy ${item.label}`,
                            () => redeployService(args, item.label),
                            RefreshCw,
                        )
                    }
                >
                    <RefreshCw aria-hidden="true" />
                    Redeploy {item.label}
                </CommandItem>,
            );

            if (item.nodeStatus?.reason !== 'deploy_failed') {
                rows.push(
                    <CommandItem
                        key={`restart-${item.id}`}
                        value={words('restart')}
                        keywords={keywords('restart', 'reboot')}
                        onSelect={() =>
                            ask(
                                `Restart ${item.label}`,
                                () => restartService(args, item.label),
                                RotateCw,
                            )
                        }
                    >
                        <RotateCw aria-hidden="true" />
                        Restart {item.label}
                    </CommandItem>,
                );
            }
        }

        rows.push(
            <CommandItem
                key={`variables-${item.id}`}
                value={words('variables', 'env')}
                keywords={keywords('variables', 'env', 'environment')}
                onSelect={() =>
                    go(services.section({ ...args, section: 'variables' }).url)
                }
            >
                <Variable aria-hidden="true" />
                Variables of {item.label}
                {own && <CommandShortcut>g v</CommandShortcut>}
            </CommandItem>,
        );

        if (data?.canWriteVariables && searching) {
            rows.push(
                <CommandItem
                    key={`variable-${item.id}`}
                    value={words('add variable', 'env')}
                    keywords={keywords('add variable')}
                    onSelect={() =>
                        go(
                            `${services.section({ ...args, section: 'variables' }).url}?add=variable`,
                        )
                    }
                >
                    <SquarePlus aria-hidden="true" />
                    Add variable to {item.label}
                </CommandItem>,
            );
        }

        if (item.serviceKind === 'database' && data?.canData) {
            rows.push(
                <CommandItem
                    key={`data-${item.id}`}
                    value={words('data', 'query tables')}
                    keywords={keywords('data', 'query', 'tables', 'connect')}
                    onSelect={() => go(tab('data'), inPlace(item))}
                >
                    <Database aria-hidden="true" />
                    Data of {item.label}
                </CommandItem>,
            );
        }

        return rows;
    };

    // Recent places, resolved against this team's index (stale or foreign
    // ids drop out), without the page you're on.
    const recentRows = useMemo(() => {
        if (!data) {
            return [];
        }

        const environmentName = (id: string | null) =>
            data.environments.find((item) => item.id === id)?.label ?? null;

        return recent
            .filter(
                (visit) =>
                    !(
                        (visit.kind === 'service' &&
                            visit.id === context.service) ||
                        (visit.kind === 'project' &&
                            visit.id === context.project &&
                            visit.environment === context.environment) ||
                        (visit.kind === 'deployment' &&
                            visit.id === context.deployment?.id)
                    ),
            )
            .map((visit) => {
                const list =
                    visit.kind === 'service'
                        ? data.services
                        : visit.kind === 'project'
                          ? data.projects
                          : visit.kind === 'deployment'
                            ? data.deployments
                            : data.servers;
                const item = list.find((entry) => entry.id === visit.id);

                if (!item) {
                    return null;
                }

                if (visit.kind === 'service' || visit.kind === 'project') {
                    const sameEnvironment =
                        item.environmentId === visit.environment;
                    const projectName =
                        visit.kind === 'service'
                            ? data.projects.find(
                                  (entry) => entry.id === item.projectId,
                              )?.label
                            : null;

                    return {
                        item: {
                            ...item,
                            environmentId:
                                visit.environment ?? item.environmentId,
                            nodeStatus: sameEnvironment
                                ? item.nodeStatus
                                : null,
                        },
                        detail: [
                            projectName,
                            environmentName(visit.environment),
                        ]
                            .filter(Boolean)
                            .join(', '),
                    };
                }

                return { item, detail: item.detail };
            })
            .filter((row) => row !== null)
            .slice(0, 5);
    }, [
        data,
        recent,
        context.service,
        context.project,
        context.environment,
        context.deployment,
    ]);

    const navigateRow = (
        value: string,
        icon: ReactNode,
        label: string,
        url: string,
        shortcut?: string,
    ) => (
        <CommandItem key={value} value={value} onSelect={() => go(url)}>
            {icon}
            {label}
            {shortcut && <CommandShortcut>{shortcut}</CommandShortcut>}
        </CommandItem>
    );

    const shared = page.props;
    const navItems = teamNavItems(shared);
    const owner = shared.auth.user?.isInstanceOwner === true;
    const settingsPages = SETTINGS_PAGES.filter(
        (entry) => owner || !entry.ownerOnly,
    );
    const openNode = page.props.panel
        ? page.props.nodes?.find(
              (node) => node.id === page.props.panel?.serviceId,
          )
        : undefined;

    // Create verbs (docs/08 §8): what people open ⌘K for most, shown
    // before typing; each one the viewer may do.
    const createRows: ReactNode[] = [
        navigateRow(
            'new project deploy repository url template create',
            <SquarePlus aria-hidden="true" />,
            'New project',
            newProject().url,
        ),
    ];

    if (
        context.project &&
        context.environment &&
        page.props.abilities?.manage
    ) {
        createRows.push(
            navigateRow(
                'add a service database app image worker cron create new',
                <Plus aria-hidden="true" />,
                'Add a service',
                `${canvas([context.project, context.environment]).url}?add=1`,
            ),
        );
    }

    if (
        context.project &&
        context.environment &&
        openNode?.capabilities.web &&
        page.props.abilities?.manage
    ) {
        createRows.push(
            navigateRow(
                `add domain to ${openNode.name} custom domain dns`,
                <Link2 aria-hidden="true" />,
                `Add domain to ${openNode.name}`,
                services.section({
                    project: context.project,
                    environment: context.environment,
                    service: openNode.id,
                    section: 'domains',
                }).url,
            ),
        );
    }

    if (shared.teams?.canAddServers) {
        createRows.push(
            navigateRow(
                'add server connect machine host create new',
                <Server aria-hidden="true" />,
                'Add server',
                serversCreate().url,
            ),
        );
    }

    if (shared.teams?.canInvite) {
        createRows.push(
            navigateRow(
                'invite people member teammate add user',
                <UserPlus aria-hidden="true" />,
                'Invite people',
                teamSettings().url,
            ),
        );
    }

    return (
        <>
            <ShortcutsDialog open={sheet} onOpenChange={setSheet} />
            <CommandDialog
                open={open}
                onOpenChange={setOpen}
                title="Command palette"
                description="Jump to a page or run an action"
            >
                <CommandInput
                    value={search}
                    onValueChange={setSearch}
                    placeholder={
                        confirm
                            ? 'Press Enter to confirm, Escape to cancel'
                            : 'Search services, projects, deployments, actions…'
                    }
                />
                <CommandList>
                    {!pastedTrace && (
                        <CommandEmpty>Nothing found.</CommandEmpty>
                    )}

                    {searching && !confirm && (
                        <CommandGroup
                            heading="Trace"
                            forceMount={!!pastedTrace}
                        >
                            {pastedTrace ? (
                                <CommandItem
                                    forceMount
                                    value="open this pasted ray"
                                    onSelect={() => go(traceHref(pastedTrace))}
                                >
                                    <Waypoints aria-hidden="true" />
                                    Open this ray
                                    <CommandShortcut>↵</CommandShortcut>
                                </CommandItem>
                            ) : (
                                navigateRow(
                                    'paste a ray trace a request x-launchroom-ray header',
                                    <Waypoints aria-hidden="true" />,
                                    'Paste a ray',
                                    traceHref(),
                                )
                            )}
                        </CommandGroup>
                    )}

                    {confirm ? (
                        <CommandGroup heading="Confirm">
                            <CommandItem
                                value={`confirm ${confirm.label}`}
                                onSelect={() => {
                                    confirm.run();
                                    setOpen(false);
                                }}
                            >
                                <confirm.icon aria-hidden="true" />
                                {confirm.label}
                                <CommandShortcut>↵</CommandShortcut>
                            </CommandItem>
                            <CommandItem
                                value="cancel"
                                onSelect={() => setConfirm(null)}
                            >
                                <Undo2 aria-hidden="true" />
                                Cancel
                            </CommandItem>
                        </CommandGroup>
                    ) : (
                        <>
                            {openServiceItem && !searching && (
                                <CommandGroup heading={openServiceItem.label}>
                                    {serviceCommands(openServiceItem, true)}
                                </CommandGroup>
                            )}

                            {projectServices.length > 0 && (
                                <CommandGroup
                                    heading={
                                        currentProject
                                            ? `Services in ${currentProject.label}`
                                            : 'Services'
                                    }
                                >
                                    {projectServices.map((item) =>
                                        itemRow(item, 'here-', null),
                                    )}
                                </CommandGroup>
                            )}

                            {recentRows.length > 0 && !searching && (
                                <CommandGroup heading="Recent">
                                    {recentRows.map((row) => {
                                        const url = urlFor(row.item);

                                        return url === null ? null : (
                                            <CommandItem
                                                key={`recent-${row.item.kind}-${row.item.id}`}
                                                value={`recent ${row.item.label} ${row.item.id}`}
                                                onSelect={() =>
                                                    go(
                                                        url,
                                                        row.item.kind ===
                                                            'service' &&
                                                            inPlace(row.item),
                                                    )
                                                }
                                            >
                                                {row.item.kind === 'service' &&
                                                row.item.logo ? (
                                                    itemIcon(row.item)
                                                ) : (
                                                    <History aria-hidden="true" />
                                                )}
                                                <span className="truncate">
                                                    {row.item.label}
                                                </span>
                                                {row.detail && (
                                                    <span className="truncate type-small text-muted-foreground">
                                                        {row.detail}
                                                    </span>
                                                )}
                                                <ItemStatus item={row.item} />
                                            </CommandItem>
                                        );
                                    })}
                                </CommandGroup>
                            )}

                            {searching && (
                                <CommandGroup heading="Commands">
                                    {(context.project
                                        ? projectServices
                                        : allServices
                                    ).flatMap((item) =>
                                        serviceCommands(
                                            item,
                                            item.id === context.service,
                                        ),
                                    )}
                                </CommandGroup>
                            )}

                            {(context.deployment?.canRollback ||
                                currentProjectEnvironments.length > 0) && (
                                <CommandGroup heading="Actions">
                                    {context.deployment?.canRollback && (
                                        <CommandItem
                                            value="roll back to this deployment"
                                            onSelect={() => {
                                                const deployment =
                                                    context.deployment;

                                                if (deployment) {
                                                    ask(
                                                        `Roll back ${deployment.serviceName} to this deployment`,
                                                        () =>
                                                            router.post(
                                                                rollback({
                                                                    project:
                                                                        deployment.projectId,
                                                                    environment:
                                                                        deployment.environmentId,
                                                                    deployment:
                                                                        deployment.id,
                                                                }).url,
                                                            ),
                                                        RotateCcw,
                                                    );
                                                }
                                            }}
                                        >
                                            <RotateCcw aria-hidden="true" />
                                            Roll back to this deployment
                                        </CommandItem>
                                    )}
                                    {currentProjectEnvironments.map((item) => (
                                        <CommandItem
                                            key={`switch-${item.id}`}
                                            value={`switch environment ${item.label}`}
                                            onSelect={() =>
                                                item.projectId &&
                                                go(
                                                    canvas([
                                                        item.projectId,
                                                        item.id,
                                                    ]).url,
                                                )
                                            }
                                        >
                                            <Layers aria-hidden="true" />
                                            Switch to {item.label}
                                        </CommandItem>
                                    ))}
                                </CommandGroup>
                            )}

                            <CommandGroup heading="Create">
                                {createRows}
                            </CommandGroup>

                            <CommandSeparator />

                            {context.project && (
                                <CommandGroup
                                    heading={currentProject?.label ?? 'Project'}
                                >
                                    {context.environment && [
                                        navigateRow(
                                            'go canvas of this project',
                                            <LayoutDashboard aria-hidden="true" />,
                                            'Canvas',
                                            canvas([
                                                context.project,
                                                context.environment,
                                            ]).url,
                                            'g c',
                                        ),
                                    ]}
                                    {navigateRow(
                                        'go deployments of this project',
                                        <Rocket aria-hidden="true" />,
                                        'Deployments',
                                        projectDeployments(context.project).url,
                                        'g d',
                                    )}
                                    {context.environment && [
                                        navigateRow(
                                            'go logs of this environment all services',
                                            <ScrollText aria-hidden="true" />,
                                            'Logs of all services',
                                            logsHref({
                                                project: context.project,
                                                environment:
                                                    context.environment,
                                            }),
                                            context.service ? undefined : 'g l',
                                        ),
                                        navigateRow(
                                            'go analytics traffic requests of this environment all services',
                                            <BarChart3 aria-hidden="true" />,
                                            'Analytics of all services',
                                            analyticsHref({
                                                project: context.project,
                                                environment:
                                                    context.environment,
                                            }),
                                            context.service ? undefined : 'g a',
                                        ),
                                        navigateRow(
                                            'go resources metrics cpu memory usage of this environment',
                                            <Gauge aria-hidden="true" />,
                                            'Resources of all services',
                                            analyticsHref({
                                                project: context.project,
                                                environment:
                                                    context.environment,
                                                view: 'resources',
                                            }),
                                        ),
                                    ]}
                                    {navigateRow(
                                        'go previews pull requests of this project',
                                        <Layers aria-hidden="true" />,
                                        'Previews',
                                        projectPreviews(context.project).url,
                                    )}
                                    {navigateRow(
                                        'go project settings',
                                        <Settings aria-hidden="true" />,
                                        'Project settings',
                                        projectSettings({
                                            project: context.project,
                                        }).url,
                                    )}
                                </CommandGroup>
                            )}

                            <CommandGroup heading="Navigate">
                                {navItems.map((item) =>
                                    item.external ? (
                                        <CommandItem
                                            key={`go ${item.title}`}
                                            value={`go ${item.title} ${item.keywords}`}
                                            onSelect={() => {
                                                setOpen(false);
                                                window.open(
                                                    hrefOf(item.href),
                                                    '_blank',
                                                    'noopener',
                                                );
                                            }}
                                        >
                                            {item.icon && (
                                                <item.icon aria-hidden="true" />
                                            )}
                                            {item.title}
                                            <ArrowUpRight
                                                aria-hidden="true"
                                                className="ml-auto text-muted-foreground"
                                            />
                                        </CommandItem>
                                    ) : (
                                        navigateRow(
                                            `go ${item.title} ${item.keywords}`,
                                            item.icon ? (
                                                <item.icon aria-hidden="true" />
                                            ) : null,
                                            item.title,
                                            hrefOf(item.href),
                                            item.shortcut,
                                        )
                                    ),
                                )}
                                {!searching &&
                                    navigateRow(
                                        'go paste a ray trace a request',
                                        <Waypoints aria-hidden="true" />,
                                        'Paste a ray',
                                        traceHref(),
                                    )}
                                <CommandItem
                                    value="keyboard shortcuts help"
                                    onSelect={() => {
                                        setOpen(false);
                                        setSheet(true);
                                    }}
                                >
                                    <Keyboard aria-hidden="true" />
                                    Keyboard shortcuts
                                    <CommandShortcut>?</CommandShortcut>
                                </CommandItem>
                            </CommandGroup>

                            {searching && (
                                <CommandGroup heading="Settings">
                                    {settingsPages.map((entry) => (
                                        <CommandItem
                                            key={`settings-${entry.label}`}
                                            value={`settings ${entry.label} ${entry.group} ${entry.keywords}`}
                                            onSelect={() => go(entry.url())}
                                        >
                                            <Settings aria-hidden="true" />
                                            <span className="truncate">
                                                {entry.label}
                                            </span>
                                            <span className="truncate type-small text-muted-foreground">
                                                {entry.group}
                                            </span>
                                        </CommandItem>
                                    ))}
                                </CommandGroup>
                            )}

                            {data && (
                                <>
                                    <CommandGroup heading="Projects">
                                        {data.projects.map((item) =>
                                            itemRow(item),
                                        )}
                                    </CommandGroup>
                                    {searching && (
                                        <CommandGroup heading="Services">
                                            {allServices
                                                .filter(
                                                    (item) =>
                                                        item.projectId !==
                                                        context.project,
                                                )
                                                .map((item) => itemRow(item))}
                                        </CommandGroup>
                                    )}
                                    <CommandGroup heading="Deployments">
                                        {data.deployments
                                            .slice(0, searching ? undefined : 5)
                                            .map((item) => itemRow(item))}
                                    </CommandGroup>
                                    {searching && (
                                        <CommandGroup heading="Servers">
                                            {data.servers.map((item) =>
                                                itemRow(item),
                                            )}
                                        </CommandGroup>
                                    )}
                                </>
                            )}
                        </>
                    )}
                </CommandList>
                <div className="flex items-center gap-4 border-t px-3 py-2 type-mono-small text-muted-foreground">
                    <span>↑↓ move</span>
                    <span>↵ open</span>
                    <span>esc close</span>
                    <span className="ml-auto">? shortcuts</span>
                </div>
            </CommandDialog>
        </>
    );
}
