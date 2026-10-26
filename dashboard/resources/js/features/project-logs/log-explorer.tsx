import { http } from '@inertiajs/react';
import { Radio, ScrollText } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Histogram } from '@/components/charts/histogram';
import type { ChartMarker } from '@/components/charts/time-series-chart';
import { TimeRangeSelect } from '@/components/charts/time-range-select';
import type { TimeRangeOption } from '@/components/charts/time-range-select';
import { EmptyState } from '@/components/patterns/empty-state';
import { MetaLine } from '@/components/patterns/meta-line';
import { SidePanel } from '@/components/patterns/sheet';
import { Button } from '@/components/ui/button';
import {
    bucketEntries,
    countFacets,
    FilterRail,
    LogDetail,
    LogTable,
    matchesQuery,
    parseLogQuery,
    QueryBar,
    toggleFilter,
} from '@/features/logs/explorer';
import type { LogEntry, LogLevel, QueryKey } from '@/features/logs/explorer';
import { requestJson } from '@/features/projects/http';
import { cn } from '@/lib/utils';
import { entries as teamEntriesRoute } from '@/routes/logs';
import {
    entries as entriesRoute,
    search as projectSearchRoute,
    tail as tailRoute,
} from '@/routes/projects/logs';
import { stop as stopTailRoute } from '@/routes/projects/logs/tail';

type Page = App.Data.Logs.LogExplorerPageData;
type Entry = App.Data.Logs.LogEntryData;
type Range = '15m' | '1h' | '24h' | '7d' | '30d';
type Scope = 'environment' | 'project' | 'team';

const RANGES: TimeRangeOption<Range>[] = [
    { value: '15m', label: '15m' },
    { value: '1h', label: '1h' },
    { value: '24h', label: '24h' },
    { value: '7d', label: '7d' },
    { value: '30d', label: '30d' },
];
/**
 * Build output (plan, build, release) belongs to its deployment's Steps,
 * not here (docs/08 §12): the explorer asks for everything else, which is
 * what services print plus rollout, health and lifecycle events.
 */
const SOURCE = 'runtime';
const WIDER: Record<Range, Range | null> = {
    '15m': '1h',
    '1h': '24h',
    '24h': '7d',
    '7d': '30d',
    '30d': null,
};
const POLL_MS = 2_000;
/** Tails run 5 minutes server-side; renew well before. */
const RENEW_MS = 3 * 60_000;
const MAX_LIVE = 20_000;
/** Wait this long after the last keystroke before searching on the server. */
const SEARCH_DEBOUNCE_MS = 300;

/** The server's line → the explorer's row (features/logs/explorer). */
function toEntry(
    entry: Entry,
): LogEntry & { source: string; deploymentId: string | null } {
    return {
        id: entry.id,
        time: entry.time,
        level: entry.level as LogLevel,
        service: entry.service,
        replica: entry.replica,
        server: entry.server,
        deployment: entry.deployment,
        stream: entry.stream as LogEntry['stream'],
        message: entry.message,
        environment: entry.environment,
        project: entry.project,
        source: entry.source,
        deploymentId: entry.deploymentId,
    };
}

type Row = ReturnType<typeof toEntry>;

function updateUrl(params: Record<string, string | null>) {
    const url = new URL(window.location.href);

    for (const [key, value] of Object.entries(params)) {
        if (value === null || value === '') {
            url.searchParams.delete(key);
        } else {
            url.searchParams.set(key, value);
        }
    }

    window.history.replaceState(window.history.state, '', url);
}

/**
 * The log explorer (direction §9.8): query bar, range, Live; the histogram with deploy markers and errors stacked in
 * red; the filter rail with counts; the virtualised table (newest first);
 * the detail drawer (right column on wide screens, a sheet on phones).
 *
 * Search runs on the server (docs/08 §12): `GET …/-/logs?q=` returns the
 * newest 5,000 matching lines of the range, how many match and the
 * histogram; the same query semantics filter live lines here. Scopes: one
 * environment (Live starts runtime tails on the servers, `POST …/-/logs/tail`,
 * renewed every three minutes, stopped on Live off or when the explorer
 * closes, and polls for newer chunks), every environment of the project
 * (`environmentName` offers the switch), or the whole team (`team`, owners
 * and admins). Wide scopes add a "where" column and have no Live.
 *
 * `service` scopes it to one service (the service panel's Logs tab): no
 * rail, no service column.
 */
export function LogExplorer({
    project,
    environment,
    team = false,
    environmentName,
    service = null,
    initialQuery = '',
    syncUrl = false,
    className,
}: {
    project?: string;
    environment?: string;
    /** Search every project of the current team (the team Logs page). */
    team?: boolean;
    /** Offer "<environment> · All environments" (the project Logs page). */
    environmentName?: string;
    /** Service public id: scope everything to it. */
    service?: string | null;
    initialQuery?: string;
    /** Keep `?q=`, `?range=`, `?scope=` in the address bar. */
    syncUrl?: boolean;
    className?: string;
}) {
    const [scope, setScope] = useState<Scope>(() => {
        if (team || !project) {
            return 'team';
        }

        if (!environment) {
            return 'project';
        }

        return syncUrl &&
            environmentName &&
            new URLSearchParams(window.location.search).get('scope') ===
                'project'
            ? 'project'
            : 'environment';
    });
    const [range, setRange] = useState<Range>(() => {
        const value = syncUrl
            ? new URLSearchParams(window.location.search).get('range')
            : null;

        return value && value in WIDER ? (value as Range) : '1h';
    });
    const [query, setQuery] = useState(initialQuery);
    const [searched, setSearched] = useState(initialQuery);
    const [page, setPage] = useState<Page | null>(null);
    const [rows, setRows] = useState<Row[]>([]);
    const [error, setError] = useState<string | null>(null);
    const [loading, setLoading] = useState(true);
    const [live, setLive] = useState(false);
    const [following, setFollowing] = useState<number | null>(null);
    const [selectedId, setSelectedId] = useState<string | null>(null);
    const cursor = useRef(0);
    const args = { project: project ?? '', environment: environment ?? '' };
    const canLive = scope === 'environment';

    // Search on the server once typing pauses.
    useEffect(() => {
        const timer = window.setTimeout(
            () => setSearched(query.trim()),
            SEARCH_DEBOUNCE_MS,
        );

        return () => window.clearTimeout(timer);
    }, [query]);

    const url = useCallback(
        (after = 0) => {
            const options = {
                query: {
                    range,
                    source: SOURCE,
                    ...(searched !== '' ? { q: searched } : {}),
                    ...(service ? { service } : {}),
                    ...(after > 0 ? { after } : {}),
                },
            };

            if (scope === 'team') {
                return teamEntriesRoute(options).url;
            }

            if (scope === 'project') {
                return projectSearchRoute(args.project, options).url;
            }

            return entriesRoute(args, options).url;
        },
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [project, environment, scope, range, service, searched],
    );

    // Live polling reads the latest search without restarting the tails.
    const urlRef = useRef(url);
    useEffect(() => {
        urlRef.current = url;
    }, [url]);

    // Load the range.
    useEffect(() => {
        const controller = new AbortController();
        setLoading(true);
        setError(null);

        requestJson<Page>('get', url(), undefined, controller.signal)
            .then((result) => {
                setPage(result);
                // Newest first.
                setRows(result.entries.map(toEntry).reverse());
                cursor.current = result.cursor;
            })
            .catch((reason: unknown) => {
                if (!controller.signal.aborted) {
                    setError(
                        reason instanceof Error
                            ? reason.message
                            : 'Logs could not be loaded.',
                    );
                }
            })
            .finally(() => {
                if (!controller.signal.aborted) {
                    setLoading(false);
                }
            });

        return () => controller.abort();
    }, [url]);

    // Live: start/renew tails, poll newer chunks, stop when done.
    useEffect(() => {
        if (!live || !canLive) {
            return;
        }

        let stopped = false;
        const start = () =>
            requestJson<{ following: number }>(
                'post',
                tailRoute(args).url,
                service ? { service } : {},
            )
                .then((result) => !stopped && setFollowing(result.following))
                .catch(() => !stopped && setFollowing(0));
        void start();
        const renew = window.setInterval(() => void start(), RENEW_MS);
        const poll = window.setInterval(() => {
            if (document.visibilityState !== 'visible') {
                return;
            }

            requestJson<Page>('get', urlRef.current(cursor.current))
                .then((result) => {
                    if (stopped || result.entries.length === 0) {
                        return;
                    }

                    cursor.current = result.cursor;
                    setRows((current) =>
                        [
                            ...result.entries.map(toEntry).reverse(),
                            ...current,
                        ].slice(0, MAX_LIVE),
                    );
                })
                .catch(() => undefined);
        }, POLL_MS);

        return () => {
            stopped = true;
            window.clearInterval(renew);
            window.clearInterval(poll);
            setFollowing(null);
            void http
                .getClient()
                .request({
                    method: 'delete',
                    url: stopTailRoute(args).url,
                    headers: { Accept: 'application/json' },
                })
                .catch(() => undefined);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [live, canLive, service, project, environment]);

    const parsed = useMemo(() => parseLogQuery(query), [query]);
    const visible = useMemo(
        () => rows.filter((row) => matchesQuery(row, parsed)),
        [rows, parsed],
    );
    // Counts come from the loaded lines; a selected value stays listed (at
    // its count) so it can be unselected after the server narrowed them.
    const facets = useMemo(() => {
        const groups = countFacets(rows);

        for (const filter of parsed.filters) {
            const group = groups.find((item) => item.key === filter.key);

            if (
                !filter.negated &&
                group &&
                !group.options.some((option) => option.value === filter.value)
            ) {
                group.options.push({
                    value: filter.value,
                    label: filter.value,
                    count: 0,
                });
            }
        }

        return groups;
    }, [rows, parsed]);
    const selectedFilters = Object.fromEntries(
        facets.map((group) => [
            group.key,
            parsed.filters
                .filter((f) => f.key === group.key && !f.negated)
                .map((f) => f.value),
        ]),
    );
    const from = page ? Date.parse(page.from) : Date.now() - 3600_000;
    const to = live ? Date.now() : page ? Date.parse(page.to) : Date.now();
    const buckets = useMemo(
        () =>
            // The server counted every matching line of the range.
            !live && page && page.buckets.length > 0
                ? page.buckets
                : rows.length === 0 && !page
                  ? []
                  : bucketEntries(
                        rows.filter((row) =>
                            matchesQuery(row, {
                                ...parsed,
                                filters: parsed.filters.filter(
                                    (f) => f.key !== 'level',
                                ),
                            }),
                        ),
                        from,
                        to,
                        60,
                    ),
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [rows, parsed, page, live],
    );
    const markers: ChartMarker[] = (page?.markers ?? []).map((marker) => ({
        t: Date.parse(marker.at),
        label: marker.label,
        tone: marker.status === 'failed' ? 'error' : 'signal',
    }));
    const selected = visible.find((row) => row.id === selectedId) ?? null;
    const index = selected ? visible.indexOf(selected) : -1;

    const changeQuery = (next: string) => {
        setQuery(next);

        if (syncUrl) {
            updateUrl({ q: next });
        }
    };
    const changeRange = (next: Range) => {
        setRange(next);

        if (syncUrl) {
            updateUrl({ range: next === '1h' ? null : next });
        }
    };
    const changeScope = (next: Scope) => {
        setScope(next);
        setLive(false);
        setSelectedId(null);

        if (syncUrl) {
            updateUrl({ scope: next === 'project' ? 'project' : null });
        }
    };

    const facts = (row: Row) => [
        {
            label: 'Time',
            value: new Date(row.time).toLocaleString('en-GB', {
                hour12: false,
            }),
            mono: true,
        },
        ...(row.project ? [{ label: 'Project', value: row.project }] : []),
        ...(row.environment
            ? [{ label: 'Environment', value: row.environment }]
            : []),
        ...(row.deployment
            ? [{ label: 'Deployment', value: row.deployment, mono: true }]
            : []),
        ...(row.replica
            ? [{ label: 'Replica', value: row.replica, mono: true }]
            : []),
        ...(row.server
            ? [{ label: 'Server', value: row.server, mono: true }]
            : []),
        ...(row.stream
            ? [{ label: 'Stream', value: row.stream, mono: true }]
            : []),
    ];
    const similar = (row: Row) => {
        const head = row.message.replace(/\d+/g, '#').slice(0, 48);

        return rows.filter(
            (other) => other.message.replace(/\d+/g, '#').slice(0, 48) === head,
        ).length;
    };

    const detail = selected && (
        <LogDetail
            entry={selected}
            facts={facts(selected)}
            onClose={() => setSelectedId(null)}
            onPrevious={
                index > 0
                    ? () => setSelectedId(visible[index - 1].id)
                    : undefined
            }
            onNext={
                index < visible.length - 1
                    ? () => setSelectedId(visible[index + 1].id)
                    : undefined
            }
            footer={
                <div className="flex flex-col gap-3">
                    <p className="type-small text-muted-foreground">
                        {(() => {
                            const count = similar(selected);

                            return count > 1
                                ? `${count} similar lines in this range.`
                                : 'The only line like this in this range.';
                        })()}
                    </p>
                    {!service && (
                        <div className="flex flex-wrap gap-2">
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    changeQuery(
                                        toggleFilter(
                                            query,
                                            'service',
                                            selected.service,
                                        ),
                                    )
                                }
                            >
                                {parsed.filters.some(
                                    (f) =>
                                        f.key === 'service' &&
                                        f.value === selected.service,
                                )
                                    ? `All services`
                                    : `Only ${selected.service}`}
                            </Button>
                        </div>
                    )}
                </div>
            }
        />
    );

    const empty = loading ? (
        <p className="p-6 type-body text-muted-foreground" aria-live="polite">
            Loading lines…
        </p>
    ) : error ? (
        <EmptyState icon={ScrollText} variant="plain" className="py-16">
            {error}
        </EmptyState>
    ) : rows.length > 0 ||
      parsed.filters.length > 0 ||
      parsed.text.length > 0 ? (
        <EmptyState
            icon={ScrollText}
            variant="plain"
            className="py-16"
            action={
                <Button variant="outline" onClick={() => changeQuery('')}>
                    Clear the search
                </Button>
            }
        >
            No lines match this search.
        </EmptyState>
    ) : (
        <EmptyState
            icon={ScrollText}
            variant="plain"
            className="py-16"
            action={
                WIDER[range] ? (
                    <Button
                        variant="outline"
                        onClick={() => changeRange(WIDER[range] as Range)}
                    >
                        Widen the range
                    </Button>
                ) : undefined
            }
            secondary={
                canLive &&
                !live && (
                    <Button variant="ghost" onClick={() => setLive(true)}>
                        Follow live
                    </Button>
                )
            }
        >
            {canLive
                ? 'Nothing logged in this time range. Runtime logs are shown live unless the environment keeps them: turn on Live to follow your services.'
                : 'Nothing logged in this time range. Runtime logs are searchable here when an environment keeps them (project settings → Logs).'}
        </EmptyState>
    );

    return (
        <div className={cn('flex min-h-0 flex-1 flex-col', className)}>
            <div className="flex flex-col gap-3 border-b px-4 py-3 sm:px-6">
                <div className="flex flex-wrap items-start gap-2">
                    <QueryBar
                        value={query}
                        onChange={changeQuery}
                        placeholder={
                            service
                                ? 'Search   level:error "timeout"'
                                : undefined
                        }
                        className="min-w-0 flex-1 basis-72"
                    />
                    {environmentName && project && environment && (
                        <TimeRangeSelect<Scope>
                            value={scope}
                            onChange={changeScope}
                            options={[
                                {
                                    value: 'environment',
                                    label: environmentName,
                                },
                                { value: 'project', label: 'All environments' },
                            ]}
                            label="Search in"
                            className="max-sm:order-3"
                        />
                    )}
                    <TimeRangeSelect<Range>
                        value={range}
                        onChange={changeRange}
                        options={RANGES}
                        label="Time range"
                    />
                    {canLive && (
                        <Button
                            variant={live ? 'default' : 'outline'}
                            size="sm"
                            className="h-9"
                            aria-pressed={live}
                            onClick={() => setLive((value) => !value)}
                        >
                            <Radio aria-hidden="true" />
                            Live
                        </Button>
                    )}
                </div>
                <div className="flex flex-col gap-1">
                    <Histogram
                        buckets={buckets}
                        label={`Log lines over the last ${range}`}
                        markers={markers}
                        height={64}
                        highlight={selected ? Date.parse(selected.time) : null}
                    />
                    <p
                        className="type-small text-muted-foreground"
                        aria-live="polite"
                    >
                        <MetaLine>
                            {live || !page
                                ? `${visible.length.toLocaleString('en')} of ${rows.length.toLocaleString('en')} lines`
                                : `${page.matches.toLocaleString('en')} ${searched !== '' ? (page.matches === 1 ? 'matching line' : 'matching lines') : page.matches === 1 ? 'line' : 'lines'}`}
                            {!live && page?.truncated && 'newest 5,000 shown'}
                            {scope === 'project' && 'all environments'}
                            {scope === 'team' && 'all projects'}
                            {live &&
                                (following === null
                                    ? 'starting live logs…'
                                    : following === 0
                                      ? 'no running service to follow'
                                      : `following ${following} ${following === 1 ? 'replica' : 'replicas'}`)}
                        </MetaLine>
                    </p>
                </div>
            </div>

            <div
                className={cn(
                    'grid min-h-0 flex-1 grid-cols-1',
                    !service && 'md:grid-cols-[220px_minmax(0,1fr)]',
                    selected &&
                        (service
                            ? 'xl:grid-cols-[minmax(0,1fr)_340px]'
                            : 'xl:grid-cols-[220px_minmax(0,1fr)_340px]'),
                )}
            >
                {!service && (
                    <FilterRail
                        groups={facets}
                        selected={selectedFilters}
                        onToggle={(group, value) =>
                            changeQuery(
                                toggleFilter(query, group as QueryKey, value),
                            )
                        }
                        className="overflow-auto border-r px-4 py-4 max-md:hidden"
                    />
                )}
                <LogTable
                    entries={visible}
                    selectedId={selectedId}
                    onSelect={(entry) => setSelectedId(entry.id)}
                    highlight={parsed.text.join(' ')}
                    hideService={Boolean(service)}
                    context={
                        scope === 'team'
                            ? 'project'
                            : scope === 'project'
                              ? 'environment'
                              : null
                    }
                    empty={empty}
                    live={live && canLive}
                    className="min-h-[320px]"
                />
                {selected && (
                    <div className="min-h-0 overflow-auto border-l max-xl:hidden">
                        {detail}
                    </div>
                )}
            </div>

            <div className="xl:hidden">
                <SidePanel
                    open={
                        selected !== null &&
                        typeof window !== 'undefined' &&
                        window.innerWidth < 1280
                    }
                    onOpenChange={(open) => !open && setSelectedId(null)}
                    title="Log line"
                    size="md"
                >
                    {detail}
                </SidePanel>
            </div>
        </div>
    );
}
