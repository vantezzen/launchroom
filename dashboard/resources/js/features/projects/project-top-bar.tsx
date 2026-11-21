import { router, usePage } from '@inertiajs/react';
import { useQuery } from '@tanstack/react-query';
import {
    ArrowRightLeft,
    Check,
    ChevronDown,
    ChevronsUpDown,
    FolderKanban,
    History,
    Plus,
    Settings2,
    TriangleAlert,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { PillTabs } from '@/components/patterns/pill-tabs';
import type { PillTab } from '@/components/patterns/pill-tabs';
import { TopBar } from '@/components/patterns/top-bar';
import { ProjectIcon } from '@/components/project-icon';
import { Status, StatusIcon } from '@/components/status';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
    CommandSeparator,
} from '@/components/ui/command';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { readRecent } from '@/features/command-palette/recent';
import { AutoDeployPausedBanner } from '@/features/projects/auto-deploy-paused-banner';
import {
    environmentSwitchHref,
    projectSwitchHref,
} from '@/features/projects/switch-targets';
import { AttentionPopover } from '@/features/service-status/attention-popover';
import { ServiceStatusSync } from '@/features/service-status/service-status-sync';
import { useLiveStatus } from '@/features/service-status/store';
import { StagedChangesPill } from '@/features/staged-changes/staged-changes-pill';
import { useLocationSearch } from '@/hooks/use-observe-scope';
import { requestJson } from '@/lib/http';
import { analyticsHref, logsHref } from '@/lib/links';
import { liveReload } from '@/lib/live-reload';
import { readScope } from '@/lib/observe-scope';
import { cn } from '@/lib/utils';
import { project as projectDeployments } from '@/routes/deployments';
import {
    activity,
    canvas,
    importMethod as newProject,
    index,
    previews as projectPreviews,
    settings,
    show,
} from '@/routes/projects';
import { hint, show as promote } from '@/routes/promotions';

type Hint = { count: number; to: { id: string; name: string } | null };
type Environment = App.Data.Canvas.EnvironmentData;
type NodeStatus = App.Data.Canvas.NodeStatusData;

/** Group headings and rows like ⌘K's (the switchers are small palettes). */
const COMMAND_CLASSES =
    '[&_[cmdk-group-heading]]:px-2 [&_[cmdk-group-heading]]:py-1.5 [&_[cmdk-group-heading]]:type-small [&_[cmdk-group-heading]]:text-muted-foreground max-sm:[&_[cmdk-item]]:min-h-11';

/** Past this many environments the environment menu gets a filter field. */
const FILTER_ENVIRONMENTS_FROM = 8;

/**
 * "Never hide a single point of failure" (docs/02 §5): calm badge while all
 * public traffic goes through one server, with the fix one click away.
 */
function SinglePointOfFailure({
    overview,
}: {
    overview: App.Data.Ingress.EdgeOverviewData;
}) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="sm"
                    aria-label="Single point of failure"
                    className="gap-1.5 px-2 type-small text-muted-foreground"
                >
                    <TriangleAlert
                        aria-hidden="true"
                        className="size-3.5 text-status-progress"
                    />
                    <span className="hidden sm:inline">
                        Single point of failure
                    </span>
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-80 p-3">
                <p className="type-small">
                    All public traffic to this project goes through one server.
                    If it goes down, your apps are unreachable.
                </p>
                <p className="mt-2 type-small text-muted-foreground">
                    Give the edge role to 2–3 public servers and point DNS at
                    all of them (health-checked DNS or a floating IP).
                </p>
                <a
                    href={overview.guideUrl}
                    target="_blank"
                    rel="noreferrer"
                    className="mt-2 inline-block type-small underline underline-offset-4"
                >
                    High-availability ingress guide
                </a>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/**
 * "staging has 6 changes not in production, Compare" (docs/06 §6.1),
 * fetched when the switcher opens.
 */
function PromotionHint({
    project,
    environment,
    onSelect,
}: {
    project: string;
    environment: Environment;
    onSelect: (url: string) => void;
}) {
    const { data: state } = useQuery({
        queryKey: ['promotion-hint', environment.id],
        queryFn: ({ signal }) =>
            requestJson<Hint>(
                'get',
                hint([project, environment.id]).url,
                undefined,
                signal,
            ),
        staleTime: 30_000,
    });

    if (!state || state.to === null || state.count === 0) {
        return null;
    }

    return (
        <CommandItem
            value="compare changes not in"
            className="flex-col items-start gap-0.5"
            onSelect={() =>
                onSelect(
                    promote(project, {
                        query: {
                            from: environment.id,
                            to: state.to?.id ?? '',
                        },
                    }).url,
                )
            }
        >
            <span className="type-small text-muted-foreground">
                {environment.name} has {state.count}{' '}
                {state.count === 1 ? 'change' : 'changes'} not in{' '}
                {state.to.name}
            </span>
            <span className="type-label">Compare</span>
        </CommandItem>
    );
}

/** The environment status worth showing in the bar: only when it needs you. */
function needsYou(status: NodeStatus | undefined): status is NodeStatus {
    return (
        status !== undefined &&
        (status.state === 'failed' ||
            status.state === 'warning' ||
            status.reason === 'not_deployed')
    );
}

/** The Deployments tab's live state: icon plus words for screen readers. */
function DeployIndicator({
    health,
}: {
    health: App.Data.Canvas.EnvironmentHealthData | null;
}) {
    if (!health || (!health.deploying && !health.deployFailed)) {
        return null;
    }

    const state = health.deploying ? 'progress' : 'failed';

    return (
        <span className="inline-flex items-center">
            <StatusIcon state={state} className="size-3" />
            <span className="sr-only">
                {health.deploying ? '(deploying)' : '(last deploy failed)'}
            </span>
        </span>
    );
}

type Tab = PillTab;

/**
 * The project tabs as one segmented row inside the top bar (direction
 * §3.2, `PillTabs`). The selected pill slides between tabs because the top
 * bar persists across project pages; narrow bars get a menu instead.
 */
function ProjectTabs({
    tabs,
    pageLabel,
}: {
    tabs: Tab[];
    /** Pages outside the tabs (activity, promote): their own name. */
    pageLabel: string | null;
}) {
    const active = tabs.find((tab) => tab.active);
    const current = active?.label ?? pageLabel ?? 'Canvas';
    const busy = tabs.some((tab) => tab.indicator);

    return (
        <>
            <PillTabs
                tabs={tabs}
                label="Project"
                className="hidden @4xl:block"
            />

            {/* Narrow bars and phones: the tabs collapse into a menu. */}
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        variant="ghost"
                        size="sm"
                        className="max-w-36 shrink-0 gap-1 px-2 type-label @4xl:hidden"
                        aria-label={`Project page: ${current}`}
                    >
                        <span className="truncate">{current}</span>
                        {busy && !active?.indicator && (
                            <span
                                aria-hidden="true"
                                className="size-1.5 shrink-0 rounded-full bg-signal"
                            />
                        )}
                        <ChevronDown
                            aria-hidden="true"
                            className="size-3.5 shrink-0 text-muted-foreground"
                        />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start" className="w-52">
                    {tabs.map((tab) => (
                        <DropdownMenuItem
                            key={tab.label}
                            onSelect={() => router.visit(tab.href)}
                        >
                            <span className="flex flex-1 items-center gap-1.5">
                                {tab.label}
                                {tab.count !== undefined && tab.count > 0 && (
                                    <span className="text-muted-foreground tabular-nums">
                                        {tab.count}
                                    </span>
                                )}
                                {tab.indicator}
                            </span>
                            {tab.active && (
                                <Check
                                    aria-label="Current page"
                                    className="size-4"
                                />
                            )}
                        </DropdownMenuItem>
                    ))}
                </DropdownMenuContent>
            </DropdownMenu>
        </>
    );
}

/** Project ids in the order they were last visited (⌘K's Recent). */
function recentProjectIds(): string[] {
    const ids: string[] = [];

    for (const visit of readRecent()) {
        if (visit.project && !ids.includes(visit.project)) {
            ids.push(visit.project);
        }
    }

    return ids;
}

/**
 * Project top bar (design-system §4.1, direction §3.2): one 52 px row with
 * `project ▾ / environment ▾`, the project tabs inline (Canvas,
 * Deployments, Logs, Analytics, Previews, Settings) and the staged-changes
 * button right. The environment switcher says when the open environment
 * needs you (failed, needs attention, not deployed); the Deployments tab
 * shows a running or failed deploy. Both switchers are small palettes:
 * type to filter, ↑↓ and ↵. Project activity lives in the project menu (the
 * team Activity page in the sidebar is the main feed).
 */
export function ProjectTopBar() {
    const page = usePage<
        App.Data.Canvas.ProjectShellPropsData & { nodes?: unknown[] }
    >();
    const {
        project,
        environment,
        environments,
        projects,
        abilities,
        edgeOverview,
        projectStatuses,
        liveStatus,
        environmentStatuses,
    } = page.props;
    const onCanvas = page.component === 'projects/canvas';
    const [projectOpen, setProjectOpen] = useState(false);
    const [environmentOpen, setEnvironmentOpen] = useState(false);
    const [phoneOpen, setPhoneOpen] = useState(false);
    const persistent = environments.filter((item) => item.kind !== 'preview');
    const previews = environments.filter((item) => item.kind === 'preview');
    const environmentId = environment?.id ?? environments[0]?.id;
    const search = useLocationSearch();
    const observing = ['projects/logs', 'projects/analytics'].includes(
        page.component,
    );
    const scope = observing ? readScope(search) : null;
    const carried = { range: scope?.range, service: scope?.service };
    // The live-status store (D108): the same snapshot the canvas, the panel
    // and the deployment card read, so the bar never says something else.
    const live = useLiveStatus(environment?.id) ?? liveStatus ?? undefined;
    const environmentHealth = live?.health ?? null;
    const status = environmentHealth?.status;
    const shownStatus = needsYou(status) ? status : null;
    // Problems open a popover of the services and their fixes; "Not
    // deployed" stays a word beside the environment's name.
    const attention = shownStatus !== null && shownStatus.state !== 'idle';

    const tabs: Tab[] = [
        {
            label: 'Canvas',
            href: environmentId
                ? canvas([project.id, environmentId]).url
                : show(project.id).url,
            active: onCanvas,
        },
        {
            label: 'Deployments',
            href: projectDeployments(project.id).url,
            active: page.component.startsWith('projects/deployments'),
            indicator:
                environmentHealth &&
                (environmentHealth.deploying ||
                    environmentHealth.deployFailed) ? (
                    <DeployIndicator health={environmentHealth} />
                ) : undefined,
        },
        // Logs and Analytics keep the observe scope (range and service)
        // between each other (docs/08 §1).
        ...(environmentId
            ? [
                  {
                      label: 'Logs',
                      href: logsHref({
                          project: project.id,
                          environment: environmentId,
                          ...carried,
                      }),
                      active: page.component === 'projects/logs',
                  },
                  {
                      label: 'Analytics',
                      href: analyticsHref({
                          project: project.id,
                          environment: environmentId,
                          ...carried,
                      }),
                      active: page.component === 'projects/analytics',
                  },
              ]
            : []),
        {
            label: 'Previews',
            href: projectPreviews(project.id).url,
            active: page.component === 'projects/previews',
            count: previews.length,
        },
        {
            label: 'Settings',
            href: settings({ project: project.id }).url,
            active: page.component === 'projects/settings',
        },
    ];

    const close = () => {
        setProjectOpen(false);
        setEnvironmentOpen(false);
        setPhoneOpen(false);
    };
    const visit = (url: string) => {
        close();
        router.visit(url);
    };

    const projectGroups = (
        <ProjectRows
            open={projectOpen || phoneOpen}
            projects={projects}
            current={project.id}
            statuses={projectStatuses}
            hrefFor={(item) =>
                projectSwitchHref(page.component, page.url, item)
            }
            onVisit={visit}
            footer={
                <CommandGroup forceMount>
                    <CommandItem
                        forceMount
                        value="new project"
                        onSelect={() => visit(newProject().url)}
                    >
                        <Plus aria-hidden="true" />
                        New project
                    </CommandItem>
                    <CommandItem
                        forceMount
                        value="all projects"
                        onSelect={() => visit(index().url)}
                    >
                        <FolderKanban aria-hidden="true" />
                        All projects
                    </CommandItem>
                    <CommandItem
                        forceMount
                        value="project activity"
                        onSelect={() => visit(activity(project.id).url)}
                    >
                        <History aria-hidden="true" />
                        Project activity
                    </CommandItem>
                </CommandGroup>
            }
        />
    );

    const environmentHref = (item: Environment) =>
        environmentSwitchHref(page.component, project.id, item.id, page.url);
    const environmentStatus = (item: Environment) =>
        item.id === environment?.id && status
            ? status
            : environmentStatuses?.find(
                  (entry) => entry.environmentId === item.id,
              )?.status;
    const environmentRow = (item: Environment) => (
        <EnvironmentItem
            key={item.id}
            href={environmentHref(item)}
            item={item}
            status={environmentStatus(item)}
            current={item.id === environment?.id}
            onVisit={visit}
        />
    );

    const environmentGroups = (
        <>
            {(environmentOpen || phoneOpen) && <LoadEnvironmentStatuses />}
            <CommandGroup heading="Environments">
                {persistent.map(environmentRow)}
            </CommandGroup>
            {previews.length > 0 && (
                <CommandGroup heading="Previews">
                    {previews.map(environmentRow)}
                </CommandGroup>
            )}
            <CommandSeparator className="my-1" />
            <CommandGroup forceMount>
                {(environmentOpen || phoneOpen) &&
                    environment &&
                    environment.kind === 'persistent' && (
                        <PromotionHint
                            project={project.id}
                            environment={environment}
                            onSelect={visit}
                        />
                    )}
                {persistent.length > 1 && (
                    <CommandItem
                        forceMount
                        value="compare and promote"
                        onSelect={() => visit(promote(project.id).url)}
                    >
                        <ArrowRightLeft aria-hidden="true" />
                        Compare & promote
                    </CommandItem>
                )}
                {abilities.manage && (
                    <CommandItem
                        forceMount
                        value="new environment"
                        onSelect={() =>
                            visit(
                                settings(
                                    {
                                        project: project.id,
                                        section: 'environments',
                                    },
                                    { query: { add: 'environment' } },
                                ).url,
                            )
                        }
                    >
                        <Plus aria-hidden="true" />
                        New environment
                    </CommandItem>
                )}
                <CommandItem
                    forceMount
                    value="manage environments"
                    onSelect={() =>
                        visit(
                            settings({
                                project: project.id,
                                section: 'environments',
                            }).url,
                        )
                    }
                >
                    <Settings2 aria-hidden="true" />
                    Manage environments
                </CommandItem>
            </CommandGroup>
        </>
    );

    // Pages outside the tabs name themselves in the page menu.
    const pageLabels: Record<string, string> = {
        'projects/activity': 'Activity',
        'projects/promote': 'Promote',
        'projects/changes': 'Changes',
    };
    const pageLabel = pageLabels[page.component] ?? null;
    // On the canvas the floating toolbar carries "Review & apply" (§13.9);
    // the top bar keeps it everywhere else. Compare & promote lists the
    // changes itself, so the pill would only say it twice there.
    const stagedInToolbar = onCanvas && (page.props.nodes?.length ?? 0) > 0;
    const onPromote = page.component === 'projects/promote';
    const environmentName = environment?.name ?? 'Environment';
    const statusWords = shownStatus ? `, ${shownStatus.label}` : '';

    return (
        <TopBar
            actions={
                <>
                    {edgeOverview?.singlePointOfFailure && (
                        <SinglePointOfFailure overview={edgeOverview} />
                    )}
                    {environment && !onPromote && (
                        <StagedChangesPill
                            project={project.id}
                            environment={environment.id}
                            showButton={!stagedInToolbar}
                        />
                    )}
                </>
            }
            below={
                environment?.autoDeployPaused ? (
                    <AutoDeployPausedBanner
                        project={project.id}
                        environment={environment}
                        canResume={abilities.deploy}
                    />
                ) : undefined
            }
        >
            {environment && (
                <ServiceStatusSync
                    project={project.id}
                    environment={environment.id}
                    seed={liveStatus}
                />
            )}
            <nav
                aria-label="Project and environment"
                className="flex min-w-0 shrink items-center gap-0.5"
            >
                {/* Phones: one switcher, project over environment, so both
                    names get the full width instead of truncating. */}
                <Popover open={phoneOpen} onOpenChange={setPhoneOpen}>
                    <PopoverTrigger asChild>
                        <Button
                            variant="ghost"
                            className="h-11 min-w-0 justify-start gap-2 px-1.5 sm:hidden"
                            aria-label={`${project.name}, ${environmentName}${statusWords}: switch project or environment`}
                        >
                            <ProjectIcon
                                name={project.name}
                                iconUrl={project.iconUrl}
                            />
                            <span className="flex min-w-0 flex-col items-start text-left leading-tight">
                                <span className="max-w-full truncate type-label">
                                    {project.name}
                                </span>
                                <span className="flex max-w-full min-w-0 items-center gap-1 type-small text-muted-foreground">
                                    {shownStatus && !attention && (
                                        <StatusIcon
                                            state={shownStatus.state}
                                            className="size-3"
                                        />
                                    )}
                                    <span className="truncate">
                                        {environmentName}
                                    </span>
                                </span>
                            </span>
                            <ChevronsUpDown
                                aria-hidden="true"
                                className="size-3.5 shrink-0 text-muted-foreground"
                            />
                        </Button>
                    </PopoverTrigger>
                    <SwitcherContent
                        className="w-[min(20rem,calc(100vw-2rem))]"
                        placeholder="Find project or environment…"
                        empty="Nothing found."
                    >
                        {environmentGroups}
                        <CommandSeparator className="my-1" />
                        {projectGroups}
                    </SwitcherContent>
                </Popover>

                <Popover open={projectOpen} onOpenChange={setProjectOpen}>
                    <PopoverTrigger asChild>
                        <Button
                            variant="ghost"
                            size="sm"
                            className="hidden min-w-0 gap-1.5 px-2 type-label sm:inline-flex"
                        >
                            <ProjectIcon
                                name={project.name}
                                iconUrl={project.iconUrl}
                                fallback="none"
                            />
                            <span className="truncate">{project.name}</span>
                            <ChevronsUpDown
                                aria-hidden="true"
                                className="size-3.5 text-muted-foreground"
                            />
                        </Button>
                    </PopoverTrigger>
                    <SwitcherContent
                        className="w-80"
                        placeholder="Find project…"
                        empty="No project matches."
                    >
                        {projectGroups}
                    </SwitcherContent>
                </Popover>

                <span
                    aria-hidden="true"
                    className="hidden text-faint sm:inline"
                >
                    /
                </span>

                <Popover
                    open={environmentOpen}
                    onOpenChange={setEnvironmentOpen}
                >
                    <PopoverTrigger asChild>
                        <Button
                            variant="ghost"
                            size="sm"
                            className="hidden min-w-0 gap-1.5 px-2 type-label sm:inline-flex"
                            disabled={!environment && environments.length === 0}
                            aria-label={
                                shownStatus
                                    ? `${environmentName}${statusWords}`
                                    : undefined
                            }
                        >
                            <span className="truncate">{environmentName}</span>
                            {shownStatus && !attention && (
                                <span
                                    className="flex min-w-0 items-center gap-1 type-small font-normal text-muted-foreground"
                                    data-test="environment-status"
                                >
                                    <StatusIcon
                                        state={shownStatus.state}
                                        className="size-3"
                                    />
                                    <span className="hidden truncate @5xl:inline">
                                        {shownStatus.label}
                                    </span>
                                </span>
                            )}
                            <ChevronsUpDown
                                aria-hidden="true"
                                className="size-3.5 text-muted-foreground"
                            />
                        </Button>
                    </PopoverTrigger>
                    <SwitcherContent
                        className="w-72"
                        placeholder={
                            environments.length >= FILTER_ENVIRONMENTS_FROM
                                ? 'Find environment…'
                                : null
                        }
                        empty="No environment matches."
                    >
                        {environmentGroups}
                    </SwitcherContent>
                </Popover>
                {attention && environment && (
                    <AttentionPopover
                        project={project.id}
                        environment={environment.id}
                        status={shownStatus}
                        canDeploy={abilities.deploy}
                        className="shrink-0"
                    />
                )}
            </nav>

            <span
                aria-hidden="true"
                className="mx-1 hidden h-4 w-px bg-border @4xl:block"
            />

            <ProjectTabs tabs={tabs} pageLabel={pageLabel} />
        </TopBar>
    );
}

/**
 * A switcher's popover: a small palette. Without a filter field the list
 * itself takes focus, so ↑↓ and ↵ still work.
 */
function SwitcherContent({
    className,
    placeholder,
    empty,
    children,
}: {
    className?: string;
    /** The filter field's placeholder; null hides the field. */
    placeholder: string | null;
    empty: string;
    children: ReactNode;
}) {
    const root = useRef<HTMLDivElement>(null);

    return (
        <PopoverContent
            align="start"
            className={cn('overflow-hidden p-0', className)}
            onOpenAutoFocus={(event) => {
                // Touch screens: no keyboard popping up over the list.
                const touch = window.matchMedia('(pointer: coarse)').matches;

                if (placeholder === null || touch) {
                    event.preventDefault();
                    root.current?.focus();
                }
            }}
        >
            <Command
                ref={root}
                tabIndex={-1}
                loop
                className={cn(COMMAND_CLASSES, 'outline-hidden')}
            >
                {placeholder !== null && (
                    <CommandInput placeholder={placeholder} />
                )}
                <CommandList>
                    <CommandEmpty>{empty}</CommandEmpty>
                    {children}
                </CommandList>
            </Command>
        </PopoverContent>
    );
}

/**
 * The project menu's rows: projects visited lately first (⌘K's Recent),
 * then the rest by name, each with its status (production's, or the open
 * environment's for this project).
 */
function ProjectRows({
    open,
    projects,
    current,
    statuses,
    hrefFor,
    onVisit,
    footer,
}: {
    open: boolean;
    projects: App.Data.Canvas.ProjectLinkData[];
    current: string;
    statuses: App.Data.Canvas.ProjectStatusData[] | undefined;
    hrefFor: (item: App.Data.Canvas.ProjectLinkData) => string;
    onVisit: (url: string) => void;
    footer: ReactNode;
}) {
    const [recent, setRecent] = useState<string[]>([]);

    useEffect(() => {
        if (open) {
            setRecent(recentProjectIds());
        }
    }, [open]);

    const recentProjects = recent
        .filter((id) => id !== current)
        .map((id) => projects.find((item) => item.id === id))
        .filter((item) => item !== undefined)
        .slice(0, 3);
    const rest = projects.filter((item) => !recentProjects.includes(item));
    const row = (item: App.Data.Canvas.ProjectLinkData) => (
        <CommandItem
            key={item.id}
            value={`${item.name} ${item.id}`}
            {...switchTo(hrefFor(item), onVisit)}
        >
            <ProjectIcon name={item.name} iconUrl={item.iconUrl} />
            <span className="flex-1 truncate">{item.name}</span>
            <ProjectStatus
                status={
                    statuses?.find((entry) => entry.projectId === item.id)
                        ?.status
                }
            />
            {item.id === current && (
                <Check aria-label="Current project" className="size-4" />
            )}
        </CommandItem>
    );

    return (
        <>
            {open && <LoadProjectStatuses loaded={statuses !== undefined} />}
            {recentProjects.length > 0 && (
                <CommandGroup heading="Recent">
                    {recentProjects.map(row)}
                </CommandGroup>
            )}
            <CommandGroup
                heading={
                    recentProjects.length > 0 ? 'All projects' : 'Projects'
                }
            >
                {rest.map(row)}
            </CommandGroup>
            <CommandSeparator className="my-1" />
            {footer}
        </>
    );
}

/**
 * A project's status in the switcher (production's, or this environment's
 * for the current project).
 */
function ProjectStatus({ status }: { status: NodeStatus | undefined }) {
    if (!status || status.reason === 'no_services') {
        return null;
    }

    return (
        <Status
            state={status.state}
            label={status.label}
            className="max-w-32 shrink-0 truncate type-small text-muted-foreground"
        />
    );
}

/** Loads the switcher's statuses when the project menu opens (an optional prop). */
function LoadProjectStatuses({ loaded }: { loaded: boolean }) {
    useEffect(() => {
        if (!loaded) {
            liveReload(['projectStatuses']);
        }
    }, [loaded]);

    return null;
}

/** Fresh environment statuses each time the environment menu opens. */
function LoadEnvironmentStatuses() {
    useEffect(() => {
        liveReload(['environmentStatuses']);
    }, []);

    return null;
}

/**
 * A switcher entry: opens `href`, and starts loading it while the pointer
 * rests on it.
 */
function switchTo(href: string, onVisit: (url: string) => void) {
    let timer: number | undefined;

    return {
        onSelect: () => onVisit(href),
        onPointerEnter: () => {
            window.clearTimeout(timer);
            timer = window.setTimeout(
                () => router.prefetch(href, {}, { cacheFor: '5s' }),
                75,
            );
        },
        onPointerLeave: () => window.clearTimeout(timer),
    };
}

function EnvironmentItem({
    href,
    item,
    status,
    current,
    onVisit,
}: {
    /** The same page in this environment where it has one (switch-targets.ts). */
    href: string;
    item: Environment;
    status: NodeStatus | undefined;
    current: boolean;
    onVisit: (url: string) => void;
}) {
    const trigger =
        item.branch ?? (item.tagPattern ? `tags ${item.tagPattern}` : null);
    // A name like "main" or "live" says nothing about production: the tag does.
    const productionTag =
        item.kind === 'production' && item.name.toLowerCase() !== 'production';

    return (
        <CommandItem
            value={`${item.name} ${item.branch ?? ''} ${item.id}`}
            {...switchTo(href, onVisit)}
        >
            {status && status.reason !== 'no_services' ? (
                <span className="flex size-4 items-center justify-center">
                    <StatusIcon state={status.state} />
                    <span className="sr-only">{status.label}</span>
                </span>
            ) : (
                <span aria-hidden="true" className="size-4" />
            )}
            <span className="min-w-0 flex-1 truncate">{item.name}</span>
            {productionTag && (
                <span className="shrink-0 rounded-sm border px-1 type-small text-muted-foreground">
                    Production
                </span>
            )}
            {trigger && (
                <span className="max-w-24 shrink-0 truncate type-mono-small text-muted-foreground">
                    {trigger}
                </span>
            )}
            {current && (
                <Check aria-label="Current environment" className="size-4" />
            )}
        </CommandItem>
    );
}
