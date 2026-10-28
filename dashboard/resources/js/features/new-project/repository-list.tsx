import { Link } from '@inertiajs/react';
import {
    ArrowUp,
    ArrowUpRight,
    Check,
    ChevronDown,
    Lock,
    Search,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { Logo } from '@/components/logo';
import { MetaLine } from '@/components/patterns/meta-line';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { compactAge } from '@/features/canvas/format';
import { providerLabel, repositoryLogo } from '@/features/git/providers';
import { requestJson } from '@/features/projects/http';
import { cn } from '@/lib/utils';
import { edit as gitSettings } from '@/routes/git';
import { index as repositoriesRoute } from '@/routes/git/repositories';

type Repository = App.Data.Git.GitRepositoryData;

const PROVIDER_FILTER: Record<string, string> = {
    github: 'GitHub',
    gitlab: 'GitLab',
    gitea: 'Gitea and Forgejo',
    git: 'Added by URL',
};

const ROW = 'flex items-center gap-3 px-4 py-3';

/**
 * "Your repositories" on New project (docs/08 §13): every repository the
 * team's git connections reach, recent pushes first, with a provider filter
 * and search. Without any connection it explains what connecting gives you
 * and points at the URL field.
 */
export function RepositoryList({
    providers,
    installUrl,
    canConnectGit,
    githubConnected,
    onPick,
}: {
    providers: string[];
    installUrl: string | null;
    canConnectGit: boolean;
    githubConnected: boolean;
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
        return <ConnectGit canConnectGit={canConnectGit} />;
    }

    return (
        <div className="flex flex-col overflow-hidden rounded-[14px] border bg-card shadow-card">
            <div className="flex items-center gap-2 border-b p-3">
                {providers.length > 1 && (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                variant="outline"
                                className="h-9 shrink-0 gap-2 px-2.5"
                                aria-label="Show repositories from"
                            >
                                <Logo
                                    kind={
                                        provider === null
                                            ? 'git'
                                            : provider === 'gitea'
                                              ? 'forgejo'
                                              : provider
                                    }
                                    size={20}
                                    decorative
                                    className="border-0 bg-transparent"
                                />
                                <span className="hidden type-label sm:inline">
                                    {provider === null
                                        ? 'All'
                                        : PROVIDER_FILTER[provider]}
                                </span>
                                <ChevronDown
                                    aria-hidden="true"
                                    className="size-3.5 text-muted-foreground"
                                />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="start" className="w-52">
                            <DropdownMenuRadioGroup
                                value={provider ?? 'all'}
                                onValueChange={(value) => {
                                    setProvider(value === 'all' ? null : value);
                                    setRepositories(null);
                                }}
                            >
                                <DropdownMenuRadioItem value="all">
                                    All repositories
                                </DropdownMenuRadioItem>
                                {providers.map((item) => (
                                    <DropdownMenuRadioItem
                                        key={item}
                                        value={item}
                                    >
                                        {PROVIDER_FILTER[item] ??
                                            providerLabel(item)}
                                    </DropdownMenuRadioItem>
                                ))}
                            </DropdownMenuRadioGroup>
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}
                <div className="relative min-w-0 flex-1">
                    <Search
                        aria-hidden="true"
                        className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <input
                        type="search"
                        aria-label="Search repositories"
                        placeholder="Search repositories"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        className="h-9 w-full rounded-md border border-border-strong bg-background pr-3 pl-9 type-body outline-none placeholder:text-muted-foreground focus-visible:border-foreground/40 focus-visible:ring-2 focus-visible:ring-ring/40"
                    />
                </div>
            </div>

            <div className="relative min-h-[120px] flex-1">
                {error && (
                    <p className="px-4 py-6 type-body text-status-error">
                        {error}
                    </p>
                )}
                {repositories === null && !error && (
                    <ul
                        aria-label="Loading repositories"
                        className="flex animate-skeleton-in flex-col divide-y"
                    >
                        {[0, 1, 2, 3, 4].map((row) => (
                            <li key={row} className={ROW}>
                                <span className="size-8 rounded-[9px] bg-hover" />
                                <span className="flex flex-1 flex-col gap-1.5">
                                    <span className="h-3 w-40 rounded bg-hover" />
                                    <span className="h-2.5 w-24 rounded bg-hover" />
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
                {repositories?.length === 0 && (
                    <p className="px-6 py-14 text-center type-body text-muted-foreground">
                        {search
                            ? `No repository matches “${search}”.`
                            : provider === 'git'
                              ? 'Repositories you add by URL show up here.'
                              : 'No repositories yet. Give launchroom access to the ones you deploy.'}
                    </p>
                )}
                {repositories && repositories.length > 0 && (
                    <ul
                        aria-label="Repositories"
                        className="flex max-h-[452px] flex-col divide-y overflow-y-auto"
                    >
                        {repositories.map((repository) => (
                            <RepositoryRow
                                key={repository.id}
                                repository={repository}
                                showConnection={providers.length > 1}
                                onPick={onPick}
                            />
                        ))}
                    </ul>
                )}
            </div>

            {(installUrl || (!githubConnected && canConnectGit)) && (
                <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-t bg-recessed px-4 py-3 type-small text-muted-foreground">
                    {installUrl ? (
                        <a
                            href={installUrl}
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex items-center gap-1 hover:text-foreground"
                        >
                            Don’t see it? Grant access on GitHub
                            <ArrowUpRight
                                aria-hidden="true"
                                className="size-3.5"
                            />
                        </a>
                    ) : (
                        <span>Connect GitHub to deploy on every push.</span>
                    )}
                    {canConnectGit && (
                        <Link
                            href={gitSettings()}
                            className="text-foreground underline-offset-4 hover:underline"
                        >
                            {githubConnected
                                ? 'Connect another provider'
                                : 'Connect GitHub'}
                        </Link>
                    )}
                </div>
            )}
        </div>
    );
}

function RepositoryRow({
    repository,
    showConnection,
    onPick,
}: {
    repository: Repository;
    showConnection: boolean;
    onPick: (repository: Repository) => void;
}) {
    const added = repository.provider === 'git';
    // Added by URL: `github.com/owner/app` reads as `owner/app` with its host's mark.
    const shown = added
        ? repository.fullName.split('/').slice(1).join('/') ||
          repository.fullName
        : repository.fullName;
    const cut = shown.lastIndexOf('/');
    const owner = cut > 0 ? shown.slice(0, cut + 1) : '';
    const name = shown.slice(cut + 1);
    const meta = repository.archived
        ? 'Archived'
        : repository.pushedAt
          ? `Pushed ${compactAge(repository.pushedAt)} ago`
          : (repository.defaultBranch ?? 'main');

    return (
        <li>
            <button
                type="button"
                disabled={repository.archived}
                onClick={() => onPick(repository)}
                className={cn(
                    ROW,
                    'group w-full text-left transition-colors duration-fast hover:bg-hover focus-visible:bg-hover focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50',
                )}
            >
                <Logo
                    kind={repositoryLogo(
                        repository.serverKind,
                        repository.fullName,
                    )}
                    size={32}
                    name={providerLabel(repository.provider)}
                />
                <span className="flex min-w-0 flex-1 flex-col">
                    <span className="flex min-w-0 items-center gap-1.5">
                        <span className="truncate type-label">
                            <span className="font-normal text-muted-foreground">
                                {owner}
                            </span>
                            {name}
                        </span>
                        {repository.private && (
                            <Lock
                                aria-label="Private"
                                className="size-3 shrink-0 text-muted-foreground"
                            />
                        )}
                    </span>
                    <MetaLine className="min-w-0 type-small text-muted-foreground">
                        <span className="truncate">{meta}</span>
                        {showConnection &&
                            repository.connectionName &&
                            (added
                                ? repository.fullName.split('/')[0]
                                : repository.connectionName)}
                    </MetaLine>
                </span>
                <span className="hidden h-8 shrink-0 items-center rounded-md border border-border-strong bg-card px-3 type-label transition-colors duration-fast group-hover:border-foreground/30 group-hover:bg-background sm:inline-flex">
                    Import
                </span>
            </button>
        </li>
    );
}

function ConnectGit({ canConnectGit }: { canConnectGit: boolean }) {
    return (
        <div className="flex h-full flex-col overflow-hidden rounded-[14px] border bg-card shadow-card">
            <div
                aria-hidden="true"
                className="flex h-[132px] items-center justify-center gap-3 border-b bg-recessed"
            >
                <Logo
                    kind="gitlab"
                    size={32}
                    decorative
                    className="opacity-60"
                />
                <Logo
                    kind="github"
                    size={44}
                    decorative
                    className="shadow-card"
                />
                <Logo
                    kind="forgejo"
                    size={32}
                    decorative
                    className="opacity-60"
                />
            </div>
            <div className="flex flex-1 flex-col gap-5 p-5">
                <div className="flex flex-col gap-1">
                    <h3 className="type-heading">
                        Connect GitHub to deploy on every push
                    </h3>
                    <p className="type-body text-muted-foreground">
                        Your repositories show up here, private ones included.
                    </p>
                </div>
                <ul className="flex flex-col gap-2.5 type-body">
                    {[
                        'Each push deploys right away',
                        'Every pull request gets its own preview',
                        'Commits show their deploy status on GitHub',
                    ].map((line) => (
                        <li key={line} className="flex items-start gap-2.5">
                            <Check
                                aria-hidden="true"
                                className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                            />
                            {line}
                        </li>
                    ))}
                </ul>
                <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                    {canConnectGit ? (
                        <>
                            <Button variant="outline" asChild>
                                <Link href={gitSettings()}>
                                    <Logo
                                        kind="github"
                                        size={20}
                                        decorative
                                        className="border-0 bg-transparent"
                                    />
                                    Connect GitHub
                                </Link>
                            </Button>
                            <Link
                                href={gitSettings()}
                                className="type-small text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                            >
                                GitLab, Gitea and other git servers
                            </Link>
                        </>
                    ) : (
                        <p className="type-small text-muted-foreground">
                            A team owner or admin can connect GitHub in Settings
                            → Git.
                        </p>
                    )}
                </div>
                <p className="mt-auto flex items-center gap-2 border-t pt-4 type-small text-muted-foreground">
                    <ArrowUp aria-hidden="true" className="size-3.5 shrink-0" />
                    Public repositories need no account: paste the URL above.
                </p>
            </div>
        </div>
    );
}
