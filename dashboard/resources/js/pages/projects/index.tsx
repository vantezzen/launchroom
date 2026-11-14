import { Head, Link, router } from '@inertiajs/react';
import { ArrowUpRight, ChevronRight, Plus, Search } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { ObjectCard } from '@/components/cards/object-card';
import { formatCompact } from '@/components/charts/format';
import { MiniBars } from '@/components/charts/mini-bars';
import { Sparkline } from '@/components/charts/sparkline';
import { CommitAvatar } from '@/components/commit-avatar';
import { Logo } from '@/components/logo';
import { MetaLine } from '@/components/patterns/meta-line';
import { Page, PageHeader } from '@/components/patterns/page';
import { ProjectIcon } from '@/components/project-icon';
import { Status, StatusIcon } from '@/components/status';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { compactAge, plural } from '@/features/canvas/format';
import { MiniCanvas } from '@/features/canvas/mini-canvas';
import { DeploymentLink } from '@/features/deployments/deployment-link';
import {
    deploymentStatusState,
    deploymentTitle,
    deploymentWord,
    firstLine,
    isInProgress,
    shortSha,
} from '@/features/deployments/format';
import { EnvironmentLiveUpdates } from '@/features/projects/environment-live-updates';
import { StartEmptyDialog } from '@/features/projects/start-empty-dialog';
import { index as activity } from '@/routes/activity';
import { canvas, importMethod, index, show } from '@/routes/projects';

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

function Card({ project }: { project: Summary }) {
    const production = productionOf(project);
    const others = project.environments.filter(
        (env) => env.id !== production?.id,
    );
    const persistent = others.filter((env) => env.kind !== 'preview');
    const previews = others.length - persistent.length;
    const last = project.lastDeployment;
    // Name the failing service (docs/08 §13): the most urgent one links to why.
    const problem = production?.problems[0] ?? null;
    const more = Math.max(0, (production?.problems.length ?? 0) - 1);
    const today = new Date().toISOString().slice(0, 10);
    const environments = [
        production?.name ?? 'production',
        ...persistent.map((env) => env.name),
        ...(previews > 0 ? [plural(previews, 'preview')] : []),
    ];

    return (
        <ObjectCard
            title={project.name}
            // Straight to the production canvas (no redirect hop through
            // /p/{project}); prefetched on hover with a short cache so the
            // canvas isn't served older than a click would fetch.
            href={
                production?.kind === 'production'
                    ? canvas([project.id, production.id])
                    : show(project.id)
            }
            prefetch
            cacheFor="3s"
            titleMedia={
                <ProjectIcon
                    name={project.name}
                    iconUrl={project.iconUrl}
                    fallback="none"
                />
            }
            media={<MiniCanvas canvas={project.canvas} logos={project.logos} />}
            status={
                production && (
                    <Status
                        state={production.status.state}
                        label={production.status.label}
                        className="ml-auto shrink-0 type-small"
                    />
                )
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
                    <MiniBars
                        height={18}
                        className="w-[84px] shrink-0"
                        label={`${project.name} deploys, last 14 days`}
                        bars={project.deploys.map((day) => ({
                            key: day.date,
                            label: new Date(
                                `${day.date}T00:00:00Z`,
                            ).toLocaleDateString(undefined, {
                                month: 'short',
                                day: 'numeric',
                            }),
                            value: day.total,
                            failed: day.failed,
                            current: day.date === today,
                        }))}
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
                            <span className="sr-only">(opens a new tab)</span>
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
                                project.requests.reduce((sum, v) => sum + v, 0),
                            )}{' '}
                            req in 24h
                        </span>
                    )}
                </div>
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

export default function ProjectsIndex({
    projects,
    attention,
    recentDeployments,
    canCreate,
}: Props) {
    const [startEmpty, setStartEmpty] = useState(false);
    const [query, setQuery] = useState('');

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

        return needle === ''
            ? projects
            : projects.filter(
                  (project) =>
                      project.name.toLowerCase().includes(needle) ||
                      (project.primaryDomain ?? '').includes(needle),
              );
    }, [projects, query]);

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
            />

            <Page>
                <PageHeader
                    title="Projects"
                    description={description}
                    actions={
                        projects.length > 0 && (
                            <>
                                {projects.length > 3 && (
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
                        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                            {visible.map((project) => (
                                <Card key={project.id} project={project} />
                            ))}
                            {visible.length === 0 && (
                                <p className="col-span-full py-10 text-center type-body text-muted-foreground">
                                    No project matches “{query}”.
                                </p>
                            )}
                            {canCreate && query === '' && (
                                <NewProjectCard
                                    onStartEmpty={() => setStartEmpty(true)}
                                />
                            )}
                        </div>
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
