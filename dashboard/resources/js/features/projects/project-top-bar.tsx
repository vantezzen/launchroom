import { router, usePage } from '@inertiajs/react';
import { useQuery } from '@tanstack/react-query';
import {
    ArrowRightLeft,
    Check,
    ChevronDown,
    ChevronsUpDown,
    History,
    TriangleAlert,
} from 'lucide-react';
import { useState } from 'react';
import { PillTabs } from '@/components/patterns/pill-tabs';
import type { PillTab } from '@/components/patterns/pill-tabs';
import { TopBar } from '@/components/patterns/top-bar';
import { ProjectIcon } from '@/components/project-icon';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { AutoDeployPausedBanner } from '@/features/projects/auto-deploy-paused-banner';
import { requestJson } from '@/features/projects/http';
import type { ProjectShellProps } from '@/features/projects/types';
import { StagedChangesPill } from '@/features/staged-changes/staged-changes-pill';
import { project as projectDeployments } from '@/routes/deployments';
import {
    activity,
    analyticsPage as projectAnalytics,
    canvas,
    index,
    logs as projectLogs,
    metricsPage as projectMetrics,
    previews as projectPreviews,
    settings,
    show,
} from '@/routes/projects';
import { hint, show as promote } from '@/routes/promotions';

type Hint = { count: number; to: { id: string; name: string } | null };

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
 * "staging has 6 changes that aren't in production · Compare" (docs/06
 * §6.1), fetched when the switcher opens.
 */
function PromotionHint({
    project,
    environment,
}: {
    project: string;
    environment: App.Data.Canvas.EnvironmentData;
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
        <>
            <DropdownMenuSeparator />
            <DropdownMenuItem
                className="flex-col items-start gap-0.5"
                onSelect={() =>
                    router.visit(
                        promote(project, {
                            query: {
                                from: environment.id,
                                to: state.to?.id ?? '',
                            },
                        }),
                    )
                }
            >
                <span className="type-small text-muted-foreground">
                    {environment.name} has {state.count}{' '}
                    {state.count === 1 ? 'change' : 'changes'} not in{' '}
                    {state.to.name}
                </span>
                <span className="type-label">Compare</span>
            </DropdownMenuItem>
        </>
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
                        className="max-w-32 shrink-0 gap-1 px-2 type-label @4xl:hidden"
                        aria-label={`Project page: ${current}`}
                    >
                        <span className="truncate">{current}</span>
                        <ChevronDown
                            aria-hidden="true"
                            className="size-3.5 shrink-0 text-muted-foreground"
                        />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start" className="w-48">
                    {tabs.map((tab) => (
                        <DropdownMenuItem
                            key={tab.label}
                            onSelect={() => router.visit(tab.href)}
                        >
                            <span className="flex-1">{tab.label}</span>
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

/**
 * Project top bar (design-system §4.1, direction §3.2): one 52 px row with
 * `project ▾ / environment ▾`, the project tabs inline (Canvas ·
 * Deployments · Logs · Metrics · Analytics · Previews · Settings) and the staged-changes button
 * right. Project activity lives in the project menu (the team Activity page
 * in the sidebar is the main feed).
 */
export function ProjectTopBar() {
    const page = usePage<ProjectShellProps & { nodes?: unknown[] }>();
    const {
        project,
        environment,
        environments,
        projects,
        abilities,
        edgeOverview,
    } = page.props;
    const onCanvas = page.component === 'projects/canvas';
    const [switcherOpen, setSwitcherOpen] = useState(false);
    const [phoneOpen, setPhoneOpen] = useState(false);
    const persistent = environments.filter((item) => item.kind !== 'preview');
    const previews = environments.filter((item) => item.kind === 'preview');
    const environmentId = environment?.id ?? environments[0]?.id;

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
        },
        ...(environmentId
            ? [
                  {
                      label: 'Logs',
                      href: projectLogs([project.id, environmentId]).url,
                      active: page.component === 'projects/logs',
                  },
                  {
                      label: 'Metrics',
                      href: projectMetrics([project.id, environmentId]).url,
                      active: page.component === 'projects/metrics',
                  },
                  {
                      label: 'Analytics',
                      href: projectAnalytics([project.id, environmentId]).url,
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

    const projectItems = (
        <>
            <DropdownMenuLabel className="type-small text-muted-foreground">
                Projects
            </DropdownMenuLabel>
            {projects.map((item) => (
                <DropdownMenuItem
                    key={item.id}
                    onSelect={() => router.visit(show(item.id))}
                >
                    <ProjectIcon name={item.name} iconUrl={item.iconUrl} />
                    <span className="flex-1 truncate">{item.name}</span>
                    {item.id === project.id && (
                        <Check
                            aria-label="Current project"
                            className="size-4"
                        />
                    )}
                </DropdownMenuItem>
            ))}
            <DropdownMenuSeparator />
            <DropdownMenuItem
                onSelect={() => router.visit(activity(project.id))}
            >
                <History aria-hidden="true" className="size-4" />
                Project activity
            </DropdownMenuItem>
            <DropdownMenuItem onSelect={() => router.visit(index())}>
                All projects
            </DropdownMenuItem>
        </>
    );

    const environmentItems = (
        <>
            <DropdownMenuLabel className="type-small text-muted-foreground">
                Environments
            </DropdownMenuLabel>
            {persistent.map((item) => (
                <EnvironmentItem
                    key={item.id}
                    project={project.id}
                    item={item}
                    current={item.id === environment?.id}
                />
            ))}
            {previews.length > 0 && (
                <>
                    <DropdownMenuSeparator />
                    <DropdownMenuLabel className="type-small text-muted-foreground">
                        Previews
                    </DropdownMenuLabel>
                    {previews.map((item) => (
                        <EnvironmentItem
                            key={item.id}
                            project={project.id}
                            item={item}
                            current={item.id === environment?.id}
                        />
                    ))}
                </>
            )}
            {(switcherOpen || phoneOpen) &&
                environment &&
                environment.kind === 'persistent' && (
                    <PromotionHint
                        project={project.id}
                        environment={environment}
                    />
                )}
            <DropdownMenuSeparator />
            {persistent.length > 1 && (
                <DropdownMenuItem
                    onSelect={() => router.visit(promote(project.id))}
                >
                    <ArrowRightLeft aria-hidden="true" className="size-4" />
                    Compare & promote
                </DropdownMenuItem>
            )}
            <DropdownMenuItem
                onSelect={() =>
                    router.visit(
                        settings({
                            project: project.id,
                            section: 'environments',
                        }),
                    )
                }
            >
                Manage environments
            </DropdownMenuItem>
        </>
    );

    // Pages outside the tabs name themselves in the page menu.
    const pageLabels: Record<string, string> = {
        'projects/activity': 'Activity',
        'projects/promote': 'Promote',
        'projects/changes': 'Changes',
    };
    const pageLabel = pageLabels[page.component] ?? null;
    // On the canvas the floating toolbar carries "Review & deploy" (§13.9);
    // the top bar keeps it everywhere else.
    const stagedInToolbar = onCanvas && (page.props.nodes?.length ?? 0) > 0;

    return (
        <TopBar
            actions={
                <>
                    {edgeOverview?.singlePointOfFailure && (
                        <SinglePointOfFailure overview={edgeOverview} />
                    )}
                    {environment && (
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
            <nav
                aria-label="Project and environment"
                className="flex min-w-0 shrink items-center gap-0.5"
            >
                {/* Phones: one switcher, project over environment, so both
                    names get the full width instead of truncating. */}
                <DropdownMenu open={phoneOpen} onOpenChange={setPhoneOpen}>
                    <DropdownMenuTrigger asChild>
                        <Button
                            variant="ghost"
                            className="h-11 min-w-0 justify-start gap-2 px-1.5 sm:hidden"
                            aria-label={`${project.name}, ${environment?.name ?? 'no environment'}: switch project or environment`}
                        >
                            <ProjectIcon
                                name={project.name}
                                iconUrl={project.iconUrl}
                            />
                            <span className="flex min-w-0 flex-col items-start text-left leading-tight">
                                <span className="max-w-full truncate type-label">
                                    {project.name}
                                </span>
                                <span className="max-w-full truncate type-small text-muted-foreground">
                                    {environment?.name ?? 'Environment'}
                                </span>
                            </span>
                            <ChevronsUpDown
                                aria-hidden="true"
                                className="size-3.5 shrink-0 text-muted-foreground"
                            />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        align="start"
                        className="w-[min(18rem,calc(100vw-2rem))]"
                    >
                        {environmentItems}
                        <DropdownMenuSeparator />
                        {projectItems}
                    </DropdownMenuContent>
                </DropdownMenu>

                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
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
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="start" className="w-64">
                        {projectItems}
                    </DropdownMenuContent>
                </DropdownMenu>

                <span
                    aria-hidden="true"
                    className="hidden text-faint sm:inline"
                >
                    /
                </span>

                <DropdownMenu
                    open={switcherOpen}
                    onOpenChange={setSwitcherOpen}
                >
                    <DropdownMenuTrigger asChild>
                        <Button
                            variant="ghost"
                            size="sm"
                            className="hidden min-w-0 gap-1.5 px-2 type-label sm:inline-flex"
                            disabled={!environment && environments.length === 0}
                        >
                            <span className="truncate">
                                {environment?.name ?? 'Environment'}
                            </span>
                            <ChevronsUpDown
                                aria-hidden="true"
                                className="size-3.5 text-muted-foreground"
                            />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="start" className="w-64">
                        {environmentItems}
                    </DropdownMenuContent>
                </DropdownMenu>
            </nav>

            <span
                aria-hidden="true"
                className="mx-1 hidden h-4 w-px bg-border @4xl:block"
            />

            <ProjectTabs tabs={tabs} pageLabel={pageLabel} />
        </TopBar>
    );
}

function EnvironmentItem({
    project,
    item,
    current,
}: {
    project: string;
    item: App.Data.Canvas.EnvironmentData;
    current: boolean;
}) {
    const trigger =
        item.branch ?? (item.tagPattern ? `tags ${item.tagPattern}` : null);

    return (
        <DropdownMenuItem
            onSelect={() => router.visit(canvas([project, item.id]))}
        >
            <span className="flex-1 truncate">{item.name}</span>
            {trigger && (
                <span className="type-mono-small text-muted-foreground">
                    {trigger}
                </span>
            )}
            {current && (
                <Check aria-label="Current environment" className="size-4" />
            )}
        </DropdownMenuItem>
    );
}
