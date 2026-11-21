import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowUpRight,
    ChevronRight,
    Copy,
    LayoutGrid,
    List,
    MoreHorizontal,
    Plus,
    Search,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { ObjectCard } from '@/components/cards/object-card';
import { MiniBars } from '@/components/charts/mini-bars';
import { Sparkline } from '@/components/charts/sparkline';
import { CommitAvatar } from '@/components/commit-avatar';
import { Logo } from '@/components/logo';
import { MetaLine } from '@/components/patterns/meta-line';
import { Page, PageHeader } from '@/components/patterns/page';
import { ProjectIcon } from '@/components/project-icon';
import { Status, StatusIcon } from '@/components/status';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { MiniCanvas } from '@/features/canvas/mini-canvas';
import { DeploymentLink } from '@/features/deployments/deployment-link';
import {
    deploymentStatusState,
    deploymentTitle,
    deploymentWord,
    firstLine,
    isInProgress,
} from '@/features/deployments/format';
import { EnvironmentLiveUpdates } from '@/features/projects/environment-live-updates';
import { StartEmptyDialog } from '@/features/projects/start-empty-dialog';
import { AppUpdatesSection } from '@/features/app-updates/app-updates-section';
import { logsHref } from '@/lib/links';
import { cn } from '@/lib/utils';
import { index as activity } from '@/routes/activity';
import { project as projectDeployments } from '@/routes/deployments';
import { canvas, importMethod, index, settings, show } from '@/routes/projects';
import { compactAge, formatCompact, plural, shortSha } from '@/lib/format';

type Summary = App.Data.Canvas.ProjectSummaryData;

type Attention = App.Data.Attention.AttentionItemData;

type Recent = App.Data.Deployments.RecentDeploymentData;

type Props = {
    projects: Summary[];
    /** Deferred: undefined until it has loaded. */
    attention?: Attention[];
    /** Deferred: the team's last 3 deployments. */
    recentDeployments?: Recent[];
    canCreate: boolean;
};

/** Rows shown before "Show N more". */
const ATTENTION_ROWS = 5;

/** Frameworks on the "New project" card and the empty state (monochrome). */
const GHOST_LOGOS = ['nextjs', 'laravel', 'python', 'postgres'];

/** The project's headline status: production's. */
function productionOf(project: Summary) {
    return (
        project.environments.find((env) => env.kind === 'production') ??
        project.environments[0]
    );
}

type Sort = 'recent' | 'name';

type View = 'grid' | 'list';

const SORT_KEY = 'launchroom.projects.sort';
const VIEW_KEY = 'launchroom.projects.view';

/** A per-viewer choice from localStorage (may be blocked or empty). */
function readChoice<T extends string>(
    key: string,
    allowed: readonly T[],
): T | null {
    try {
        const value = window.localStorage.getItem(key);

        return allowed.find((choice) => choice === value) ?? null;
    } catch {
        return null;
    }
}

function storeChoice(key: string, value: string): void {
    try {
        window.localStorage.setItem(key, value);
    } catch {
        // Storage blocked: the choice lasts until the page reloads.
    }
}

/** Lists are easier to scan than cards on a phone. */
function defaultView(): View {
    return typeof window !== 'undefined' &&
        window.matchMedia('(max-width: 639px)').matches
        ? 'list'
        : 'grid';
}

/** No services in any of its environments yet. */
function isEmpty(project: Summary): boolean {
    return project.environments.every((env) => env.services === 0);
}

/** Straight to the production canvas (no redirect hop through /p/{project}). */
function projectHref(project: Summary) {
    const production = productionOf(project);

    return production?.kind === 'production'
        ? canvas([project.id, production.id])
        : show(project.id);
}

/** The canvas with the Add sheet open (`?add=1`). */
function addServiceHref(project: Summary): string | null {
    const production = productionOf(project);

    return production
        ? canvas([project.id, production.id], { query: { add: 1 } }).url
        : null;
}

function ProjectStatus({
    project,
    className,
}: {
    project: Summary;
    className?: string;
}) {
    const production = productionOf(project);

    if (!production) {
        return null;
    }

    return isEmpty(project) ? (
        <Status state="idle" label="Empty" className={className} />
    ) : (
        <Status
            state={production.status.state}
            label={production.status.label}
            className={className}
        />
    );
}

function DeployBars({
    project,
    className,
}: {
    project: Summary;
    className?: string;
}) {
    const today = new Date().toISOString().slice(0, 10);

    return (
        <MiniBars
            height={18}
            className={className}
            label={`${project.name} deploys, last 14 days`}
            bars={project.deploys.map((day) => ({
                key: day.date,
                label: new Date(`${day.date}T00:00:00Z`).toLocaleDateString(
                    undefined,
                    { month: 'short', day: 'numeric' },
                ),
                value: day.total,
                failed: day.failed,
                current: day.date === today,
            }))}
        />
    );
}

/**
 * Quick ways into a project without opening it first (PRJ-5): only links to
 * existing pages, nothing destructive. Visible on touch screens, revealed
 * on hover or focus where there's a mouse.
 */
function ProjectMenu({ project }: { project: Summary }) {
    const production = productionOf(project);
    const domain = project.primaryDomain;

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon-sm"
                    aria-label={`Actions for ${project.name}`}
                    className="relative z-10 -my-1 -mr-2 shrink-0 text-muted-foreground data-[state=open]:opacity-100 pointer-fine:opacity-0 pointer-fine:group-focus-within:opacity-100 pointer-fine:group-hover:opacity-100"
                >
                    <MoreHorizontal aria-hidden="true" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="min-w-44">
                {domain && (
                    <DropdownMenuItem asChild>
                        <a
                            href={`https://${domain}`}
                            target="_blank"
                            rel="noreferrer"
                        >
                            Open site
                            <ArrowUpRight
                                aria-hidden="true"
                                className="ml-auto"
                            />
                        </a>
                    </DropdownMenuItem>
                )}
                <DropdownMenuItem asChild>
                    <Link href={projectDeployments(project.id).url}>
                        Deployments
                    </Link>
                </DropdownMenuItem>
                {production && (
                    <DropdownMenuItem asChild>
                        <Link
                            href={logsHref({
                                project: project.id,
                                environment: production.id,
                            })}
                        >
                            Logs
                        </Link>
                    </DropdownMenuItem>
                )}
                <DropdownMenuItem asChild>
                    <Link href={settings({ project: project.id }).url}>
                        Settings
                    </Link>
                </DropdownMenuItem>
                {domain && (
                    <>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            onSelect={() => {
                                void navigator.clipboard
                                    ?.writeText(domain)
                                    .then(() =>
                                        toast.success(`Copied ${domain}`),
                                    );
                            }}
                        >
                            <Copy aria-hidden="true" />
                            Copy domain
                        </DropdownMenuItem>
                    </>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/** The picture of a project without services: a dashed "+" node. */
function EmptyPicture() {
    return (
        <span
            aria-hidden="true"
            className="flex size-14 items-center justify-center rounded-xl border border-dashed border-(--border-strong) bg-card text-muted-foreground"
        >
            <Plus className="size-5" />
        </span>
    );
}

function AddServiceLink({
    project,
    className,
}: {
    project: Summary;
    className?: string;
}) {
    const href = addServiceHref(project);

    if (!href) {
        return null;
    }

    return (
        <Link
            href={href}
            className={cn(
                'relative z-10 inline-flex items-center gap-1 rounded-sm type-small text-muted-foreground underline-offset-4 hover:text-foreground hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                className,
            )}
        >
            <Plus aria-hidden="true" className="size-3.5" />
            Add a service
        </Link>
    );
}

function Card({ project }: { project: Summary }) {
    const production = productionOf(project);
    const others = project.environments.filter(
        (env) => env.id !== production?.id,
    );
    const persistent = others.filter((env) => env.kind !== 'preview');
    const previews = others.length - persistent.length;
    const last = project.lastDeployment;
    const empty = isEmpty(project);
    // Name the failing service (docs/08 §13): the most urgent one links to why.
    const problem = production?.problems[0] ?? null;
    const more = Math.max(0, (production?.problems.length ?? 0) - 1);
    const environments = [
        production?.name ?? 'production',
        ...persistent.map((env) => env.name),
        ...(previews > 0 ? [plural(previews, 'preview')] : []),
    ];

    return (
        <ObjectCard
            title={project.name}
            // Prefetched on hover with a short cache so the canvas isn't
            // served older than a click would fetch.
            href={projectHref(project)}
            prefetch
            cacheFor="3s"
            titleMedia={
                <ProjectIcon
                    name={project.name}
                    iconUrl={project.iconUrl}
                    fallback="none"
                />
            }
            media={
                empty ? (
                    <EmptyPicture />
                ) : (
                    <MiniCanvas canvas={project.canvas} logos={project.logos} />
                )
            }
            status={
                <>
                    <ProjectStatus
                        project={project}
                        className="ml-auto shrink-0 type-small"
                    />
                    <ProjectMenu project={project} />
                </>
            }
            footer={
                <>
                    <MetaLine className="min-w-0 flex-nowrap overflow-hidden">
                        {environments.map((name) => (
                            <span key={name} className="truncate">
                                {name}
                            </span>
                        ))}
                    </MetaLine>
                    <DeployBars
                        project={project}
                        className="w-[84px] shrink-0"
                    />
                </>
            }
        >
            <div className="-mt-2 flex min-w-0 flex-col gap-3">
                {problem && (
                    <p className="flex min-w-0 items-center gap-2 type-small">
                        <span className="min-w-0 truncate">
                            {problem.sentence}
                            {more > 0 && (
                                <span className="text-muted-foreground">
                                    {' '}
                                    and {plural(more, 'more service')}
                                </span>
                            )}
                        </span>
                        <Link
                            href={problem.href}
                            className="relative z-10 ml-auto inline-flex shrink-0 items-center gap-0.5 rounded-sm type-label underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            See why
                            <ChevronRight
                                aria-hidden="true"
                                className="size-3.5"
                            />
                        </Link>
                    </p>
                )}
                {empty ? (
                    <AddServiceLink project={project} className="self-start" />
                ) : (
                    <div className="flex min-w-0 items-center gap-3">
                        {project.primaryDomain ? (
                            <a
                                href={`https://${project.primaryDomain}`}
                                target="_blank"
                                rel="noreferrer"
                                className="relative z-10 inline-flex min-w-0 flex-1 items-center gap-0.5 rounded-sm type-mono-small text-muted-foreground underline-offset-4 hover:text-foreground hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            >
                                <span className="truncate">
                                    {project.primaryDomain}
                                </span>
                                <ArrowUpRight
                                    aria-hidden="true"
                                    className="size-3 shrink-0"
                                />
                                <span className="sr-only">
                                    (opens a new tab)
                                </span>
                            </a>
                        ) : (
                            <p className="min-w-0 flex-1 truncate type-mono-small text-muted-foreground">
                                No domain yet
                            </p>
                        )}
                        {project.requests && (
                            <span className="flex shrink-0 items-center gap-2 type-small text-muted-foreground tabular-nums">
                                <span className="w-14">
                                    <Sparkline
                                        values={project.requests}
                                        height={16}
                                        min={0}
                                        label={`${project.name} requests per hour, last 24 hours`}
                                    />
                                </span>
                                {formatCompact(
                                    project.requests.reduce(
                                        (sum, v) => sum + v,
                                        0,
                                    ),
                                )}{' '}
                                req in 24h
                            </span>
                        )}
                    </div>
                )}
                {last && (
                    <p className="flex min-w-0 items-center gap-2 type-small">
                        <CommitAvatar
                            name={last.author}
                            avatarUrl={last.authorAvatarUrl}
                            size={16}
                        />
                        <span className="min-w-0 truncate">
                            {last.message?.split('\n')[0] ??
                                `${last.serviceName} deployed`}
                        </span>
                        <MetaLine className="shrink-0 flex-nowrap text-muted-foreground">
                            {last.author}
                            <time
                                dateTime={last.createdAt}
                                title={new Date(
                                    last.createdAt,
                                ).toLocaleString()}
                            >
                                {compactAge(last.createdAt)}
                            </time>
                        </MetaLine>
                    </p>
                )}
            </div>
        </ObjectCard>
    );
}

/**
 * The compact view (PRJ-2): one row per project with the same facts as the
 * card, for teams with many projects.
 */
function ProjectRow({ project }: { project: Summary }) {
    const empty = isEmpty(project);
    const last = project.lastDeployment;
    const problem = productionOf(project)?.problems[0] ?? null;

    return (
        <li className="group relative flex min-w-0 items-center gap-3 px-4 py-3 hover:bg-(--hover) has-[a[data-row-link]:focus-visible]:bg-(--hover)">
            <ProjectIcon
                name={project.name}
                iconUrl={project.iconUrl}
                fallback="monogram"
            />
            <span className="flex min-w-0 flex-1 flex-col gap-0.5 sm:flex-row sm:items-baseline sm:gap-3">
                <Link
                    href={projectHref(project)}
                    prefetch
                    cacheFor="3s"
                    data-row-link
                    className="min-w-0 truncate type-body outline-none after:absolute after:inset-0 after:content-['']"
                >
                    {project.name}
                </Link>
                {empty ? (
                    <AddServiceLink project={project} />
                ) : problem ? (
                    <span className="min-w-0 truncate type-small text-muted-foreground">
                        {problem.sentence}
                    </span>
                ) : project.primaryDomain ? (
                    <span className="min-w-0 truncate type-mono-small text-muted-foreground">
                        {project.primaryDomain}
                    </span>
                ) : null}
            </span>
            <ProjectStatus project={project} className="shrink-0 type-small" />
            <span className="hidden w-10 shrink-0 text-right type-small text-muted-foreground tabular-nums sm:block">
                {last && (
                    <time
                        dateTime={last.createdAt}
                        title={`Last deploy ${new Date(last.createdAt).toLocaleString()}`}
                    >
                        {compactAge(last.createdAt)}
                    </time>
                )}
            </span>
            <DeployBars
                project={project}
                className="hidden w-[84px] shrink-0 md:block"
            />
            <ProjectMenu project={project} />
        </li>
    );
}

/**
 * "Needs you" (docs/08 §13): what in the team needs the user, most urgent
 * first, each row a link to the place that fixes it. One muted line when
 * nothing does; nothing at all while it loads.
 */
function NeedsYou({ items }: { items: Attention[] | undefined }) {
    const [expanded, setExpanded] = useState(false);

    if (items === undefined) {
        return null;
    }

    if (items.length === 0) {
        return (
            <p className="-mt-2 mb-6 type-small text-muted-foreground">
                Nothing needs your attention.
            </p>
        );
    }

    const shown = expanded ? items : items.slice(0, ATTENTION_ROWS);
    const hidden = items.length - shown.length;

    return (
        <section aria-labelledby="needs-you" className="mb-8">
            <h2 id="needs-you" className="mb-2 type-label">
                Needs you
                <span className="ml-2 text-muted-foreground tabular-nums">
                    {items.length}
                </span>
            </h2>
            <ul className="divide-y overflow-hidden rounded-[14px] border bg-card">
                {shown.map((item) => (
                    <li key={item.key}>
                        <Link
                            href={item.href}
                            className="group flex min-w-0 items-center gap-3 px-4 py-3 hover:bg-(--hover) focus-visible:bg-(--hover) focus-visible:outline-none"
                        >
                            <StatusIcon state={item.state} />
                            <span className="flex min-w-0 flex-1 flex-col gap-0.5 sm:flex-row sm:items-baseline sm:gap-3">
                                <span className="min-w-0 truncate type-body">
                                    {item.title}
                                </span>
                                <MetaLine className="min-w-0 flex-nowrap overflow-hidden type-small text-muted-foreground">
                                    {item.context && (
                                        <span className="truncate">
                                            {item.context}
                                        </span>
                                    )}
                                    {item.detail && (
                                        <span className="truncate">
                                            {item.detail}
                                        </span>
                                    )}
                                </MetaLine>
                            </span>
                            {item.at && (
                                <time
                                    dateTime={item.at}
                                    title={new Date(item.at).toLocaleString()}
                                    className="hidden shrink-0 type-small text-muted-foreground tabular-nums sm:block"
                                >
                                    {compactAge(item.at)}
                                </time>
                            )}
                            <span className="inline-flex shrink-0 items-center gap-0.5 type-small text-muted-foreground group-hover:text-foreground">
                                {item.action}
                                <ChevronRight
                                    aria-hidden="true"
                                    className="size-3.5"
                                />
                            </span>
                        </Link>
                    </li>
                ))}
            </ul>
            {(hidden > 0 || expanded) && items.length > ATTENTION_ROWS && (
                <button
                    type="button"
                    onClick={() => setExpanded((value) => !value)}
                    className="mt-2 rounded-sm type-small text-muted-foreground underline-offset-4 hover:text-foreground hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    {expanded ? 'Show less' : `Show ${hidden} more`}
                </button>
            )}
        </section>
    );
}

/**
 * "Recent deployments" (docs/08 §13): the team's last three deployments
 * across projects, each a link to its page (prefetched on hover), and a
 * link to the deploys on the activity page. Nothing while it loads or
 * before the first deployment.
 */
function RecentDeployments({ items }: { items: Recent[] | undefined }) {
    if (items === undefined || items.length === 0) {
        return null;
    }

    return (
        <section aria-labelledby="recent-deployments" className="mb-8">
            <div className="mb-2 flex items-baseline justify-between gap-3">
                <h2 id="recent-deployments" className="type-label">
                    Recent deployments
                </h2>
                <Link
                    href={activity({ query: { kind: 'deploys' } })}
                    prefetch
                    className="rounded-sm type-small text-muted-foreground underline-offset-4 hover:text-foreground hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    Show all
                </Link>
            </div>
            <ul className="divide-y overflow-hidden rounded-[14px] border bg-card">
                {items.map(({ deployment, projectName }) => {
                    const message = firstLine(deployment.commitMessage);
                    const sha = shortSha(deployment.commitSha);

                    return (
                        <li key={deployment.id}>
                            <DeploymentLink
                                deployment={deployment}
                                className="flex min-w-0 items-center gap-3 px-4 py-3 hover:bg-(--hover) focus-visible:bg-(--hover) focus-visible:outline-none"
                            >
                                <span className="flex min-w-0 flex-1 flex-col gap-0.5 sm:flex-row sm:items-baseline sm:gap-3">
                                    <span className="min-w-0 truncate type-body">
                                        {deployment.serviceName}
                                    </span>
                                    <MetaLine className="min-w-0 flex-nowrap overflow-hidden type-small text-muted-foreground">
                                        <span className="truncate">
                                            {projectName}
                                        </span>
                                        {message ? (
                                            <span className="truncate">
                                                {message}
                                            </span>
                                        ) : sha ? (
                                            <span className="font-mono">
                                                {sha}
                                            </span>
                                        ) : (
                                            <span className="truncate">
                                                {deploymentTitle(deployment)}
                                            </span>
                                        )}
                                    </MetaLine>
                                </span>
                                <Status
                                    state={deploymentStatusState(deployment)}
                                    label={
                                        isInProgress(deployment.status)
                                            ? deployment.statusLabel
                                            : deploymentWord(deployment)
                                    }
                                    className="shrink-0 type-small"
                                />
                                <time
                                    dateTime={deployment.queuedAt}
                                    title={new Date(
                                        deployment.queuedAt,
                                    ).toLocaleString()}
                                    className="min-w-8 shrink-0 text-right type-small text-muted-foreground tabular-nums"
                                >
                                    {compactAge(deployment.queuedAt)}
                                </time>
                            </DeploymentLink>
                        </li>
                    );
                })}
            </ul>
        </section>
    );
}

function NewProjectCard({ onStartEmpty }: { onStartEmpty: () => void }) {
    return (
        <article className="relative flex min-h-[280px] flex-col overflow-hidden rounded-[14px] border border-dashed bg-transparent hover:border-(--border-strong) has-[a[data-card-link]:focus-visible]:ring-2 has-[a[data-card-link]:focus-visible]:ring-(--signal)">
            <div
                aria-hidden="true"
                className="flex h-[150px] items-center justify-center gap-2 border-b border-dashed"
            >
                {GHOST_LOGOS.map((logo) => (
                    <Logo
                        key={logo}
                        kind={logo}
                        decorative
                        className="text-muted-foreground"
                    />
                ))}
            </div>
            <div className="flex flex-1 flex-col gap-1.5 p-5">
                <h3 className="type-heading">
                    <Link
                        href={importMethod()}
                        data-card-link
                        className="outline-none after:absolute after:inset-0 after:content-['']"
                    >
                        New project
                    </Link>
                </h3>
                <p className="type-body text-muted-foreground">
                    Deploy a repository or a template.
                </p>
                <button
                    type="button"
                    onClick={onStartEmpty}
                    className="relative z-10 mt-auto self-start rounded-sm type-small text-muted-foreground underline-offset-4 hover:text-foreground hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    Start empty instead
                </button>
            </div>
        </article>
    );
}

/** Grid or list (PRJ-2), two icon buttons. */
function ViewToggle({
    view,
    onChange,
}: {
    view: View;
    onChange: (view: View) => void;
}) {
    const options = [
        { value: 'grid', label: 'Show as cards', Icon: LayoutGrid },
        { value: 'list', label: 'Show as list', Icon: List },
    ] as const;

    return (
        <div className="flex shrink-0 rounded-md border p-px">
            {options.map(({ value, label, Icon }) => (
                <button
                    key={value}
                    type="button"
                    aria-label={label}
                    aria-pressed={view === value}
                    title={label}
                    onClick={() => onChange(value)}
                    className="inline-flex size-8 items-center justify-center rounded-[5px] text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none aria-pressed:bg-(--hover) aria-pressed:text-foreground"
                >
                    <Icon aria-hidden="true" className="size-4" />
                </button>
            ))}
        </div>
    );
}

export default function ProjectsIndex({
    projects,
    attention,
    recentDeployments,
    canCreate,
}: Props) {
    const [startEmpty, setStartEmpty] = useState(false);
    const [query, setQuery] = useState('');
    // Per viewer: the server sends recent activity first (docs/08 §13).
    const [sort, setSort] = useState<Sort>(
        () => readChoice(SORT_KEY, ['recent', 'name'] as const) ?? 'recent',
    );
    const [view, setView] = useState<View>(
        () => readChoice(VIEW_KEY, ['grid', 'list'] as const) ?? defaultView(),
    );

    const changeSort = (value: Sort) => {
        setSort(value);
        storeChoice(SORT_KEY, value);
    };

    const changeView = (value: View) => {
        setView(value);
        storeChoice(VIEW_KEY, value);
    };

    // `?new=1` (older links from the first-run checklist) opens the import flow.
    useEffect(() => {
        if (
            canCreate &&
            new URLSearchParams(window.location.search).has('new')
        ) {
            router.visit(importMethod(), { replace: true });
        }
    }, [canCreate]);

    const visible = useMemo(() => {
        const needle = query.trim().toLowerCase();
        const matching =
            needle === ''
                ? projects
                : projects.filter(
                      (project) =>
                          project.name.toLowerCase().includes(needle) ||
                          (project.primaryDomain ?? '').includes(needle),
                  );

        return sort === 'name'
            ? [...matching].sort((a, b) => a.name.localeCompare(b.name))
            : matching;
    }, [projects, query, sort]);

    const services = projects.reduce(
        (sum, project) => sum + (productionOf(project)?.services ?? 0),
        0,
    );
    const today = new Date().toISOString().slice(0, 10);
    const deploysToday = projects.reduce(
        (sum, project) =>
            sum +
            (project.deploys.find((day) => day.date === today)?.total ?? 0),
        0,
    );
    const description =
        projects.length === 0 ? undefined : (
            <MetaLine>
                {plural(projects.length, 'project')}
                {plural(services, 'service')}
                {deploysToday > 0 && `${plural(deploysToday, 'deploy')} today`}
            </MetaLine>
        );

    return (
        <>
            <Head title="Projects" />
            <EnvironmentLiveUpdates
                environments={projects.flatMap((project) =>
                    project.environments.map((environment) => environment.id),
                )}
                only={['projects', 'attention', 'recentDeployments']}
                statuses
            />

            <Page>
                <PageHeader
                    title="Projects"
                    description={description}
                    actions={
                        projects.length > 0 && (
                            <>
                                {projects.length > 3 && (
                                    <>
                                        <div className="relative w-full sm:w-60">
                                            <Search
                                                aria-hidden="true"
                                                className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                                            />
                                            <Input
                                                type="search"
                                                aria-label="Search projects"
                                                placeholder="Search projects"
                                                value={query}
                                                onChange={(event) =>
                                                    setQuery(event.target.value)
                                                }
                                                className="pl-9"
                                            />
                                        </div>
                                        <Select
                                            value={sort}
                                            onValueChange={(value) =>
                                                changeSort(value as Sort)
                                            }
                                        >
                                            <SelectTrigger
                                                aria-label="Sort projects"
                                                className="w-auto"
                                            >
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent align="end">
                                                <SelectItem value="recent">
                                                    Recent activity
                                                </SelectItem>
                                                <SelectItem value="name">
                                                    Name
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                        <ViewToggle
                                            view={view}
                                            onChange={changeView}
                                        />
                                    </>
                                )}
                                {canCreate && (
                                    <Button asChild>
                                        <Link href={importMethod()}>
                                            <Plus aria-hidden="true" />
                                            New project
                                        </Link>
                                    </Button>
                                )}
                            </>
                        )
                    }
                />

                {projects.length === 0 ? (
                    <div className="flex flex-col items-center gap-6 py-10 text-center">
                        <div
                            aria-hidden="true"
                            className="flex h-[150px] w-full max-w-sm items-center justify-center gap-2 rounded-[14px] border border-dashed"
                        >
                            {GHOST_LOGOS.map((logo) => (
                                <Logo
                                    key={logo}
                                    kind={logo}
                                    decorative
                                    className="text-muted-foreground"
                                />
                            ))}
                        </div>
                        <div className="flex flex-col gap-1">
                            <p className="type-heading">No projects yet</p>
                            <p className="type-body text-muted-foreground">
                                {canCreate
                                    ? 'Paste a Git URL or pick a template. launchroom works out how to build and run it.'
                                    : 'Ask a team admin or developer to create one.'}
                            </p>
                        </div>
                        {canCreate && (
                            <div className="flex items-center gap-3">
                                <Button asChild>
                                    <Link href={importMethod()}>
                                        New project
                                    </Link>
                                </Button>
                                <Button
                                    variant="ghost"
                                    onClick={() => setStartEmpty(true)}
                                >
                                    Start with a blank canvas
                                </Button>
                            </div>
                        )}
                    </div>
                ) : (
                    <>
                        {query === '' && (
                            <>
                                <NeedsYou items={attention} />
                                <RecentDeployments items={recentDeployments} />
                            </>
                        )}
                        {visible.length === 0 ? (
                            <p className="py-10 text-center type-body text-muted-foreground">
                                No project matches “{query}”.
                            </p>
                        ) : view === 'list' ? (
                            <ul
                                aria-label="Projects"
                                className="divide-y overflow-hidden rounded-[14px] border bg-card"
                            >
                                {visible.map((project) => (
                                    <ProjectRow
                                        key={project.id}
                                        project={project}
                                    />
                                ))}
                            </ul>
                        ) : (
                            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                                {visible.map((project) => (
                                    <Card key={project.id} project={project} />
                                ))}
                                {canCreate && query === '' && (
                                    <NewProjectCard
                                        onStartEmpty={() => setStartEmpty(true)}
                                    />
                                )}
                            </div>
                        )}
                        {query === '' && <AppUpdatesSection />}
                    </>
                )}
            </Page>

            {canCreate && (
                <StartEmptyDialog
                    open={startEmpty}
                    onOpenChange={setStartEmpty}
                />
            )}
        </>
    );
}

ProjectsIndex.layout = {
    breadcrumbs: [{ title: 'Projects', href: index() }],
};
