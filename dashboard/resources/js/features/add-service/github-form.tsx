import { Link } from '@inertiajs/react';
import { Lock, Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { requestJson, RequestFailed } from '@/features/projects/http';
import { DetectedPlan } from '@/features/service-settings/detected-plan';
import { cn } from '@/lib/utils';
import { planPreview } from '@/routes/projects';
import { edit as gitSettings } from '@/routes/git';
import {
    branches as branchesRoute,
    index as repositoriesRoute,
} from '@/routes/git/repositories';
import { Field, suggestName } from './fields';
import type { SubmitService } from './types';

type Repository = App.Data.Git.GitRepositoryData;
type Branch = App.Data.Git.GitBranchData;

const POLL_MS = 2000;

function RepositoryPicker({
    onPick,
}: {
    onPick: (repository: Repository) => void;
}) {
    const [search, setSearch] = useState('');
    const [repositories, setRepositories] = useState<Repository[] | null>(null);
    const [error, setError] = useState<RequestFailed | null>(null);

    useEffect(() => {
        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            requestJson<{ repositories: Repository[] }>(
                'get',
                repositoriesRoute({ query: search ? { search } : {} }).url,
                undefined,
                controller.signal,
            )
                .then((data) => {
                    setRepositories(data.repositories);
                    setError(null);
                })
                .catch((failure: unknown) => {
                    if (
                        !controller.signal.aborted &&
                        failure instanceof RequestFailed
                    ) {
                        setError(failure);
                    }
                });
        }, 200);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [search]);

    if (error?.status === 403) {
        return (
            <p className="type-body text-muted-foreground">
                Picking repositories needs the developer role or higher.
            </p>
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
                    aria-label="Search repositories"
                    placeholder="Search repositories"
                    value={search}
                    autoFocus
                    onChange={(event) => setSearch(event.target.value)}
                    className="pl-9"
                />
            </div>

            {error && (
                <p className="type-body text-status-error">{error.message}</p>
            )}

            {repositories === null && !error && (
                <div className="flex items-center gap-2 type-body text-muted-foreground">
                    <Spinner /> Loading repositories
                </div>
            )}

            {repositories?.length === 0 && (
                <div className="flex flex-col items-start gap-3 py-4">
                    <p className="max-w-[60ch] type-body text-muted-foreground">
                        {search
                            ? 'No repository matches. The GitHub App only sees the repositories you granted it.'
                            : 'No repositories yet. Connect GitHub and grant the app access to the repositories you deploy.'}
                    </p>
                    <Button asChild variant="outline" size="sm">
                        <Link href={gitSettings()}>Git settings</Link>
                    </Button>
                </div>
            )}

            {repositories && repositories.length > 0 && (
                <ul
                    aria-label="Repositories"
                    className="flex max-h-[50vh] flex-col divide-y overflow-y-auto rounded-md border"
                >
                    {repositories.map((repository) => (
                        <li key={repository.id}>
                            <button
                                type="button"
                                disabled={repository.archived}
                                onClick={() => onPick(repository)}
                                className="flex w-full items-center gap-3 px-3 py-2.5 text-left hover:bg-accent focus-visible:bg-accent focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                <span className="min-w-0 flex-1 truncate type-mono">
                                    {repository.fullName}
                                </span>
                                {repository.private && (
                                    <Lock
                                        aria-label="Private"
                                        className="size-3.5 text-muted-foreground"
                                    />
                                )}
                                {repository.archived && (
                                    <span className="type-small text-muted-foreground">
                                        Archived
                                    </span>
                                )}
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

/**
 * GitHub repository → branch + root directory → "We detected…" → add.
 */
export function GitHubForm({
    project,
    submit,
    processing,
    errors,
}: {
    project: string;
    submit: SubmitService;
    processing: boolean;
    errors: Record<string, string>;
}) {
    const [repository, setRepository] = useState<Repository | null>(null);
    const [branches, setBranches] = useState<Branch[] | null>(null);
    const [branchError, setBranchError] = useState<string | null>(null);
    const [branch, setBranch] = useState('');
    const [rootDirectory, setRootDirectory] = useState('');
    const [name, setName] = useState('');
    const [preview, setPreview] =
        useState<App.Data.Canvas.PlanPreviewData | null>(null);
    const [previewError, setPreviewError] = useState<string | null>(null);
    const [previewing, setPreviewing] = useState(false);

    useEffect(() => {
        if (!repository) {
            return;
        }

        const controller = new AbortController();
        setBranches(null);
        setBranchError(null);
        requestJson<{ branches: Branch[] }>(
            'get',
            branchesRoute(repository.id).url,
            undefined,
            controller.signal,
        )
            .then((data) => setBranches(data.branches))
            .catch((failure: unknown) => {
                if (!controller.signal.aborted) {
                    setBranchError(
                        failure instanceof Error
                            ? failure.message
                            : 'Loading branches failed.',
                    );
                }
            });

        return () => controller.abort();
    }, [repository]);

    // Poll while a plan task runs.
    useEffect(() => {
        if (preview?.status !== 'pending' || !repository) {
            return;
        }

        const timer = window.setTimeout(() => void runPreview(), POLL_MS);

        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [preview]);

    const runPreview = async () => {
        if (!repository) {
            return;
        }

        setPreviewing(true);
        setPreviewError(null);

        try {
            setPreview(
                await requestJson<App.Data.Canvas.PlanPreviewData>(
                    'get',
                    planPreview(project, {
                        query: {
                            repository: repository.id,
                            branch,
                            rootDirectory,
                        },
                    }).url,
                ),
            );
        } catch (failure) {
            setPreviewError(
                failure instanceof Error
                    ? failure.message
                    : 'Detection failed.',
            );
        } finally {
            setPreviewing(false);
        }
    };

    if (!repository) {
        return (
            <RepositoryPicker
                onPick={(picked) => {
                    setRepository(picked);
                    setBranch(picked.defaultBranch ?? 'main');
                    setName(suggestName(picked.fullName));
                    setPreview(null);
                }}
            />
        );
    }

    const confirm = () =>
        submit({
            kind: 'app',
            name,
            repository: repository.id,
            branch,
            rootDirectory: rootDirectory || null,
            port: preview?.plan?.port ?? null,
        });

    const reviewing = preview !== null && preview.status !== 'pending';

    return (
        <div className="flex flex-col gap-6">
            <div className="flex items-center justify-between gap-3 rounded-md border px-3 py-2">
                <span className="min-w-0 truncate type-mono">
                    {repository.fullName}
                </span>
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => setRepository(null)}
                >
                    Change
                </Button>
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                <Field
                    id="add-git-branch"
                    label="Branch"
                    error={branchError ?? errors.branch}
                >
                    {branches === null && !branchError ? (
                        <div className="flex h-9 items-center gap-2 type-body text-muted-foreground">
                            <Spinner /> Loading branches
                        </div>
                    ) : (
                        <Select
                            value={branch}
                            onValueChange={(value) => {
                                setBranch(value);
                                setPreview(null);
                            }}
                        >
                            <SelectTrigger
                                id="add-git-branch"
                                className="w-full font-mono text-[13px]"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {(
                                    branches ?? [
                                        {
                                            name: branch,
                                            sha: '',
                                            protected: false,
                                            isDefault: true,
                                        },
                                    ]
                                ).map((item) => (
                                    <SelectItem
                                        key={item.name}
                                        value={item.name}
                                        className="font-mono text-[13px]"
                                    >
                                        {item.name}
                                        {item.isDefault && (
                                            <span className="ml-2 font-sans text-muted-foreground">
                                                default
                                            </span>
                                        )}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    )}
                </Field>
                <Field
                    id="add-git-root"
                    label="Root directory"
                    helper="For monorepos, e.g. apps/web."
                    error={errors.rootDirectory}
                >
                    <Input
                        id="add-git-root"
                        value={rootDirectory}
                        placeholder="/"
                        className="font-mono text-[13px]"
                        onChange={(event) => {
                            setRootDirectory(
                                event.target.value.replace(/^\/+|\/+$/g, ''),
                            );
                            setPreview(null);
                        }}
                    />
                </Field>
            </div>

            <Field id="add-git-name" label="Service name" error={errors.name}>
                <Input
                    id="add-git-name"
                    value={name}
                    maxLength={63}
                    onChange={(event) => setName(event.target.value)}
                />
            </Field>

            {reviewing && (
                <section
                    aria-label="Detected build"
                    className="flex flex-col gap-2"
                >
                    {preview.status === 'failed' && (
                        <p className="max-w-[65ch] type-body text-status-error">
                            {preview.message ??
                                'Detection failed. You can still add the service; the first build detects again.'}
                        </p>
                    )}
                    <DetectedPlan
                        plan={preview.plan}
                        showPlanJson={false}
                        emptyMessage={
                            preview.status === 'failed'
                                ? 'You can still add the service: the first build detects again, and Settings → Build shows what it found.'
                                : (preview.message ?? undefined)
                        }
                    />
                </section>
            )}
            {preview?.status === 'pending' && (
                <div className="flex flex-col gap-1" role="status">
                    <p className="flex items-center gap-2 type-body">
                        <Spinner />
                        {preview.message ?? 'Inspecting the repository'}
                    </p>
                    <p className="type-small text-muted-foreground">
                        launchroom clones the branch head and detects the
                        framework, commands and port. This usually takes a few
                        seconds.
                    </p>
                </div>
            )}
            {previewError && (
                <p className="type-body text-status-error">{previewError}</p>
            )}
            {errors.repository && (
                <p className="type-body text-status-error">
                    {errors.repository}
                </p>
            )}

            <div className="flex justify-end gap-2">
                {preview?.status === 'pending' && (
                    <Button
                        variant="ghost"
                        onClick={confirm}
                        disabled={processing || name === ''}
                    >
                        Add without waiting
                    </Button>
                )}
                {reviewing ? (
                    <Button
                        onClick={confirm}
                        disabled={processing || name === ''}
                    >
                        Add service
                    </Button>
                ) : (
                    <Button
                        onClick={() => void runPreview()}
                        disabled={
                            previewing ||
                            branch === '' ||
                            name === '' ||
                            preview?.status === 'pending'
                        }
                        className={cn(previewing && 'cursor-progress')}
                    >
                        {previewing && <Spinner />}
                        Continue
                    </Button>
                )}
            </div>
        </div>
    );
}
