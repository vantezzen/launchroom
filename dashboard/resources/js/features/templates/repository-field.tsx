import { Check, ChevronRight, GitBranch, Lock, Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Logo } from '@/components/logo';
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
import { requestJson, RequestFailed } from '@/lib/http';
import { addUrl } from '@/routes/projects/import';
import {
    branches as branchesRoute,
    index as repositoriesRoute,
} from '@/routes/git/repositories';

type Repository = App.Data.Git.GitRepositoryData;

export type RepositoryChoice = { repository: Repository; branch: string };

/**
 * A compact repository picker for stacks and Compose files in a
 * repository: search the team's repositories, pick one, then its branch.
 * Stacks with a public starter offer it first (PRJ-3), so trying the stack
 * needs no repository of your own.
 */
export function RepositoryField({
    value,
    onChange,
    error,
    starter = null,
}: {
    value: RepositoryChoice | null;
    onChange: (value: RepositoryChoice | null) => void;
    error?: string;
    starter?: { url: string; framework: string; logo: string } | null;
}) {
    const [search, setSearch] = useState('');
    const [repositories, setRepositories] = useState<Repository[] | null>(null);
    const [failed, setFailed] = useState<string | null>(null);
    const [branches, setBranches] = useState<string[] | null>(null);
    const [starterId, setStarterId] = useState<string | null>(null);

    useEffect(() => {
        if (value) {
            return;
        }

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
                    setFailed(null);
                })
                .catch((cause: unknown) => {
                    if (!controller.signal.aborted) {
                        setFailed(
                            cause instanceof Error
                                ? cause.message
                                : 'Repositories didn’t load.',
                        );
                    }
                });
        }, 200);

        return () => {
            controller.abort();
            window.clearTimeout(timer);
        };
    }, [search, value]);

    useEffect(() => {
        if (!value) {
            setBranches(null);

            return;
        }

        const controller = new AbortController();
        requestJson<{ branches: { name: string }[] }>(
            'get',
            branchesRoute(value.repository.id).url,
            undefined,
            controller.signal,
        )
            .then((data) =>
                setBranches(data.branches.map((branch) => branch.name)),
            )
            .catch(() => undefined);

        return () => controller.abort();
    }, [value]);

    if (value) {
        const isStarter = value.repository.id === starterId;

        return (
            <div className="flex flex-col gap-1.5">
                <div className="flex items-center gap-3 rounded-xl border bg-card p-3">
                    <Logo
                        kind={
                            value.repository.provider === 'github'
                                ? 'github'
                                : value.repository.provider
                        }
                        size={32}
                        decorative
                    />
                    <span className="min-w-0 flex-1 truncate type-mono">
                        {value.repository.fullName}
                    </span>
                    <Select
                        value={value.branch}
                        onValueChange={(branch) =>
                            onChange({ ...value, branch })
                        }
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
                            {(branches ?? [value.branch]).map((branch) => (
                                <SelectItem
                                    key={branch}
                                    value={branch}
                                    className="font-mono text-[12px]"
                                >
                                    {branch}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => onChange(null)}
                    >
                        Change
                    </Button>
                </div>
                {isStarter && (
                    <p className="type-small text-muted-foreground">
                        Deploys the official starter as it is. To work on the
                        code, fork it and pick your fork here instead.
                    </p>
                )}
                {error && (
                    <p className="type-small text-status-error">{error}</p>
                )}
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-2">
            {starter && (
                <StarterTile
                    starter={starter}
                    onPick={(repository) => {
                        setStarterId(repository.id);
                        onChange({
                            repository,
                            branch: repository.defaultBranch ?? 'main',
                        });
                    }}
                />
            )}
            <div className="relative">
                <Search
                    aria-hidden="true"
                    className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    placeholder="Search repositories"
                    aria-label="Search repositories"
                    className="pl-9"
                />
            </div>
            {failed && <p className="type-small text-status-error">{failed}</p>}
            {repositories === null && !failed && (
                <p className="flex items-center gap-2 type-small text-muted-foreground">
                    <Spinner /> Loading repositories…
                </p>
            )}
            {repositories !== null && repositories.length === 0 && (
                <p className="type-small text-muted-foreground">
                    No repositories yet. Connect a git provider in Settings →
                    Git.
                </p>
            )}
            {repositories !== null && repositories.length > 0 && (
                <ul className="divide-y overflow-hidden rounded-xl border bg-card">
                    {repositories.slice(0, 6).map((repository) => (
                        <li key={repository.id}>
                            <button
                                type="button"
                                onClick={() =>
                                    onChange({
                                        repository,
                                        branch:
                                            repository.defaultBranch ?? 'main',
                                    })
                                }
                                className="flex w-full items-center gap-3 px-3 py-2.5 text-left transition-colors duration-fast hover:bg-hover focus-visible:bg-hover focus-visible:outline-none"
                            >
                                <Logo
                                    kind={
                                        repository.provider === 'github'
                                            ? 'github'
                                            : repository.provider
                                    }
                                    size={20}
                                    decorative
                                />
                                <span className="min-w-0 flex-1 truncate type-mono-small">
                                    {repository.fullName}
                                </span>
                                {repository.private && (
                                    <Lock
                                        aria-label="Private"
                                        className="size-3.5 text-muted-foreground"
                                    />
                                )}
                                <Check
                                    aria-hidden="true"
                                    className="size-3.5 text-transparent"
                                />
                            </button>
                        </li>
                    ))}
                </ul>
            )}
            {error && <p className="type-small text-status-error">{error}</p>}
        </div>
    );
}

/**
 * "Start from the Laravel starter": adds the framework's public starter
 * like a pasted URL (the import's public-repository endpoint) and picks it.
 */
function StarterTile({
    starter,
    onPick,
}: {
    starter: { url: string; framework: string; logo: string };
    onPick: (repository: Repository) => void;
}) {
    const [adding, setAdding] = useState(false);
    const [failed, setFailed] = useState<string | null>(null);

    const add = () => {
        setAdding(true);
        setFailed(null);
        requestJson<Repository>('post', addUrl().url, { url: starter.url })
            .then(onPick)
            .catch((failure: unknown) =>
                setFailed(
                    failure instanceof RequestFailed
                        ? failure.message
                        : 'The starter couldn’t be added. Try again.',
                ),
            )
            .finally(() => setAdding(false));
    };

    return (
        <div className="flex flex-col gap-1.5">
            <button
                type="button"
                onClick={add}
                disabled={adding}
                className="flex w-full items-center gap-3 rounded-xl border bg-card p-3 text-left shadow-card transition-colors duration-fast hover:border-border-strong focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-70"
            >
                <Logo kind={starter.logo} size={32} decorative />
                <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                    <span className="type-label">
                        Start from the {starter.framework} starter
                    </span>
                    <span className="truncate type-small text-muted-foreground">
                        No repository of your own needed. You can switch to
                        yours later.
                    </span>
                </span>
                {adding ? (
                    <Spinner />
                ) : (
                    <ChevronRight
                        aria-hidden="true"
                        className="size-4 shrink-0 text-muted-foreground"
                    />
                )}
            </button>
            {failed && <p className="type-small text-status-error">{failed}</p>}
            <p className="pt-1 type-small text-muted-foreground">
                Or pick one of your repositories:
            </p>
        </div>
    );
}
