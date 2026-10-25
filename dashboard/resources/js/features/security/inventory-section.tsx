import { Link } from '@inertiajs/react';
import { useQuery } from '@tanstack/react-query';
import { PackageSearch, Search, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Meter } from '@/components/charts/usage-meter';
import { Logo } from '@/components/logo';
import { EmptyState } from '@/components/patterns/empty-state';
import { StatusIcon } from '@/components/status';
import { Section } from '@/components/patterns/section';
import { Input } from '@/components/ui/input';
import { requestJson } from '@/features/projects/http';
import { cn } from '@/lib/utils';
import { search as searchRoute } from '@/routes/security/inventory';
import { SeverityText } from './finding-card';
import { card, ECOSYSTEM_LABEL, longDate, plural, SCOPE_LABEL } from './format';

type Runtime = App.Data.Security.RuntimeCardData;
type SearchResult = App.Data.Security.InventorySearchData;

/**
 * Inventory (docs/22 §9.2 part 5): "do we run X version Y?" in one search.
 * Without a query, the runtimes, engines and base images in use with their
 * release cycles.
 */
export function InventorySection({
    runtimes,
    baseImages,
    initialQuery,
    onOpenFinding,
    description,
}: {
    description?: string;
    runtimes: Runtime[];
    baseImages: Runtime[];
    initialQuery: string | null;
    onOpenFinding: (href: string) => void;
}) {
    const [query, setQuery] = useState(initialQuery ?? '');
    const [debounced, setDebounced] = useState(query);

    useEffect(() => {
        const handle = window.setTimeout(() => setDebounced(query.trim()), 250);

        return () => window.clearTimeout(handle);
    }, [query]);

    useEffect(() => {
        const url =
            debounced === ''
                ? '/security'
                : `/security/inventory?q=${encodeURIComponent(debounced)}`;

        if (
            window.location.pathname + window.location.search !== url &&
            (debounced !== '' ||
                window.location.pathname === '/security/inventory')
        ) {
            window.history.replaceState(window.history.state, '', url);
        }
    }, [debounced]);

    const { data, isFetching } = useQuery({
        queryKey: ['security-inventory', debounced],
        queryFn: ({ signal }) =>
            requestJson<SearchResult>(
                'get',
                searchRoute({ query: { q: debounced } }).url,
                undefined,
                signal,
            ),
        enabled: debounced !== '',
        placeholderData: (previous) => previous,
        staleTime: 30_000,
    });

    return (
        <Section title="Inventory" description={description}>
            <div className="flex flex-col gap-6">
                <label className="relative block">
                    <span className="sr-only">
                        Search packages and runtimes
                    </span>
                    <Search
                        aria-hidden="true"
                        className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <Input
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder="node 20, lodash, openssl 3.0"
                        className="h-10 pr-9 pl-9 font-mono"
                        spellCheck={false}
                        autoComplete="off"
                    />
                    {query !== '' && (
                        <button
                            type="button"
                            onClick={() => setQuery('')}
                            aria-label="Clear search"
                            className="absolute top-1/2 right-2 flex size-6 -translate-y-1/2 items-center justify-center rounded-md text-muted-foreground hover:bg-hover"
                        >
                            <X aria-hidden="true" className="size-3.5" />
                        </button>
                    )}
                </label>

                {debounced === '' ? (
                    <Overview runtimes={runtimes} baseImages={baseImages} />
                ) : (
                    <Results
                        data={data}
                        query={debounced}
                        loading={isFetching && !data}
                        onOpenFinding={onOpenFinding}
                    />
                )}
            </div>
        </Section>
    );
}

function Overview({
    runtimes,
    baseImages,
}: {
    runtimes: Runtime[];
    baseImages: Runtime[];
}) {
    if (runtimes.length === 0 && baseImages.length === 0) {
        return (
            <EmptyState icon={PackageSearch} variant="framed">
                Runtimes, engines and base images show here once launchroom has
                read the packages of a live service.
            </EmptyState>
        );
    }

    return (
        <div className="flex flex-col gap-6">
            {runtimes.length > 0 && (
                <Catalogue title="Runtimes and engines" items={runtimes} />
            )}
            {baseImages.length > 0 && (
                <Catalogue title="Base images" items={baseImages} />
            )}
        </div>
    );
}

function Catalogue({ title, items }: { title: string; items: Runtime[] }) {
    return (
        <div className="flex flex-col gap-2.5">
            <h3 className="type-label text-muted-foreground">{title}</h3>
            <ul className={cn(card, 'divide-y overflow-hidden')}>
                {items.map((runtime) => (
                    <RuntimeRow key={runtime.key} runtime={runtime} />
                ))}
            </ul>
        </div>
    );
}

/** A runtime with its support lifecycle from release to end of life. */
function RuntimeRow({ runtime }: { runtime: Runtime }) {
    const start = runtime.releaseDate ? Date.parse(runtime.releaseDate) : null;
    const end = runtime.eol ? Date.parse(runtime.eol) : null;
    const span =
        start !== null && end !== null && end > start ? end - start : null;
    const elapsed =
        span !== null && start !== null
            ? Math.min(span, Math.max(0, Date.now() - start))
            : null;

    return (
        <li
            data-runtime={runtime.key}
            className="grid grid-cols-[auto_minmax(0,1fr)] items-center gap-x-3 gap-y-2.5 px-5 py-3.5 md:grid-cols-[auto_minmax(0,13rem)_minmax(0,1fr)_minmax(0,15rem)] md:gap-x-5"
        >
            <Logo
                kind={runtime.logo ?? runtime.product}
                name={runtime.label}
                size={32}
                decorative
            />
            <span className="flex min-w-0 flex-col">
                <span className="truncate type-body font-medium">
                    {runtime.label}{' '}
                    <span className="font-mono font-normal">
                        {runtime.version}
                    </span>
                </span>
                <span className="type-small text-muted-foreground">
                    {plural(runtime.services, 'service')}
                </span>
            </span>
            <span className="col-span-2 min-w-0 md:col-span-1">
                {span !== null && elapsed !== null ? (
                    <Meter
                        value={elapsed}
                        max={span}
                        label={`${runtime.label} ${runtime.cycle ?? ''} support used`}
                        warnAt={100}
                        height={4}
                    />
                ) : (
                    <span className="block h-1 rounded-full border border-dashed border-border-strong" />
                )}
            </span>
            <span className="col-span-2 flex min-w-0 flex-col type-small md:col-span-1 md:items-end md:text-right">
                {runtime.isEol ? (
                    <span className="inline-flex items-center gap-1.5 font-medium">
                        <StatusIcon state="warning" />
                        Ended {longDate(runtime.eol as string)}
                    </span>
                ) : runtime.eol ? (
                    <span className="text-muted-foreground">
                        Supported until {longDate(runtime.eol)}
                    </span>
                ) : (
                    <span className="text-muted-foreground">
                        No release dates known yet
                    </span>
                )}
                {runtime.patchAvailable && runtime.latest && (
                    <span className="text-muted-foreground">
                        <span className="font-mono">{runtime.latest}</span>{' '}
                        available
                    </span>
                )}
            </span>
        </li>
    );
}

function Results({
    data,
    query,
    loading,
    onOpenFinding,
}: {
    data: SearchResult | undefined;
    query: string;
    loading: boolean;
    onOpenFinding: (href: string) => void;
}) {
    if (loading) {
        return (
            <div
                aria-hidden="true"
                className={cn(card, 'h-32 animate-skeleton-in')}
            />
        );
    }

    if (!data || data.packages.length === 0) {
        const name =
            query
                .split(/\s+/)
                .find((word) => !/^v?\d/.test(word) && !word.includes(':')) ??
            query;

        return (
            <EmptyState icon={PackageSearch} variant="framed">
                No service uses {name}.
            </EmptyState>
        );
    }

    return (
        <div className="flex flex-col gap-4">
            {data.packages.map((pkg) => (
                <div
                    key={pkg.ecosystem + pkg.name}
                    className={cn(card, 'overflow-hidden')}
                >
                    <div className="flex items-baseline gap-2 border-b bg-recessed px-5 py-3">
                        <code className="type-mono font-medium">
                            {pkg.name}
                        </code>
                        <span className="type-small text-muted-foreground">
                            {ECOSYSTEM_LABEL[pkg.ecosystem] ?? pkg.ecosystem}
                        </span>
                        <span className="ml-auto type-small text-muted-foreground">
                            {plural(pkg.versions.length, 'version')}
                        </span>
                    </div>
                    <ul className="divide-y">
                        {pkg.versions.map((use) => (
                            <li
                                key={use.version}
                                className="flex flex-col gap-2 px-5 py-3 sm:flex-row sm:items-start sm:gap-4"
                            >
                                <div className="flex w-40 shrink-0 flex-col">
                                    <code className="type-mono">
                                        {use.version}
                                    </code>
                                    <span className="type-small text-muted-foreground">
                                        {SCOPE_LABEL[use.scope] ?? use.scope}
                                    </span>
                                </div>
                                <div className="flex min-w-0 flex-1 flex-col gap-1.5">
                                    <ul className="flex flex-wrap gap-x-4 gap-y-1 type-small">
                                        {use.services.map((service) => (
                                            <li
                                                key={
                                                    service.id +
                                                    service.environmentId
                                                }
                                            >
                                                <Link
                                                    href={service.href}
                                                    className="font-medium hover:underline"
                                                >
                                                    {service.name}
                                                </Link>
                                                <span className="text-muted-foreground">
                                                    {' '}
                                                    in {service.projectName} (
                                                    {service.environmentName})
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                    {use.findings.map((finding) => (
                                        <button
                                            key={finding.id}
                                            type="button"
                                            onClick={() =>
                                                onOpenFinding(finding.href)
                                            }
                                            className="flex min-w-0 items-center gap-3 text-left type-small hover:underline"
                                        >
                                            <SeverityText
                                                severity={finding.severity}
                                            />
                                            <span className="truncate">
                                                {finding.title ??
                                                    finding.advisory}
                                            </span>
                                        </button>
                                    ))}
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>
            ))}
            {data.truncated && (
                <p className="type-small text-muted-foreground">
                    Only the first matches are shown. Add a version or a filter
                    like service:api.
                </p>
            )}
        </div>
    );
}
