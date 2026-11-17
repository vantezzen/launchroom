import { http } from '@inertiajs/react';
import { useQuery } from '@tanstack/react-query';
import {
    ArrowLeft,
    Download,
    ListFilter,
    Radio,
    ScrollText,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useIsMobile } from '@/hooks/use-mobile';
import { schedulePoll } from '@/hooks/use-live-props';
import { Histogram } from '@/components/charts/histogram';
import { TimeRangeSelect } from '@/components/charts/time-range-select';
import { EmptyState } from '@/components/patterns/empty-state';
import { MetaLine } from '@/components/patterns/meta-line';
import { SidePanel } from '@/components/patterns/sheet';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { deployMarkers } from '@/features/metrics/format';
import { requestJson } from '@/lib/http';
import { deploymentHref, serverHref } from '@/lib/links';
import {
    DEFAULT_RANGE,
    formatWindow,
    RANGE_SETS,
    rangeOptions,
    rangeWords,
    readScope,
    replaceQuery as updateUrl,
    snapRange,
} from '@/lib/observe-scope';
import type { ObserveWindow } from '@/lib/observe-scope';
import { cn } from '@/lib/utils';
import {
    download as teamDownloadRoute,
    entries as teamEntriesRoute,
} from '@/routes/logs';
import {
    download as downloadRoute,
    entries as entriesRoute,
    projectDownload as projectDownloadRoute,
    search as projectSearchRoute,
    tail as tailRoute,
} from '@/routes/projects/logs';
import { stop as stopTailRoute } from '@/routes/projects/logs/tail';
import { downloadLines } from '@/features/log-explorer/download';
import { bucketEntries, countFacets } from '@/features/log-explorer/facets';
import { FilterRail } from '@/features/log-explorer/filter-rail';
import { LogDetail, LogMessagePane } from '@/features/log-explorer/log-detail';
import { LogTable } from '@/features/log-explorer/log-table';
import {
    clearFilters,
    hasFilter,
    keepOnly,
    matchesQuery,
    parseLogQuery,
    removeFilter,
    setFilter,
    toggleFilter,
} from '@/features/log-explorer/query';
import { QueryBar } from '@/features/log-explorer/query-bar';
import type { LogEntry, LogLevel } from '@/features/log-explorer/types';
import type { QueryKey } from '@/features/log-explorer/query';

type Page = App.Data.Logs.LogExplorerPageData;
type Entry = App.Data.Logs.LogEntryData;
type Range = (typeof RANGE_SETS.logs)[number];
type Scope = 'environment' | 'project' | 'team';

const RANGES = rangeOptions(RANGE_SETS.logs);
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
/** A failed tail start (server busy, connection lost) tries again after this. */
const RETRY_MS = 30_000;
const MAX_LIVE = 20_000;
/** Wait this long after the last keystroke before searching on the server. */
const SEARCH_DEBOUNCE_MS = 300;
/** Where the message pane under the list shows the message instead of the details. */
const WIDE_HIDDEN = '@[760px]/logs:hidden';
/** "Surrounding lines": this much before and after the line. */
const CONTEXT_MS = 2 * 60_000;
/** Filters kept when showing a line's surroundings: where it ran, not what it said. */
const CONTEXT_KEYS: QueryKey[] = [
    'service',
    'deployment',
    'environment',
    'project',
];

/** The server's line → the explorer's row. */
function toEntry(entry: Entry): LogEntry & {
    source: string;
    deploymentId: string | null;
    serverId: string | null;
} {
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
        serverId: entry.serverId ?? null,
    };
}

type Row = ReturnType<typeof toEntry>;

/**
 * The log explorer (direction §9.8): query bar, range, Live; the histogram with deploy markers and errors stacked in
 * red; the filter rail with counts; the virtualised table (newest first)
 * in a bordered container. A selected line splits it: list over the
 * message pane, "Log details" on the right (over the list when the
 * container is narrow, a sheet on phones).
 *
 * Search runs on the server (docs/08 §12): `GET …/-/logs?q=` returns the
 * newest 5,000 matching lines of the range, how many match and the
 * histogram; the same query semantics filter live lines here. Scopes: one
 * environment (Live starts runtime tails on the servers, `POST …/-/logs/tail`,
 * renewed when the server says, stopped on Live off or when the explorer
 * closes, and polls for newer chunks), every environment of the project
 * (`environmentName` offers the switch), or the whole team (`team`, owners
 * and admins). Wide scopes add a "where" column and have no Live.
 *
 * `service` scopes it to one service (the service panel's Logs tab): no
 * rail, no service column. `autoLive` starts Live by itself, once: `now`
 * (nothing is stored for this environment) or `when-empty` (the first load
 * found no lines).
 */
export function LogExplorer({
    project,
    environment,
    team = false,
    environmentName,
    service = null,
    initialQuery = '',
    syncUrl = false,
    autoLive,
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
    /** Start Live by itself (one environment only). */
    autoLive?: 'now' | 'when-empty';
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
    // The observe scope (lib/observe-scope.ts): the range and an absolute
    // window from a chart or an alert (`?from=&to=`) come from the URL.
    const [urlScope] = useState(() =>
        syncUrl && typeof window !== 'undefined'
            ? readScope(window.location.search)
            : null,
    );
    const [range, setRange] = useState<Range>(() =>
        snapRange(urlScope?.range, RANGE_SETS.logs),
    );
    const [span, setSpan] = useState<ObserveWindow | null>(
        () => urlScope?.window ?? null,
    );
    const [query, setQuery] = useState(() =>
        initialQuery === '' && syncUrl && typeof window !== 'undefined'
            ? (new URLSearchParams(window.location.search).get('q') ?? '')
            : initialQuery,
    );
    const [searched, setSearched] = useState(query);
    const [rows, setRows] = useState<Row[]>([]);
    const [live, setLive] = useState(
        () => autoLive === 'now' && Boolean(project && environment && !team),
    );
    const autoLiveChecked = useRef(autoLive !== 'when-empty');
    const [following, setFollowing] = useState<number | null>(null);
    // Why a replica has no live tail, or isn't running (G9).
    const [tailNotes, setTailNotes] = useState<string[]>([]);
    // `?line=` (a copied line link) opens on that line, marked.
    const [linkedLine] = useState(() =>
        syncUrl && typeof window !== 'undefined'
            ? new URLSearchParams(window.location.search).get('line')
            : null,
    );
    const [selectedId, setSelectedId] = useState<string | null>(linkedLine);
    const [anchorId, setAnchorId] = useState<string | null>(linkedLine);
    // What "Back to results" restores after "Surrounding lines".
    const [beforeContext, setBeforeContext] = useState<{
        query: string;
        span: ObserveWindow | null;
    } | null>(null);
    const [filtersOpen, setFiltersOpen] = useState(false);
    const [messageOpen, setMessageOpen] = useState(true);
    const cursor = useRef(0);
    const args = { project: project ?? '', environment: environment ?? '' };
    const canLive = scope === 'environment' && span === null;

    // A window from the URL is a moment in the past: never live.
    useEffect(() => {
        if (span !== null) {
            setLive(false);
        }
    }, [span]);

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
                    ...(span
                        ? {
                              from: Math.floor(span.from / 1000),
                              to: Math.ceil(span.to / 1000),
                          }
                        : {}),
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
        [project, environment, scope, range, span, service, searched],
    );

    // "Download → All matching": the same search as a file (docs/08 §12).
    const downloadUrl = (format: 'text' | 'ndjson') => {
        const options = {
            query: {
                range,
                ...(span
                    ? {
                          from: Math.floor(span.from / 1000),
                          to: Math.ceil(span.to / 1000),
                      }
                    : {}),
                source: SOURCE,
                ...(searched !== '' ? { q: searched } : {}),
                ...(service ? { service } : {}),
                format,
            },
        };

        if (scope === 'team') {
            return teamDownloadRoute(options).url;
        }

        if (scope === 'project') {
            return projectDownloadRoute(args.project, options).url;
        }

        return downloadRoute(args, options).url;
    };

    // Live polling reads the latest search without restarting the tails.
    const urlRef = useRef(url);
    useEffect(() => {
        urlRef.current = url;
    }, [url]);

    // Load the range through the shared query cache (docs/10 §3): coming
    // back to the same logs shows the lines at once and refreshes them
    // behind; the first visit is the one wait.
    const firstUrl = url();
    const first = useQuery({
        queryKey: ['log-explorer', firstUrl],
        queryFn: ({ signal }) =>
            requestJson<Page>('get', firstUrl, undefined, signal),
        // Live lines arrive by polling; focus must not reset the list.
        refetchOnWindowFocus: false,
    });
    const page = first.data ?? null;
    const loading = first.isPending;
    const error = first.isError
        ? first.error instanceof Error
            ? first.error.message
            : 'Logs could not be loaded.'
        : null;

    // Each first page (cached or fresh) seeds the rows, newest first.
    const [seeded, setSeeded] = useState<Page | null>(null);

    if (first.data && first.data !== seeded) {
        setSeeded(first.data);
        setRows(first.data.entries.map(toEntry).reverse());
    }

    useEffect(() => {
        if (!first.data) {
            return;
        }

        cursor.current = first.data.cursor;

        if (!autoLiveChecked.current) {
            autoLiveChecked.current = true;

            if (first.data.entries.length === 0) {
                setLive(true);
            }
        }
    }, [first.data]);

    // Live: start/renew tails, poll newer chunks, stop when done.
    useEffect(() => {
        if (!live || !canLive) {
            return;
        }

        let stopped = false;
        let renew: number | undefined;
        const start = () =>
            requestJson<App.Data.Logs.LogTailStartedData>(
                'post',
                tailRoute(args).url,
                service ? { service } : {},
            )
                .then((result) => {
                    if (!stopped) {
                        setFollowing(result.following);
                        setTailNotes(result.notes ?? []);
                        renew = window.setTimeout(
                            () => void start(),
                            result.renewAfterSeconds * 1000,
                        );
                    }
                })
                .catch(() => {
                    if (!stopped) {
                        setFollowing(0);
                        setTailNotes([]);
                        renew = window.setTimeout(() => void start(), RETRY_MS);
                    }
                });
        void start();
        const stopPolling = schedulePoll(() => {
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
            window.clearTimeout(renew);
            stopPolling();
            setFollowing(null);
            setTailNotes([]);
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
    // Stored chunks can start before an absolute window: keep its lines only.
    const visible = useMemo(
        () =>
            rows.filter((row) => {
                if (!matchesQuery(row, parsed)) {
                    return false;
                }

                if (span === null) {
                    return true;
                }

                const at = Date.parse(row.time);

                return at >= span.from && at <= span.to;
            }),
        [rows, parsed, span],
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
    const markers = useMemo(() => deployMarkers(page?.markers), [page]);
    const selected = visible.find((row) => row.id === selectedId) ?? null;
    const index = selected ? visible.indexOf(selected) : -1;
    // Phones get the details in a sheet; elsewhere they open in place.
    const isPhone = useIsMobile();
    const inPlace = selected !== null && !isPhone;

    const changeQuery = (next: string) => {
        setQuery(next);

        if (syncUrl) {
            updateUrl({ q: next });
        }
    };
    const changeRange = (next: Range) => {
        setRange(next);
        setSpan(null);
        setBeforeContext(null);
        setAnchorId(null);

        if (syncUrl) {
            updateUrl({
                range: next === DEFAULT_RANGE ? null : next,
                from: null,
                to: null,
            });
        }
    };
    const changeSpan = (next: ObserveWindow | null, keep?: string) => {
        setSpan(next);
        setSelectedId(keep ?? null);

        if (syncUrl) {
            updateUrl({
                from: next ? String(Math.floor(next.from / 1000)) : null,
                to: next ? String(Math.ceil(next.to / 1000)) : null,
            });
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

    const similar = (row: Row) => {
        const head = row.message.replace(/\d+/g, '#').slice(0, 48);

        return rows.filter(
            (other) => other.message.replace(/\d+/g, '#').slice(0, 48) === head,
        ).length;
    };

    const closeDetail = () => setSelectedId(null);
    const contextWindow = (row: Row): ObserveWindow => {
        const at = Date.parse(row.time);

        return { from: at - CONTEXT_MS, to: at + CONTEXT_MS };
    };
    // Surrounding lines: drop the text and level filters, keep where the
    // line ran, centre the range on it and mark it.
    const showContext = (row: Row) => {
        if (!beforeContext) {
            setBeforeContext({ query, span });
        }

        changeQuery(keepOnly(query, CONTEXT_KEYS));
        changeSpan(contextWindow(row), row.id);
        setAnchorId(row.id);
    };
    const backToResults = () => {
        if (!beforeContext) {
            return;
        }

        changeQuery(beforeContext.query);
        changeSpan(beforeContext.span, selectedId ?? undefined);
        setBeforeContext(null);
        setAnchorId(null);
    };
    const pivot = {
        apply: (key: QueryKey, value: string, negated: boolean) =>
            changeQuery(
                hasFilter(query, key, value, negated)
                    ? removeFilter(query, key, value)
                    : setFilter(query, key, value, negated),
            ),
        active: (key: QueryKey, value: string, negated: boolean) =>
            hasFilter(query, key, value, negated),
    };
    const lineLink = (row: Row): string | null => {
        if (!syncUrl || typeof window === 'undefined') {
            return null;
        }

        const link = new URL(window.location.href);
        const around = contextWindow(row);
        const filters = keepOnly(query, CONTEXT_KEYS);

        link.search = '';

        if (scope === 'project') {
            link.searchParams.set('scope', 'project');
        }

        if (filters !== '') {
            link.searchParams.set('q', filters);
        }

        link.searchParams.set('from', String(Math.floor(around.from / 1000)));
        link.searchParams.set('to', String(Math.ceil(around.to / 1000)));
        link.searchParams.set('line', row.id);

        return link.toString();
    };
    const facetCount = parsed.filters.length;
    const rail = (className: string) => (
        <FilterRail
            groups={facets}
            selected={selectedFilters}
            onToggle={(group, value) =>
                changeQuery(toggleFilter(query, group as QueryKey, value))
            }
            onReset={() => changeQuery(clearFilters(query))}
            className={className}
        />
    );
    // `sheet`: the phone sheet names it and closes it; the in-place panel
    // shows the message only while the container is too narrow for the
    // message pane under the list.
    const detail = (sheet: boolean) =>
        selected && (
            <LogDetail
                entry={selected}
                hrefs={{
                    deployment: selected.deploymentId
                        ? deploymentHref(selected.deploymentId)
                        : null,
                    server: selected.serverId
                        ? serverHref(selected.serverId)
                        : null,
                }}
                title={sheet ? null : 'Log details'}
                onClose={sheet ? undefined : closeDetail}
                onShowContext={
                    anchorId === selected.id
                        ? undefined
                        : () => showContext(selected)
                }
                pivot={pivot}
                lineLink={lineLink(selected)}
                messageClassName={sheet ? undefined : WIDE_HIDDEN}
                className={sheet ? undefined : 'h-full'}
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
                    <p className="type-small text-muted-foreground">
                        {(() => {
                            const count = similar(selected);

                            return count > 1
                                ? `${count} similar lines in this range.`
                                : 'The only line like this in this range.';
                        })()}
                    </p>
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
                span ? (
                    <Button variant="outline" onClick={() => changeSpan(null)}>
                        Show the {rangeWords(range)}
                    </Button>
                ) : WIDER[range] ? (
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
            {span
                ? `Nothing logged from ${formatWindow(span)}.`
                : canLive
                  ? 'Nothing logged in this time range. Runtime logs are shown live unless the environment keeps them: turn on Live to follow your services.'
                  : 'Nothing logged in this time range. Runtime logs are searchable here when an environment keeps them (project settings → Logs).'}
        </EmptyState>
    );

    return (
        <div className={cn('flex min-h-0 flex-1 flex-col', className)}>
            <div
                className={cn(
                    'flex flex-col gap-3 py-3',
                    !service && 'px-4 sm:px-6',
                )}
            >
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
                    {beforeContext && (
                        <Button
                            variant="outline"
                            size="sm"
                            className="h-9"
                            onClick={backToResults}
                        >
                            <ArrowLeft aria-hidden="true" />
                            Back to results
                        </Button>
                    )}
                    {span && (
                        <span className="inline-flex h-8 items-center gap-1 rounded-lg border border-border-strong pr-1 pl-2.5 type-label tabular-nums">
                            {formatWindow(span)}
                            <button
                                type="button"
                                onClick={() => changeSpan(null)}
                                aria-label={`Show the ${rangeWords(range)} instead`}
                                className="inline-flex size-6 items-center justify-center rounded-md text-muted-foreground hover:bg-(--hover) hover:text-foreground focus-visible:outline-2 focus-visible:outline-(--signal)"
                            >
                                <X aria-hidden="true" className="size-3.5" />
                            </button>
                        </span>
                    )}
                    {/* A picked window (drag on the histogram, a chart, an
                        alert) replaces the range until it is cleared. */}
                    {!span && (
                        <TimeRangeSelect<Range>
                            value={range}
                            onChange={changeRange}
                            options={RANGES}
                            label="Time range"
                        />
                    )}
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
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                variant="outline"
                                size="icon"
                                className="size-9"
                                aria-label="Download lines"
                                title="Download lines"
                            >
                                <Download aria-hidden="true" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-64">
                            <DropdownMenuLabel className="type-small text-muted-foreground">
                                Lines on screen (
                                {visible.length.toLocaleString('en')})
                            </DropdownMenuLabel>
                            <DropdownMenuItem
                                disabled={visible.length === 0}
                                onSelect={() =>
                                    downloadLines(
                                        [...visible].reverse(),
                                        'text',
                                    )
                                }
                            >
                                As text
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                disabled={visible.length === 0}
                                onSelect={() =>
                                    downloadLines(
                                        [...visible].reverse(),
                                        'ndjson',
                                    )
                                }
                            >
                                As NDJSON
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuLabel className="type-small text-muted-foreground">
                                All matching (up to 10,000)
                            </DropdownMenuLabel>
                            <DropdownMenuItem asChild>
                                <a href={downloadUrl('text')} download>
                                    As text
                                </a>
                            </DropdownMenuItem>
                            <DropdownMenuItem asChild>
                                <a href={downloadUrl('ndjson')} download>
                                    As NDJSON
                                </a>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                    {!service && (
                        <Button
                            variant="outline"
                            size="sm"
                            className="h-9 md:hidden"
                            onClick={() => setFiltersOpen(true)}
                        >
                            <ListFilter aria-hidden="true" />
                            {facetCount > 0
                                ? `Filters (${facetCount})`
                                : 'Filters'}
                        </Button>
                    )}
                </div>
                <div className="flex flex-col gap-1">
                    <Histogram
                        buckets={buckets}
                        label={
                            span
                                ? `Log lines from ${formatWindow(span)}`
                                : `Log lines over the ${rangeWords(range)}`
                        }
                        markers={markers}
                        height={64}
                        highlight={selected ? Date.parse(selected.time) : null}
                        // Dragging picks a window; a bucket narrows to its time.
                        onBrush={
                            live ? undefined : (picked) => changeSpan(picked)
                        }
                        onSelect={
                            live
                                ? undefined
                                : (bucket) =>
                                      changeSpan({
                                          from: bucket.start,
                                          to: bucket.end,
                                      })
                        }
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
                    {live && tailNotes.length > 0 && (
                        <ul
                            aria-label="Replicas without live logs"
                            className="flex flex-col gap-0.5 type-small text-muted-foreground"
                        >
                            {tailNotes.map((note) => (
                                <li key={note}>{note}</li>
                            ))}
                        </ul>
                    )}
                </div>
            </div>

            <div
                className={cn(
                    // One row bounded by the explorer's height: the filters,
                    // the lines, the message and the details each scroll on
                    // their own; the page never does.
                    'grid min-h-0 flex-1 grid-cols-1 grid-rows-[minmax(0,1fr)] gap-4',
                    service ? 'pb-1' : 'px-4 pb-4 sm:px-6',
                    !service && 'md:grid-cols-[200px_minmax(0,1fr)]',
                )}
            >
                {!service && rail('min-h-0 overflow-auto pr-1 max-md:hidden')}
                <div
                    // Wide (container ≥ 760 px): list over the message pane,
                    // details in a column on the right. Narrower (the
                    // service panel), the details cover the list.
                    className="@container/logs relative flex min-h-0 flex-col overflow-hidden rounded-xl border bg-card"
                    onKeyDown={(event) => {
                        if (event.key === 'Escape' && selected) {
                            event.preventDefault();
                            closeDetail();
                        }
                    }}
                >
                    <div
                        className={cn(
                            'grid min-h-0 flex-1 grid-cols-1 grid-rows-[minmax(0,1fr)]',
                            inPlace &&
                                '@[760px]/logs:grid-cols-[minmax(0,1fr)_380px] @[1100px]/logs:grid-cols-[minmax(0,1fr)_440px]',
                        )}
                    >
                        <div
                            className={cn(
                                'grid min-h-0 min-w-0 grid-cols-[minmax(0,1fr)] grid-rows-[minmax(0,1fr)]',
                                inPlace &&
                                    (messageOpen
                                        ? '@[760px]/logs:grid-rows-[minmax(0,58fr)_minmax(0,42fr)]'
                                        : '@[760px]/logs:grid-rows-[minmax(0,1fr)_auto]'),
                            )}
                        >
                            <LogTable
                                entries={visible}
                                selectedId={selectedId}
                                anchorId={anchorId}
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
                            />
                            {inPlace && selected && (
                                <LogMessagePane
                                    entry={selected}
                                    open={messageOpen}
                                    onToggle={() =>
                                        setMessageOpen((open) => !open)
                                    }
                                    className="hidden @[760px]/logs:flex"
                                />
                            )}
                        </div>
                        {inPlace && (
                            <div className="absolute inset-0 z-10 min-h-0 bg-card @[760px]/logs:static @[760px]/logs:border-l">
                                {detail(false)}
                            </div>
                        )}
                    </div>
                </div>
            </div>

            {isPhone && !service && (
                <SidePanel
                    open={filtersOpen}
                    onOpenChange={setFiltersOpen}
                    title="Filters"
                    size="sm"
                >
                    {rail('p-5')}
                </SidePanel>
            )}
            {isPhone && (
                <SidePanel
                    open={selected !== null}
                    onOpenChange={(open) => !open && closeDetail()}
                    title="Log details"
                    size="md"
                >
                    {detail(true)}
                </SidePanel>
            )}
        </div>
    );
}
