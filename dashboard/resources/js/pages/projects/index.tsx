import { Head, Link, router } from '@inertiajs/react';
import { Plus, Search } from 'lucide-react';
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
import { Status } from '@/components/status';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { compactAge, plural } from '@/features/canvas/format';
import { MiniCanvas } from '@/features/canvas/mini-canvas';
import { EnvironmentLiveUpdates } from '@/features/projects/environment-live-updates';
import { StartEmptyDialog } from '@/features/projects/start-empty-dialog';
import { importMethod, index, show } from '@/routes/projects';

type Summary = App.Data.Canvas.ProjectSummaryData;

type Props = {
    projects: Summary[];
    canCreate: boolean;
};

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
    const today = new Date().toISOString().slice(0, 10);
    const environments = [
        production?.name ?? 'production',
        ...persistent.map((env) => env.name),
        ...(previews > 0 ? [plural(previews, 'preview')] : []),
    ];

    return (
        <ObjectCard
            title={project.name}
            href={show(project.id)}
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
                <div className="flex min-w-0 items-center gap-3">
                    <p className="min-w-0 flex-1 truncate type-mono-small text-muted-foreground">
                        {project.primaryDomain ?? 'No domain yet'}
                    </p>
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

export default function ProjectsIndex({ projects, canCreate }: Props) {
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
                only={['projects']}
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
