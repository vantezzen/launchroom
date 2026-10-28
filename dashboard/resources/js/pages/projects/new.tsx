import { Head, Link, router } from '@inertiajs/react';
import { Check, GitBranch, Plus, X } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { Logo } from '@/components/logo';
import { MetaLine } from '@/components/patterns/meta-line';
import { Page, PageHeader } from '@/components/patterns/page';
import { Section } from '@/components/patterns/section';
import { Status, StatusIcon } from '@/components/status';
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
import {
    frameworkLogo,
    planParts,
} from '@/features/add-service/framework-logo';
import { repositoryLogo } from '@/features/git/providers';
import { NewProjectStart } from '@/features/new-project/new-project-start';
import { plural } from '@/features/canvas/format';
import { requestJson, RequestFailed } from '@/features/projects/http';
import { usePlanPreview } from '@/features/add-service/use-plan-preview';
import { cn } from '@/lib/utils';
import {
    branches as branchesRoute,
    index as repositoriesRoute,
} from '@/routes/git/repositories';
import { edit as gitSettings } from '@/routes/git';
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
    /** Owners and admins connect git providers (docs/19 §4). */
    canConnectGit: boolean;
    buildServerOnline: boolean;
    engines: Engine[];
    templates: App.Data.Templates.TemplateSummaryData[];
    categories: Record<string, string>;
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
/* Step 2: what we found                                               */
/* ------------------------------------------------------------------ */

function DetectionFailed({
    message,
    onRetry,
}: {
    message: string;
    onRetry: () => void;
}) {
    return (
        <span className="flex flex-col items-start gap-2" role="alert">
            <span className="flex items-start gap-1.5 text-status-error">
                <span className="mt-0.5 shrink-0">
                    <StatusIcon state="failed" />
                </span>
                <span className="max-w-[65ch] break-words">{message}</span>
            </span>
            <Button variant="outline" size="sm" onClick={onRetry}>
                Retry
            </Button>
        </span>
    );
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
    const {
        preview,
        error: previewError,
        retry: retryPreview,
    } = usePlanPreview(
        (retry) =>
            planPreview({
                query: {
                    repository,
                    branch,
                    rootDirectory: app.rootDirectory,
                    ...(retry ? { retry: '1' } : {}),
                },
            }).url,
        choice.selected
            ? `${repository}\0${branch}\0${app.rootDirectory}`
            : null,
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
        detection = (
            <DetectionFailed message={previewError} onRetry={retryPreview} />
        );
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
                {planParts(plan).length > 0 ? (
                    <MetaLine>{planParts(plan)}</MetaLine>
                ) : (
                    'Detected'
                )}
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
            <DetectionFailed
                message={
                    preview.message ??
                    'Couldn’t detect how to build it. The first build will try again.'
                }
                onRetry={retryPreview}
            />
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
                                            , {covered} from databases
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
                <MetaLine className="type-small text-muted-foreground">
                    {targets.length > 0
                        ? `Connects to ${targets.map((app) => app.name).join(', ')}`
                        : 'Not connected yet'}
                    {database.reason}
                </MetaLine>
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
    githubConnected,
    providers,
    installUrl,
    canConnectGit,
    buildServerOnline,
    engines,
    templates,
    categories,
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

        if (!id) {
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

    const deploy = (deployNow: boolean) => {
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
                deploy: deployNow,
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
                <Head title="New project" />
                <NewProjectStart
                    providers={providers}
                    installUrl={installUrl}
                    githubConnected={githubConnected}
                    canConnectGit={canConnectGit}
                    templates={templates}
                    categories={categories}
                    onPick={(picked, pickedBranch) =>
                        pick(picked, pickedBranch)
                    }
                />
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
                            kind={repositoryLogo(
                                repository.serverKind,
                                repository.fullName,
                            )}
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
                                ? 'Suggested from .env.example and connected to your apps.'
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
                            <div className="flex flex-col gap-3 border-t pt-4 sm:flex-row sm:items-center sm:justify-between">
                                <div className="flex flex-col gap-1 type-small text-muted-foreground">
                                    <p>
                                        {summary} in production, tracking{' '}
                                        <span className="type-mono-small text-foreground">
                                            {branch}
                                        </span>
                                        .
                                    </p>
                                    {repository.serverKind === 'git' && (
                                        <p>
                                            New commits are picked up within a
                                            minute.{' '}
                                            {!githubConnected &&
                                                canConnectGit &&
                                                repository.fullName.startsWith(
                                                    'github.com/',
                                                ) && (
                                                    <Link
                                                        href={gitSettings()}
                                                        className="text-foreground underline-offset-4 hover:underline"
                                                    >
                                                        Connect GitHub for
                                                        instant deploys and
                                                        previews
                                                    </Link>
                                                )}
                                        </p>
                                    )}
                                </div>
                                <div className="flex shrink-0 flex-wrap gap-2 sm:justify-end">
                                    <Button
                                        variant="outline"
                                        onClick={() => deploy(false)}
                                        disabled={
                                            deploying ||
                                            selectedApps.length === 0 ||
                                            name.trim() === ''
                                        }
                                    >
                                        Create without deploying
                                    </Button>
                                    <Button
                                        onClick={() => deploy(true)}
                                        disabled={
                                            deploying ||
                                            selectedApps.length === 0 ||
                                            name.trim() === ''
                                        }
                                    >
                                        {deploying && <Spinner />}
                                        Create and deploy
                                    </Button>
                                </div>
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
        { title: 'New project', href: importMethod() },
    ],
};
