import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowUpRight,
    Check,
    GitBranch,
    Lock,
    Plus,
    Search,
    X,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { Logo } from '@/components/logo';
import { Page, PageHeader } from '@/components/patterns/page';
import { Section } from '@/components/patterns/section';
import { Status } from '@/components/status';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { frameworkLogo, planLine } from '@/features/add-service/framework-logo';
import { providerLabel, providerLogo } from '@/features/git/providers';
import { NewProjectTabs } from '@/features/blueprints/new-project-tabs';
import { compactAge, plural } from '@/features/canvas/format';
import { requestJson, RequestFailed } from '@/features/projects/http';
import { cn } from '@/lib/utils';
import { edit as gitSettings } from '@/routes/git';
import {
    branches as branchesRoute,
    index as repositoriesRoute,
} from '@/routes/git/repositories';
import { importMethod, index as projectsIndex } from '@/routes/projects';
import { inspect, planPreview, store } from '@/routes/projects/import';
import { index as serversIndex } from '@/routes/servers';

type Repository = App.Data.Git.GitRepositoryData;
type Inspection = App.Data.Projects.RepositoryInspectionData;
type InspectedApp = App.Data.Projects.InspectedAppData;
type Preview = App.Data.Canvas.PlanPreviewData;
type Engine = App.Data.Canvas.DatabaseEngineOptionData;

type Props = {
    githubConnected: boolean;
    /** Connected providers (`github`, `gitlab`, `gitea`, `git`; docs/19). */
    providers: string[];
    installUrl: string | null;
    buildServerOnline: boolean;
    engines: Engine[];
};

type AppChoice = {
    rootDirectory: string;
    name: string;
    selected: boolean;
};

type DatabaseChoice = {
    key: string;
    engine: App.Enums.DatabaseEngine;
    name: string;
    reason: string | null;
    connectTo: string[];
    selected: boolean;
};

const POLL_MS = 2000;

/** Variables a connection injects, so `.env.example` keys can be marked as covered. */
const PROVIDED: Record<string, RegExp> = {
    postgres: /^(DATABASE_URL|DB_|PG|POSTGRES_)/,
    mysql: /^(DATABASE_URL|DB_|MYSQL_)/,
    redis: /^(REDIS_|CACHE_|QUEUE_)/,
    valkey: /^(REDIS_|VALKEY_|CACHE_|QUEUE_)/,
};

function slugName(value: string): string {
    return value
        .toLowerCase()
        .replace(/[^a-z0-9-]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 63);
}

function query(): URLSearchParams {
    return new URLSearchParams(window.location.search);
}

function remember(repository: string | null, branch: string | null): void {
    const params = new URLSearchParams();

    if (repository) {
        params.set('repository', repository);
    }

    if (repository && branch) {
        params.set('branch', branch);
    }

    const search = params.toString();
    window.history.replaceState(
        window.history.state,
        '',
        `${window.location.pathname}${search ? `?${search}` : ''}`,
    );
}

/* ------------------------------------------------------------------ */
/* Step 1: pick a repository                                           */
/* ------------------------------------------------------------------ */

function RepositoryPicker({
    providers,
    installUrl,
    onPick,
}: {
    providers: string[];
    installUrl: string | null;
    onPick: (repository: Repository) => void;
}) {
    const connected = providers.length > 0;
    const [provider, setProvider] = useState<string | null>(null);
    const [search, setSearch] = useState('');
    const [repositories, setRepositories] = useState<Repository[] | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (!connected) {
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            requestJson<{ repositories: Repository[] }>(
                'get',
                repositoriesRoute({
                    query: {
                        ...(search ? { search } : {}),
                        ...(provider ? { provider } : {}),
                    },
                }).url,
                undefined,
                controller.signal,
            )
                .then((data) => {
                    setRepositories(data.repositories);
                    setError(null);
                })
                .catch((failure: unknown) => {
                    if (!controller.signal.aborted) {
                        setError(
                            failure instanceof Error
                                ? failure.message
                                : 'Loading repositories failed.',
                        );
                    }
                });
        }, 200);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [search, provider, connected]);

    if (!connected) {
        return (
            <div className="flex flex-col items-center gap-4 rounded-[14px] border border-dashed px-6 py-14 text-center">
                <div className="flex items-center gap-2">
                    {['github', 'gitlab', 'forgejo', 'git'].map((kind) => (
                        <Logo key={kind} kind={kind} size={32} decorative />
                    ))}
                </div>
                <div className="flex flex-col gap-1">
                    <p className="type-heading">Connect your git first</p>
                    <p className="type-body text-muted-foreground">
                        GitHub, GitLab, Gitea, Forgejo or any git server.
                        launchroom deploys the repositories you give it.
                    </p>
                </div>
                <Button asChild>
                    <Link href={gitSettings()}>Connect a git provider</Link>
                </Button>
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-3">
            <div className="relative">
                <Search
                    aria-hidden="true"
                    className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    type="search"
                    aria-label="Search repositories"
                    placeholder="Search repositories"
                    value={search}
                    autoFocus
                    onChange={(event) => setSearch(event.target.value)}
                    className="h-10 pl-9"
                />
            </div>

            {providers.length > 1 && (
                <div
                    role="group"
                    aria-label="Provider"
                    className="flex flex-wrap gap-1.5"
                >
                    {[null, ...providers].map((item) => (
                        <button
                            key={item ?? 'all'}
                            type="button"
                            aria-pressed={provider === item}
                            onClick={() => {
                                setProvider(item);
                                setRepositories(null);
                            }}
                            className={cn(
                                'inline-flex h-7 items-center gap-1.5 rounded-full border px-3 type-small transition-colors duration-fast',
                                provider === item
                                    ? 'border-foreground bg-foreground text-background'
                                    : 'text-muted-foreground hover:bg-hover hover:text-foreground',
                            )}
                        >
                            {item === null
                                ? 'All'
                                : item === 'gitea'
                                  ? 'Gitea / Forgejo'
                                  : item === 'git'
                                    ? 'Git servers'
                                    : providerLabel(item)}
                        </button>
                    ))}
                </div>
            )}

            <div className="overflow-hidden rounded-[14px] border bg-card shadow-card">
                {error && (
                    <p className="px-4 py-6 type-body text-status-error">
                        {error}
                    </p>
                )}
                {repositories === null && !error && (
                    <p className="flex items-center gap-2 px-4 py-6 type-body text-muted-foreground">
                        <Spinner /> Loading repositories
                    </p>
                )}
                {repositories?.length === 0 && (
                    <p className="px-4 py-10 text-center type-body text-muted-foreground">
                        {search
                            ? `No repository matches “${search}”.`
                            : provider === 'git'
                              ? 'No git server repositories yet. Add one in Settings → Git.'
                              : 'No repositories yet. Give launchroom access to the ones you deploy.'}
                    </p>
                )}
                {repositories && repositories.length > 0 && (
                    <ul
                        aria-label="Repositories"
                        className="flex max-h-[min(60vh,520px)] flex-col divide-y overflow-y-auto"
                    >
                        {repositories.map((repository) => {
                            const cut = repository.fullName.lastIndexOf('/');
                            const owner = repository.fullName.slice(0, cut);
                            const name = repository.fullName.slice(cut + 1);

                            return (
                                <li key={repository.id}>
                                    <button
                                        type="button"
                                        disabled={repository.archived}
                                        onClick={() => onPick(repository)}
                                        className="group flex w-full items-center gap-3 px-4 py-3 text-left transition-colors duration-fast hover:bg-hover focus-visible:bg-hover focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        <Logo
                                            kind={providerLogo(
                                                repository.serverKind,
                                            )}
                                            size={32}
                                            name={providerLabel(
                                                repository.provider,
                                            )}
                                        />
                                        <span className="flex min-w-0 flex-1 flex-col">
                                            <span className="truncate type-label">
                                                <span className="font-normal text-muted-foreground">
                                                    {owner}/
                                                </span>
                                                {name}
                                            </span>
                                            <span className="truncate type-small text-muted-foreground">
                                                {repository.archived
                                                    ? 'Archived'
                                                    : repository.pushedAt
                                                      ? `Pushed ${compactAge(repository.pushedAt)} ago`
                                                      : (repository.defaultBranch ??
                                                        'main')}
                                                {providers.length > 1 &&
                                                    repository.provider !==
                                                        'git' &&
                                                    repository.connectionName !==
                                                        null &&
                                                    ` · ${repository.connectionName}`}
                                            </span>
                                        </span>
                                        {repository.private && (
                                            <Lock
                                                aria-label="Private"
                                                className="size-3.5 shrink-0 text-muted-foreground"
                                            />
                                        )}
                                        <span className="shrink-0 type-label text-muted-foreground opacity-0 transition-opacity duration-fast group-hover:opacity-100 group-focus-visible:opacity-100">
                                            Import
                                        </span>
                                    </button>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </div>

            {installUrl && (provider === null || provider === 'github') && (
                <a
                    href={installUrl}
                    target="_blank"
                    rel="noreferrer"
                    className="inline-flex items-center gap-1 self-center type-small text-muted-foreground hover:text-foreground"
                >
                    Don’t see it? Grant access on GitHub
                    <ArrowUpRight aria-hidden="true" className="size-3.5" />
                </a>
            )}
        </div>
    );
}

/* ------------------------------------------------------------------ */
/* Step 2: what we found                                               */
/* ------------------------------------------------------------------ */

function usePlanPreview(
    repository: string,
    branch: string,
    rootDirectory: string,
    enabled: boolean,
): { preview: Preview | null; error: string | null } {
    const [preview, setPreview] = useState<Preview | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (!enabled) {
            return;
        }

        let cancelled = false;
        let timer = 0;
        let rounds = 0;
        const controller = new AbortController();

        const run = () => {
            requestJson<Preview>(
                'get',
                planPreview({
                    query: { repository, branch, rootDirectory },
                }).url,
                undefined,
                controller.signal,
            )
                .then((data) => {
                    if (cancelled) {
                        return;
                    }

                    setPreview(data);

                    // Stop after ~3 minutes: the first build detects anyway.
                    if (data.status === 'pending' && rounds++ < 90) {
                        timer = window.setTimeout(run, POLL_MS);
                    }
                })
                .catch((failure: unknown) => {
                    if (!cancelled) {
                        setError(
                            failure instanceof Error
                                ? failure.message
                                : 'Detection failed.',
                        );
                    }
                });
        };

        setPreview(null);
        setError(null);
        run();

        return () => {
            cancelled = true;
            controller.abort();
            window.clearTimeout(timer);
        };
    }, [repository, branch, rootDirectory, enabled]);

    return { preview, error };
}

function Checkbox({
    checked,
    onChange,
    label,
}: {
    checked: boolean;
    onChange: (checked: boolean) => void;
    label: string;
}) {
    return (
        <label className="relative flex size-5 shrink-0 cursor-pointer items-center justify-center">
            <input
                type="checkbox"
                checked={checked}
                onChange={(event) => onChange(event.target.checked)}
                aria-label={label}
                className="peer absolute inset-0 cursor-pointer opacity-0"
            />
            <span
                aria-hidden="true"
                className={cn(
                    'flex size-5 items-center justify-center rounded-md border transition-colors duration-fast peer-focus-visible:ring-2 peer-focus-visible:ring-ring peer-focus-visible:ring-offset-2',
                    checked
                        ? 'border-foreground bg-foreground text-background'
                        : 'border-border-strong bg-card',
                )}
            >
                {checked && <Check className="size-3.5" strokeWidth={3} />}
            </span>
        </label>
    );
}

function AppCard({
    repository,
    branch,
    app,
    choice,
    multiple,
    databases,
    buildServerOnline,
    error,
    skipped,
    onChange,
    onPlan,
    onToggleProcess,
}: {
    repository: string;
    branch: string;
    app: InspectedApp;
    choice: AppChoice;
    multiple: boolean;
    databases: DatabaseChoice[];
    buildServerOnline: boolean;
    error?: string;
    /** Suggested processes the user unchecked. */
    skipped: string[];
    onChange: (choice: AppChoice) => void;
    onPlan: (root: string, preview: Preview | null) => void;
    onToggleProcess: (name: string, run: boolean) => void;
}) {
    const { preview, error: previewError } = usePlanPreview(
        repository,
        branch,
        app.rootDirectory,
        choice.selected,
    );
    const plan = preview?.plan ?? null;
    const processes = preview?.suggestions?.processes ?? [];

    useEffect(() => {
        onPlan(app.rootDirectory, preview);
        // onPlan is stable enough: keyed by root.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [preview, app.rootDirectory]);

    const covered = app.envKeys.filter((key) =>
        databases.some(
            (db) =>
                db.selected &&
                db.connectTo.includes(app.rootDirectory) &&
                PROVIDED[db.engine]?.test(key),
        ),
    ).length;

    let detection: ReactNode;

    if (!choice.selected) {
        detection = <span className="text-muted-foreground">Not imported</span>;
    } else if (previewError) {
        detection = <span className="text-status-error">{previewError}</span>;
    } else if (!preview || preview.status === 'pending') {
        detection = (
            <Status
                state="progress"
                label={preview?.message ?? 'Inspecting'}
                className="type-small text-muted-foreground"
            />
        );
    } else if (preview.status === 'ready' && plan) {
        detection = (
            <span className="text-muted-foreground">
                {planLine(plan) || 'Detected'}
            </span>
        );
    } else if (preview.status === 'unavailable' || !buildServerOnline) {
        detection = (
            <span className="text-muted-foreground">
                Detected with the first build
            </span>
        );
    } else {
        detection = (
            <span className="text-muted-foreground">
                We couldn’t detect it yet. The first build tries again.
            </span>
        );
    }

    return (
        <article
            className={cn(
                'flex flex-col gap-4 rounded-[14px] border bg-card p-5 shadow-card transition-opacity duration-base',
                !choice.selected && 'opacity-60',
            )}
        >
            <div className="flex items-start gap-4">
                <Logo
                    kind={frameworkLogo(plan?.framework, app.runtime)}
                    size={44}
                    name={choice.name}
                />
                <div className="flex min-w-0 flex-1 flex-col gap-1">
                    <div className="flex min-w-0 items-center gap-2">
                        <h3 className="truncate type-heading">
                            {plan?.framework ?? choice.name}
                        </h3>
                        {app.rootDirectory !== '' && (
                            <span className="truncate type-mono-small text-muted-foreground">
                                {app.rootDirectory}
                            </span>
                        )}
                    </div>
                    <p className="min-w-0 truncate type-small">{detection}</p>
                </div>
                {multiple && (
                    <Checkbox
                        checked={choice.selected}
                        onChange={(selected) =>
                            onChange({ ...choice, selected })
                        }
                        label={`Import ${choice.name}`}
                    />
                )}
            </div>

            {choice.selected && (
                <div className="flex flex-col gap-3 border-t pt-4 sm:flex-row sm:items-end">
                    <div className="flex min-w-0 flex-1 flex-col gap-1.5">
                        <Label htmlFor={`app-${app.rootDirectory || 'root'}`}>
                            Service name
                        </Label>
                        <Input
                            id={`app-${app.rootDirectory || 'root'}`}
                            value={choice.name}
                            maxLength={63}
                            aria-invalid={error ? true : undefined}
                            onChange={(event) =>
                                onChange({
                                    ...choice,
                                    name: event.target.value,
                                })
                            }
                        />
                        {error && (
                            <p className="type-small text-status-error">
                                {error}
                            </p>
                        )}
                    </div>
                    <dl className="flex shrink-0 gap-6 type-small sm:pb-2">
                        {plan?.port && (
                            <div className="flex flex-col">
                                <dt className="text-muted-foreground">Port</dt>
                                <dd className="type-mono">{plan.port}</dd>
                            </div>
                        )}
                        {app.envKeys.length > 0 && (
                            <div className="flex flex-col">
                                <dt className="text-muted-foreground">
                                    .env.example
                                </dt>
                                <dd>
                                    {plural(app.envKeys.length, 'variable')}
                                    {covered > 0 && (
                                        <span className="text-muted-foreground">
                                            {' '}
                                            · {covered} from databases
                                        </span>
                                    )}
                                </dd>
                            </div>
                        )}
                    </dl>
                </div>
            )}

            {choice.selected && processes.length > 0 && (
                <div className="flex flex-col gap-2 border-t pt-4">
                    <span className="type-label text-muted-foreground">
                        Also run
                    </span>
                    <ul className="flex flex-col gap-2">
                        {processes.map((process) => {
                            const run = !skipped.includes(process.name);

                            return (
                                <li
                                    key={process.name}
                                    className="flex items-center gap-3"
                                >
                                    <Checkbox
                                        checked={run}
                                        onChange={(next) =>
                                            onToggleProcess(process.name, next)
                                        }
                                        label={`Also run ${process.name}`}
                                    />
                                    <span className="type-body">
                                        {process.name}
                                    </span>
                                    <code className="min-w-0 truncate type-mono-small text-muted-foreground">
                                        {process.command.join(' ')}
                                    </code>
                                </li>
                            );
                        })}
                    </ul>
                </div>
            )}
        </article>
    );
}

function DatabaseRow({
    database,
    engines,
    apps,
    onChange,
    onRemove,
}: {
    database: DatabaseChoice;
    engines: Engine[];
    apps: AppChoice[];
    onChange: (database: DatabaseChoice) => void;
    onRemove?: () => void;
}) {
    const label =
        engines.find((engine) => engine.engine === database.engine)?.label ??
        database.engine;
    const targets = apps.filter(
        (app) => app.selected && database.connectTo.includes(app.rootDirectory),
    );

    return (
        <li className="flex items-center gap-4 px-5 py-4">
            <Logo kind={database.engine} size={32} name={label} />
            <div className="flex min-w-0 flex-1 flex-col">
                <span className="truncate type-label">{label}</span>
                <span className="truncate type-small text-muted-foreground">
                    {targets.length > 0
                        ? `Connects to ${targets.map((app) => app.name).join(', ')}`
                        : 'Not connected yet'}
                    {database.reason && ` · ${database.reason}`}
                </span>
            </div>
            {onRemove ? (
                <Button
                    variant="ghost"
                    size="icon-sm"
                    aria-label={`Remove ${label}`}
                    onClick={onRemove}
                >
                    <X aria-hidden="true" />
                </Button>
            ) : (
                <Checkbox
                    checked={database.selected}
                    onChange={(selected) => onChange({ ...database, selected })}
                    label={`Add ${label}`}
                />
            )}
        </li>
    );
}

/* ------------------------------------------------------------------ */
/* Page                                                                */
/* ------------------------------------------------------------------ */

export default function ImportRepository({
    providers,
    installUrl,
    buildServerOnline,
    engines,
}: Props) {
    const [repository, setRepository] = useState<{
        id: string;
        fullName: string;
        defaultBranch: string | null;
        serverKind: string;
    } | null>(null);
    const [branch, setBranch] = useState<string>('');
    const [branches, setBranches] = useState<string[] | null>(null);
    const [inspection, setInspection] = useState<Inspection | null>(null);
    const [inspectError, setInspectError] = useState<string | null>(null);
    const [apps, setApps] = useState<AppChoice[]>([]);
    const [databases, setDatabases] = useState<DatabaseChoice[]>([]);
    const [plans, setPlans] = useState<Record<string, Preview | null>>({});
    const [skippedProcesses, setSkippedProcesses] = useState<
        Record<string, string[]>
    >({});
    const [name, setName] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [deploying, setDeploying] = useState(false);
    const [attempt, setAttempt] = useState(0);

    // Back/reload keep the picked repository (`?repository=…&branch=…`).
    useEffect(() => {
        const params = query();
        const id = params.get('repository');

        if (!id || providers.length === 0) {
            return;
        }

        requestJson<{ repositories: Repository[] }>(
            'get',
            repositoriesRoute().url,
        )
            .then((data) => {
                const found = data.repositories.find((repo) => repo.id === id);

                if (found) {
                    pick(found, params.get('branch'));
                }
            })
            .catch(() => undefined);
        // Once on load.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const pick = (picked: Repository, pickedBranch?: string | null) => {
        const nextBranch = pickedBranch || picked.defaultBranch || 'main';
        setRepository({
            id: picked.id,
            fullName: picked.fullName,
            defaultBranch: picked.defaultBranch,
            serverKind: picked.serverKind,
        });
        setBranch(nextBranch);
        setName(slugName(picked.fullName.split('/').pop() ?? 'project'));
        remember(picked.id, nextBranch);
    };

    // Branches for the switcher.
    useEffect(() => {
        if (!repository) {
            return;
        }

        const controller = new AbortController();
        setBranches(null);
        requestJson<{ branches: { name: string }[] }>(
            'get',
            branchesRoute(repository.id).url,
            undefined,
            controller.signal,
        )
            .then((data) => setBranches(data.branches.map((b) => b.name)))
            .catch(() => undefined);

        return () => controller.abort();
    }, [repository]);

    // "We looked at your repo": apps and suggested databases.
    useEffect(() => {
        if (!repository || !branch) {
            return;
        }

        const controller = new AbortController();
        setInspection(null);
        setInspectError(null);
        setPlans({});
        setSkippedProcesses({});
        requestJson<Inspection>(
            'get',
            inspect({ query: { repository: repository.id, branch } }).url,
            undefined,
            controller.signal,
        )
            .then((data) => {
                setInspection(data);
                setApps(
                    data.apps.map((app) => ({
                        rootDirectory: app.rootDirectory,
                        name: slugName(app.name) || 'app',
                        selected: app.selected,
                    })),
                );
                setDatabases(
                    data.databases.map((db) => ({
                        key: db.engine,
                        engine: db.engine,
                        name: db.engine,
                        reason: db.reason,
                        connectTo: db.apps,
                        selected: true,
                    })),
                );
            })
            .catch((failure: unknown) => {
                if (!controller.signal.aborted) {
                    setInspectError(
                        failure instanceof RequestFailed
                            ? failure.message
                            : 'Looking at the repository failed.',
                    );
                }
            });

        return () => controller.abort();
    }, [repository, branch, attempt]);

    // Databases the plan found in the code join the `.env.example` ones,
    // merged by engine (one database per engine, connected to every app
    // that needs it).
    const mergeSuggestedDatabases = (
        root: string,
        suggested: App.Data.Projects.SuggestedDatabaseData[],
    ) => {
        if (suggested.length === 0) {
            return;
        }

        setDatabases((current) => {
            let next = current;

            for (const db of suggested) {
                const apps = db.apps.length > 0 ? db.apps : [root];
                const existing = next.find((item) => item.engine === db.engine);

                if (existing) {
                    const missing = apps.filter(
                        (app) => !existing.connectTo.includes(app),
                    );

                    if (missing.length > 0) {
                        next = next.map((item) =>
                            item === existing
                                ? {
                                      ...item,
                                      connectTo: [
                                          ...item.connectTo,
                                          ...missing,
                                      ],
                                  }
                                : item,
                        );
                    }
                } else {
                    next = [
                        ...next,
                        {
                            key: db.engine,
                            engine: db.engine,
                            name: db.engine,
                            reason: db.reason,
                            connectTo: apps,
                            selected: true,
                        },
                    ];
                }
            }

            return next;
        });
    };

    const selectedApps = apps.filter((app) => app.selected);
    const selectedDatabases = databases.filter((db) => db.selected);
    const unusedEngines = engines.filter(
        (engine) => !databases.some((db) => db.engine === engine.engine),
    );

    const summary = useMemo(() => {
        const parts = [plural(selectedApps.length, 'app')];

        if (selectedDatabases.length > 0) {
            parts.push(plural(selectedDatabases.length, 'database'));
        }

        return parts.join(' and ');
    }, [selectedApps.length, selectedDatabases.length]);

    const deploy = () => {
        if (!repository) {
            return;
        }

        setDeploying(true);
        setErrors({});
        router.post(
            store().url,
            {
                name,
                repository: repository.id,
                branch,
                apps: selectedApps.map((app) => {
                    const plan = plans[app.rootDirectory]?.plan ?? null;

                    return {
                        name: app.name,
                        rootDirectory: app.rootDirectory || null,
                        port: plan?.port ?? null,
                        framework: plan?.framework ?? null,
                        // Suggested workers the user kept (each becomes a
                        // worker service).
                        processes: (
                            plans[app.rootDirectory]?.suggestions?.processes ??
                            []
                        )
                            .filter(
                                (process) =>
                                    !(
                                        skippedProcesses[app.rootDirectory] ??
                                        []
                                    ).includes(process.name),
                            )
                            .map((process) => ({
                                name: process.name,
                                command: process.command,
                            })),
                    };
                }),
                databases: selectedDatabases.map((db) => ({
                    engine: db.engine,
                    name: db.name,
                    connectTo: db.connectTo.filter((root) =>
                        selectedApps.some((app) => app.rootDirectory === root),
                    ),
                })),
            },
            {
                onError: (failed) => setErrors(failed),
                onFinish: () => setDeploying(false),
            },
        );
    };

    const reset = () => {
        setRepository(null);
        setInspection(null);
        setApps([]);
        setDatabases([]);
        remember(null, null);
    };

    const errorFor = (index: number): string | undefined =>
        index >= 0 ? errors[`apps.${index}.name`] : undefined;

    if (!repository) {
        return (
            <>
                <Head title="Import a repository" />
                <Page size="narrow">
                    <PageHeader
                        title="Import a repository"
                        description="Pick a repository. launchroom detects the rest and deploys it."
                    />
                    <NewProjectTabs current="repository" />
                    <RepositoryPicker
                        providers={providers}
                        installUrl={installUrl}
                        onPick={(picked) => pick(picked)}
                    />
                </Page>
            </>
        );
    }

    const found = inspection
        ? [
              plural(inspection.apps.length, 'app'),
              ...(inspection.databases.length > 0
                  ? [plural(inspection.databases.length, 'database')]
                  : []),
          ].join(' and ')
        : null;

    return (
        <>
            <Head title={`Import ${repository.fullName}`} />
            <Page size="narrow">
                <PageHeader
                    title={`We looked at ${repository.fullName.split('/').pop()}`}
                    description={
                        found
                            ? `We found ${found}.`
                            : 'Looking at package.json, composer.json, Dockerfile…'
                    }
                    media={
                        <Logo
                            kind={providerLogo(repository.serverKind)}
                            size={44}
                            decorative
                        />
                    }
                    actions={
                        <Button variant="ghost" size="sm" onClick={reset}>
                            Change repository
                        </Button>
                    }
                />

                <Section
                    title="Apps"
                    description={
                        inspection && inspection.apps.length > 1
                            ? 'Pick the ones to deploy.'
                            : undefined
                    }
                    actions={
                        <Select
                            value={branch}
                            onValueChange={(value) => {
                                setBranch(value);
                                remember(repository.id, value);
                            }}
                        >
                            <SelectTrigger
                                size="sm"
                                aria-label="Branch"
                                className="h-8 gap-1.5 font-mono text-[12px]"
                            >
                                <GitBranch
                                    aria-hidden="true"
                                    className="size-3.5 text-muted-foreground"
                                />
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {(branches ?? [branch]).map((item) => (
                                    <SelectItem
                                        key={item}
                                        value={item}
                                        className="font-mono text-[12px]"
                                    >
                                        {item}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    }
                >
                    {inspectError ? (
                        <div className="flex flex-col items-start gap-3 rounded-[14px] border border-dashed p-6">
                            <p className="type-body text-status-error">
                                {inspectError}
                            </p>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setAttempt((value) => value + 1)}
                            >
                                Try again
                            </Button>
                        </div>
                    ) : !inspection ? (
                        <div
                            role="status"
                            className="flex items-center gap-4 rounded-[14px] border bg-card p-5 shadow-card"
                        >
                            <span className="flex size-11 items-center justify-center rounded-[11px] border bg-recessed">
                                <Spinner />
                            </span>
                            <div className="flex flex-col">
                                <span className="type-label">
                                    {repository.fullName}
                                </span>
                                <span className="type-small text-muted-foreground">
                                    Reading the file list of {branch}…
                                </span>
                            </div>
                        </div>
                    ) : (
                        <div className="flex flex-col gap-3">
                            {inspection.apps.map((app, index) => (
                                <AppCard
                                    key={`${branch}:${app.rootDirectory}`}
                                    repository={repository.id}
                                    branch={branch}
                                    app={app}
                                    choice={apps[index]}
                                    multiple={inspection.apps.length > 1}
                                    databases={databases}
                                    buildServerOnline={buildServerOnline}
                                    error={errorFor(
                                        selectedApps.indexOf(apps[index]),
                                    )}
                                    onChange={(next) =>
                                        setApps((current) =>
                                            current.map((item, i) =>
                                                i === index ? next : item,
                                            ),
                                        )
                                    }
                                    onPlan={(root, preview) => {
                                        setPlans((current) =>
                                            current[root] === preview
                                                ? current
                                                : {
                                                      ...current,
                                                      [root]: preview,
                                                  },
                                        );
                                        mergeSuggestedDatabases(
                                            root,
                                            preview?.suggestions?.databases ??
                                                [],
                                        );
                                    }}
                                    skipped={
                                        skippedProcesses[app.rootDirectory] ??
                                        []
                                    }
                                    onToggleProcess={(process, run) =>
                                        setSkippedProcesses((current) => {
                                            const list = (
                                                current[app.rootDirectory] ?? []
                                            ).filter(
                                                (name) => name !== process,
                                            );

                                            return {
                                                ...current,
                                                [app.rootDirectory]: run
                                                    ? list
                                                    : [...list, process],
                                            };
                                        })
                                    }
                                />
                            ))}
                            {!buildServerOnline && (
                                <p className="type-small text-muted-foreground">
                                    No build server is online, so detection
                                    happens with the first build.{' '}
                                    <Link
                                        href={serversIndex()}
                                        className="text-foreground underline underline-offset-4"
                                    >
                                        Servers
                                    </Link>
                                </p>
                            )}
                        </div>
                    )}
                </Section>

                {inspection && (
                    <Section
                        title="Databases"
                        description={
                            databases.some((db) => db.reason)
                                ? 'Suggested from .env.example and connected for you.'
                                : undefined
                        }
                        actions={
                            unusedEngines.length > 0 && (
                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <Button variant="outline" size="sm">
                                            <Plus aria-hidden="true" />
                                            Add database
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end">
                                        {unusedEngines.map((engine) => (
                                            <DropdownMenuItem
                                                key={engine.engine}
                                                onSelect={() =>
                                                    setDatabases((current) => [
                                                        ...current,
                                                        {
                                                            key: `${engine.engine}-${Date.now()}`,
                                                            engine: engine.engine,
                                                            name: engine.engine,
                                                            reason: null,
                                                            connectTo:
                                                                selectedApps.map(
                                                                    (app) =>
                                                                        app.rootDirectory,
                                                                ),
                                                            selected: true,
                                                        },
                                                    ])
                                                }
                                            >
                                                <Logo
                                                    kind={engine.engine}
                                                    size={20}
                                                    decorative
                                                />
                                                {engine.label}
                                            </DropdownMenuItem>
                                        ))}
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            )
                        }
                    >
                        {databases.length === 0 ? (
                            <p className="rounded-[14px] border border-dashed px-5 py-6 type-body text-muted-foreground">
                                Nothing in the repository asks for a database.
                                Add one if your app needs it.
                            </p>
                        ) : (
                            <ul className="divide-y overflow-hidden rounded-[14px] border bg-card shadow-card">
                                {databases.map((database, index) => (
                                    <DatabaseRow
                                        key={database.key}
                                        database={database}
                                        engines={engines}
                                        apps={apps}
                                        onChange={(next) =>
                                            setDatabases((current) =>
                                                current.map((item, i) =>
                                                    i === index ? next : item,
                                                ),
                                            )
                                        }
                                        onRemove={
                                            database.reason === null
                                                ? () =>
                                                      setDatabases((current) =>
                                                          current.filter(
                                                              (_, i) =>
                                                                  i !== index,
                                                          ),
                                                      )
                                                : undefined
                                        }
                                    />
                                ))}
                            </ul>
                        )}
                    </Section>
                )}

                {inspection && (
                    <Section title="Name and deploy">
                        <div className="flex flex-col gap-5 rounded-[14px] border bg-card p-5 shadow-card">
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="project-name">
                                    Project name
                                </Label>
                                <Input
                                    id="project-name"
                                    value={name}
                                    maxLength={100}
                                    onChange={(event) =>
                                        setName(event.target.value)
                                    }
                                    aria-invalid={
                                        errors.name ? true : undefined
                                    }
                                />
                                {errors.name && (
                                    <p className="type-small text-status-error">
                                        {errors.name}
                                    </p>
                                )}
                            </div>
                            {(errors.repository ||
                                errors.apps ||
                                errors.branch) && (
                                <p className="type-body text-status-error">
                                    {errors.repository ??
                                        errors.apps ??
                                        errors.branch}
                                </p>
                            )}
                            <div className="flex flex-col-reverse gap-3 border-t pt-4 sm:flex-row sm:items-center sm:justify-between">
                                <p className="type-small text-muted-foreground">
                                    {summary} deploy to production from{' '}
                                    <span className="type-mono-small text-foreground">
                                        {branch}
                                    </span>
                                    .
                                </p>
                                <Button
                                    onClick={deploy}
                                    disabled={
                                        deploying ||
                                        selectedApps.length === 0 ||
                                        name.trim() === ''
                                    }
                                >
                                    {deploying && <Spinner />}
                                    Deploy
                                </Button>
                            </div>
                        </div>
                    </Section>
                )}
            </Page>
        </>
    );
}

ImportRepository.layout = {
    breadcrumbs: [
        { title: 'Projects', href: projectsIndex() },
        { title: 'Import a repository', href: importMethod() },
    ],
};
