import { Check, GitBranch, Lock, Search } from 'lucide-react';
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
import { requestJson } from '@/features/projects/http';
import {
    branches as branchesRoute,
    index as repositoriesRoute,
} from '@/routes/git/repositories';

type Repository = App.Data.Git.GitRepositoryData;

export type RepositoryChoice = { repository: Repository; branch: string };

/**
 * A compact repository picker for stacks and Compose files in a
 * repository: search the team's repositories, pick one, then its branch.
 */
export function RepositoryField({
    value,
    onChange,
    error,
}: {
    value: RepositoryChoice | null;
    onChange: (value: RepositoryChoice | null) => void;
    error?: string;
}) {
    const [search, setSearch] = useState('');
    const [repositories, setRepositories] = useState<Repository[] | null>(null);
    const [failed, setFailed] = useState<string | null>(null);
    const [branches, setBranches] = useState<string[] | null>(null);

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
                {error && (
                    <p className="type-small text-status-error">{error}</p>
                )}
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-2">
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
