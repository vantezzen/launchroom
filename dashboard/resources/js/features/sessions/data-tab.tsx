import {
    Database,
    History,
    KeyRound,
    Play,
    Search,
    Table2,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { ConfirmPopover } from '@/components/patterns/confirm-popover';
import { EmptyState } from '@/components/patterns/empty-state';
import { LiveWait } from '@/components/patterns/live-wait';
import { TimeAgo } from '@/components/time-ago';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Skeleton } from '@/components/ui/skeleton';
import { Switch } from '@/components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import type { ServiceRef } from './api';
import { NullChip, ResultGrid } from './result-grid';
import type { DataResult } from './result-types';
import { useDataSession } from './use-data-session';
import { useTabData } from './use-tab-data';

type Data = App.Data.Sessions.DataTabData;
type Run = (command: Record<string, unknown>) => Promise<DataResult>;

const PAGE = 50;

/**
 * The Data tab of a managed database (docs/08 §15): schema sidebar, Browse
 * and Query. The session opens when the user asks for it, never on visit.
 */
export function DataTab(ref: ServiceRef) {
    const query = useTabData<Data>(ref, 'data');

    if (query.isPending) {
        return <Skeleton className="h-64 w-full rounded-xl" />;
    }

    if (!query.data) {
        return (
            <EmptyState icon={Database} title="Data unavailable">
                {query.error instanceof Error
                    ? query.error.message
                    : 'Try again in a moment.'}
            </EmptyState>
        );
    }

    return (
        <DataView
            serviceRef={ref}
            data={query.data}
            refresh={() => void query.refetch()}
        />
    );
}

function DataView({
    serviceRef,
    data,
    refresh,
}: {
    serviceRef: ServiceRef;
    data: Data;
    refresh: () => void;
}) {
    const session = useDataSession(serviceRef, refresh);
    const { phase } = session;

    if (phase.kind === 'live') {
        return <Workspace data={data} run={session.run} onEnd={session.end} />;
    }

    return (
        <div className="flex flex-col gap-4">
            {!data.available ? (
                <EmptyState icon={Database} title="Nothing to browse right now">
                    {data.unavailableReason}
                </EmptyState>
            ) : phase.kind === 'connecting' ? (
                <LiveWait
                    status={`Connecting to ${data.engineLabel}${data.replica ? ` on ${data.replica.serverName}` : ''}…`}
                    hint="Usually a second or two."
                    timeoutSeconds={20}
                    fallback={
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={session.end}
                        >
                            Cancel
                        </Button>
                    }
                />
            ) : (
                <div className="flex flex-col gap-4 rounded-xl border bg-card p-5 shadow-card">
                    <div className="flex items-start gap-3">
                        <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-recessed text-muted-foreground">
                            <Database aria-hidden="true" className="size-4" />
                        </span>
                        <div className="flex flex-col gap-0.5">
                            <p className="type-heading">
                                Browse {data.engineLabel}
                            </p>
                            <p className="type-small text-muted-foreground">
                                {DIALECT[data.dialect].summary}, read-only until
                                you allow writes. Every statement is recorded in
                                the audit log.
                            </p>
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-3">
                        <Button onClick={() => void session.open()}>
                            {phase.kind === 'ended'
                                ? 'Open again'
                                : 'Open data'}
                        </Button>
                        {phase.kind === 'ended' && (
                            <span className="type-small text-muted-foreground">
                                Session ended · {phase.reason}
                            </span>
                        )}
                    </div>
                    {phase.kind === 'idle' && phase.error && (
                        <p className="type-small text-status-error">
                            {phase.error}
                        </p>
                    )}
                </div>
            )}
            <HistoryList history={data.history} />
        </div>
    );
}

/** Wording per way of browsing (DataTabData.dialect). */
const DIALECT: Record<
    App.Data.Sessions.DataTabData['dialect'],
    {
        summary: string;
        noun: string;
        pick: string;
        statement: string;
        placeholder: string;
    }
> = {
    sql: {
        summary: 'Tables, rows and a SQL console',
        noun: 'table',
        pick: 'Pick a table on the left to see its rows.',
        statement: 'SQL statement',
        placeholder: 'SELECT * FROM users LIMIT 10',
    },
    redis: {
        summary: 'Keys, values and commands',
        noun: 'key',
        pick: 'Pick a key on the left to see its value.',
        statement: 'Command',
        placeholder: 'GET user:1',
    },
    mongo: {
        summary: 'Collections, documents and database commands',
        noun: 'collection',
        pick: 'Pick a collection on the left to see its documents.',
        statement: 'Database command (JSON)',
        placeholder:
            '{ "find": "users", "filter": { "plan": "pro" }, "limit": 20 }',
    },
};

type Selection =
    | { kind: 'table'; schema: string; name: string }
    | { kind: 'key'; key: string }
    | null;

function Workspace({
    data,
    run,
    onEnd,
}: {
    data: Data;
    run: Run;
    onEnd: () => void;
}) {
    const [selection, setSelection] = useState<Selection>(null);
    const [tab, setTab] = useState('browse');

    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1 type-small text-muted-foreground">
                <span className="flex items-center gap-1.5 text-foreground">
                    <span
                        aria-hidden="true"
                        className="size-2 rounded-full bg-status-ok"
                    />
                    Connected
                </span>
                <span>
                    {data.engineLabel}
                    {data.replica ? ` on ${data.replica.serverName}` : ''}
                </span>
                <Button
                    variant="ghost"
                    size="sm"
                    className="ml-auto"
                    onClick={onEnd}
                >
                    End session
                </Button>
            </div>
            <div className="@container">
                <div className="grid gap-4 @3xl:grid-cols-[220px_minmax(0,1fr)]">
                    <aside className="flex min-w-0 flex-col gap-2 @max-3xl:max-h-56 @max-3xl:overflow-y-auto">
                        {data.dialect !== 'redis' ? (
                            <SchemaSidebar
                                noun={DIALECT[data.dialect].noun}
                                run={run}
                                selection={selection}
                                onSelect={(next) => {
                                    setSelection(next);
                                    setTab('browse');
                                }}
                            />
                        ) : (
                            <KeySidebar
                                run={run}
                                selection={selection}
                                onSelect={(next) => {
                                    setSelection(next);
                                    setTab('browse');
                                }}
                            />
                        )}
                    </aside>
                    <Tabs
                        value={tab}
                        onValueChange={setTab}
                        className="min-w-0"
                    >
                        <TabsList>
                            <TabsTrigger value="browse">Browse</TabsTrigger>
                            <TabsTrigger value="query">Query</TabsTrigger>
                        </TabsList>
                        <TabsContent value="browse" className="min-w-0">
                            {selection?.kind === 'table' ? (
                                <TableBrowser
                                    key={`${selection.schema}.${selection.name}`}
                                    run={run}
                                    table={selection}
                                />
                            ) : selection?.kind === 'key' ? (
                                <KeyBrowser
                                    key={selection.key}
                                    run={run}
                                    name={selection.key}
                                />
                            ) : (
                                <EmptyState
                                    icon={
                                        data.dialect === 'redis'
                                            ? KeyRound
                                            : Table2
                                    }
                                    variant="plain"
                                >
                                    {DIALECT[data.dialect].pick}
                                </EmptyState>
                            )}
                        </TabsContent>
                        <TabsContent value="query" className="min-w-0">
                            <QueryConsole data={data} run={run} />
                        </TabsContent>
                    </Tabs>
                </div>
            </div>
        </div>
    );
}

function SidebarFilter({
    value,
    onChange,
    label,
    placeholder,
}: {
    value: string;
    onChange: (v: string) => void;
    label: string;
    placeholder: string;
}) {
    return (
        <div className="relative">
            <Search
                aria-hidden="true"
                className="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-faint"
            />
            <Input
                value={value}
                onChange={(event) => onChange(event.target.value)}
                aria-label={label}
                placeholder={placeholder}
                className="h-8 pl-8 type-small"
            />
        </div>
    );
}

function rowsLabel(rows: number | undefined): string | null {
    if (rows === undefined) {
        return null;
    }

    return rows >= 1000
        ? `~${new Intl.NumberFormat('en', { notation: 'compact' }).format(rows)}`
        : String(rows);
}

function SchemaSidebar({
    noun,
    run,
    selection,
    onSelect,
}: {
    noun: string;
    run: Run;
    selection: Selection;
    onSelect: (s: Selection) => void;
}) {
    const [result, setResult] = useState<DataResult | null>(null);
    const [filter, setFilter] = useState('');

    useEffect(() => {
        let alive = true;
        void run({ command: 'schema' }).then(
            (next) => alive && setResult(next),
        );

        return () => {
            alive = false;
        };
    }, [run]);

    const groups = useMemo(() => {
        const needle = filter.trim().toLowerCase();

        return (result?.schema?.groups ?? [])
            .map((group) => ({
                ...group,
                items: group.items.filter((item) =>
                    item.name.toLowerCase().includes(needle),
                ),
            }))
            .filter((group) => group.items.length > 0);
    }, [result, filter]);

    return (
        <>
            <SidebarFilter
                value={filter}
                onChange={setFilter}
                label={`Filter ${noun}s`}
                placeholder={`Filter ${noun}s`}
            />
            {result === null ? (
                <Skeleton className="h-40 rounded-lg" />
            ) : result.status !== 'ok' ? (
                <p className="type-small text-status-error">{result.message}</p>
            ) : groups.length === 0 ? (
                <p className="px-1 py-2 type-small text-muted-foreground">
                    {filter ? `No ${noun} matches.` : `No ${noun}s yet.`}
                </p>
            ) : (
                <nav
                    aria-label={`${noun[0].toUpperCase()}${noun.slice(1)}s`}
                    className="flex max-h-[min(60vh,560px)] flex-col gap-3 overflow-y-auto"
                >
                    {groups.map((group) => (
                        <div key={group.name} className="flex flex-col gap-0.5">
                            <p className="px-2 type-small text-faint">
                                {group.name}
                            </p>
                            {group.items.map((item) => {
                                const active =
                                    selection?.kind === 'table' &&
                                    selection.schema === group.name &&
                                    selection.name === item.name;

                                return (
                                    <button
                                        key={item.name}
                                        type="button"
                                        aria-current={
                                            active ? 'true' : undefined
                                        }
                                        onClick={() =>
                                            onSelect({
                                                kind: 'table',
                                                schema: group.name,
                                                name: item.name,
                                            })
                                        }
                                        className={cn(
                                            'flex items-center gap-2 rounded-md px-2 py-1.5 text-left type-small transition-colors duration-fast focus-visible:ring-2 focus-visible:ring-signal focus-visible:outline-none',
                                            active
                                                ? 'bg-hover text-foreground'
                                                : 'text-muted-foreground hover:bg-hover hover:text-foreground',
                                        )}
                                    >
                                        <Table2
                                            aria-hidden="true"
                                            className="size-3.5 shrink-0"
                                        />
                                        <span className="min-w-0 flex-1 truncate">
                                            {item.name}
                                        </span>
                                        {item.kind !== 'table' &&
                                            item.kind !== 'collection' && (
                                                <span className="text-faint">
                                                    {item.kind.replace(
                                                        '_',
                                                        ' ',
                                                    )}
                                                </span>
                                            )}
                                        <span className="shrink-0 text-faint tabular-nums">
                                            {rowsLabel(item.rows)}
                                        </span>
                                    </button>
                                );
                            })}
                        </div>
                    ))}
                </nav>
            )}
        </>
    );
}

function KeySidebar({
    run,
    selection,
    onSelect,
}: {
    run: Run;
    selection: Selection;
    onSelect: (s: Selection) => void;
}) {
    const [pattern, setPattern] = useState('*');
    const [items, setItems] = useState<{ key: string; type: string }[] | null>(
        null,
    );
    const [cursor, setCursor] = useState('0');
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const scan = useCallback(
        async (from: string, match: string, append: boolean) => {
            setBusy(true);
            const result = await run({
                command: 'keys',
                cursor: from,
                pattern: match || '*',
            });
            setBusy(false);

            if (result.status !== 'ok' || !result.keys) {
                setError(result.message ?? 'Couldn’t list keys.');

                return;
            }

            setError(null);
            setCursor(result.keys.cursor);
            setItems((previous) => [
                ...(append && previous ? previous : []),
                ...(result.keys?.items ?? []),
            ]);
        },
        [run],
    );

    useEffect(() => {
        const timer = window.setTimeout(
            () => void scan('0', pattern, false),
            250,
        );

        return () => window.clearTimeout(timer);
    }, [scan, pattern]);

    return (
        <>
            <SidebarFilter
                value={pattern}
                onChange={setPattern}
                label="Key pattern"
                placeholder="user:*"
            />
            {error && <p className="type-small text-status-error">{error}</p>}
            {items === null ? (
                <Skeleton className="h-40 rounded-lg" />
            ) : items.length === 0 && cursor === '0' ? (
                <p className="px-1 py-2 type-small text-muted-foreground">
                    No keys match.
                </p>
            ) : (
                <nav
                    aria-label="Keys"
                    className="flex max-h-[min(60vh,560px)] flex-col gap-0.5 overflow-y-auto"
                >
                    {items.map((item) => {
                        const active =
                            selection?.kind === 'key' &&
                            selection.key === item.key;

                        return (
                            <button
                                key={item.key}
                                type="button"
                                aria-current={active ? 'true' : undefined}
                                onClick={() =>
                                    onSelect({ kind: 'key', key: item.key })
                                }
                                className={cn(
                                    'flex items-center gap-2 rounded-md px-2 py-1.5 text-left type-small transition-colors duration-fast focus-visible:ring-2 focus-visible:ring-signal focus-visible:outline-none',
                                    active
                                        ? 'bg-hover text-foreground'
                                        : 'text-muted-foreground hover:bg-hover hover:text-foreground',
                                )}
                            >
                                <span className="min-w-0 flex-1 truncate font-mono">
                                    {item.key}
                                </span>
                                <Badge
                                    variant="outline"
                                    className="shrink-0 font-normal"
                                >
                                    {item.type}
                                </Badge>
                            </button>
                        );
                    })}
                    {cursor !== '0' && (
                        <Button
                            variant="ghost"
                            size="sm"
                            disabled={busy}
                            onClick={() => void scan(cursor, pattern, true)}
                        >
                            Load more
                        </Button>
                    )}
                </nav>
            )}
        </>
    );
}

function ResultLine({ result }: { result: DataResult }) {
    if (result.status !== 'ok') {
        return (
            <p className="type-small text-status-error">
                {result.message ?? 'The statement failed.'}
            </p>
        );
    }

    const rows = result.rows?.length ?? 0;
    const parts = [
        result.columns
            ? `${rows}${result.truncated ? '+' : ''} ${rows === 1 ? 'row' : 'rows'}`
            : result.affected !== undefined && result.affected !== null
              ? `${result.affected} ${result.affected === 1 ? 'row' : 'rows'} affected`
              : 'Done',
        `${result.durationMs} ms`,
        result.truncated ? 'first 500 shown' : null,
        result.cellsTruncated ? 'long values cut' : null,
    ].filter(Boolean);

    return (
        <p className="type-small text-muted-foreground tabular-nums">
            {parts.join(' · ')}
        </p>
    );
}

function TableBrowser({
    run,
    table,
}: {
    run: Run;
    table: { schema: string; name: string };
}) {
    const [page, setPage] = useState(0);
    const [result, setResult] = useState<DataResult | null>(null);

    useEffect(() => {
        let alive = true;
        void run({
            command: 'rows',
            schema: table.schema,
            table: table.name,
            page,
            pageSize: PAGE,
        }).then((next) => alive && setResult(next));

        return () => {
            alive = false;
        };
    }, [run, table.schema, table.name, page]);

    if (result === null) {
        return <Skeleton className="h-64 rounded-xl" />;
    }

    if (result.status !== 'ok') {
        return <ResultLine result={result} />;
    }

    const count = result.rows?.length ?? 0;

    return (
        <div className="flex flex-col gap-3">
            <div className="flex flex-wrap items-baseline gap-x-2">
                <p className="type-label">{table.name}</p>
                <p className="type-small text-muted-foreground tabular-nums">
                    {count === 0
                        ? 'No rows on this page'
                        : `Rows ${page * PAGE + 1}–${page * PAGE + count}`}
                </p>
            </div>
            {result.columns && (
                <ResultGrid
                    columns={result.columns}
                    rows={result.rows ?? []}
                    offset={page * PAGE}
                />
            )}
            <div className="flex items-center gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    disabled={page === 0}
                    onClick={() => {
                        setResult(null);
                        setPage((p) => p - 1);
                    }}
                >
                    Previous
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    disabled={count < PAGE}
                    onClick={() => {
                        setResult(null);
                        setPage((p) => p + 1);
                    }}
                >
                    Next
                </Button>
            </div>
        </div>
    );
}

function ttlLabel(ttlMs: number): string {
    if (ttlMs < 0) {
        return 'No expiry';
    }

    const seconds = Math.round(ttlMs / 1000);

    return seconds < 120
        ? `Expires in ${seconds} s`
        : `Expires in ${Math.round(seconds / 60)} min`;
}

function KeyBrowser({ run, name }: { run: Run; name: string }) {
    const [result, setResult] = useState<DataResult | null>(null);

    useEffect(() => {
        let alive = true;
        void run({ command: 'key', key: name }).then(
            (next) => alive && setResult(next),
        );

        return () => {
            alive = false;
        };
    }, [run, name]);

    if (result === null) {
        return <Skeleton className="h-40 rounded-xl" />;
    }

    const value = result.value;

    if (result.status !== 'ok' || !value) {
        return <ResultLine result={result} />;
    }

    return (
        <div className="flex flex-col gap-3">
            <div className="flex flex-wrap items-center gap-2">
                <p className="font-mono type-label [overflow-wrap:anywhere]">
                    {value.key}
                </p>
                <Badge variant="outline">{value.type}</Badge>
                <span className="type-small text-muted-foreground">
                    {ttlLabel(value.ttlMs)} · {value.size}{' '}
                    {value.type === 'string'
                        ? 'bytes'
                        : value.size === 1
                          ? 'item'
                          : 'items'}
                    {value.truncated ? ' · first part shown' : ''}
                </span>
            </div>
            {value.text !== undefined ? (
                <pre className="max-h-[min(60vh,560px)] overflow-auto rounded-xl border bg-recessed p-4 type-mono-small break-all whitespace-pre-wrap">
                    {value.text}
                </pre>
            ) : value.entries ? (
                <ResultGrid
                    columns={
                        value.type === 'zset'
                            ? ['member', 'score']
                            : value.type === 'stream'
                              ? ['id', 'fields']
                              : ['field', 'value']
                    }
                    rows={value.entries}
                />
            ) : (
                <ResultGrid
                    columns={['value']}
                    rows={(value.items ?? []).map((item) => [item])}
                />
            )}
        </div>
    );
}

function QueryConsole({ data, run }: { data: Data; run: Run }) {
    const [statement, setStatement] = useState('');
    const [allowWrites, setAllowWrites] = useState(false);
    const [busy, setBusy] = useState(false);
    const [result, setResult] = useState<DataResult | null>(null);

    const execute = async () => {
        if (statement.trim() === '' || busy) {
            return;
        }

        setBusy(true);
        setResult(await run({ command: 'query', statement, allowWrites }));
        setBusy(false);
    };

    return (
        <div className="flex flex-col gap-3">
            <Textarea
                value={statement}
                onChange={(event) => setStatement(event.target.value)}
                onKeyDown={(event) => {
                    if (
                        event.key === 'Enter' &&
                        (event.metaKey || event.ctrlKey)
                    ) {
                        event.preventDefault();
                        void execute();
                    }
                }}
                aria-label={DIALECT[data.dialect].statement}
                placeholder={DIALECT[data.dialect].placeholder}
                spellCheck={false}
                rows={data.dialect === 'redis' ? 2 : 6}
                className="resize-y bg-recessed font-mono text-[13px] leading-5"
            />
            <div className="flex flex-wrap items-center gap-3">
                {allowWrites ? (
                    <Badge variant="progress">Writes allowed</Badge>
                ) : (
                    <Badge variant="secondary">Read-only</Badge>
                )}
                {allowWrites ? (
                    <label className="flex items-center gap-2 type-small text-muted-foreground">
                        <Switch
                            checked
                            onCheckedChange={() => setAllowWrites(false)}
                            aria-label="Allow writes"
                        />
                        Allow writes
                    </label>
                ) : (
                    <ConfirmPopover
                        title="Allow writes?"
                        consequence={`Statements can change data in ${data.engineLabel} for the rest of this session. Each one is recorded.`}
                        confirmLabel="Allow writes"
                        onConfirm={() => setAllowWrites(true)}
                        align="start"
                    >
                        <button
                            type="button"
                            className="flex items-center gap-2 type-small text-muted-foreground"
                        >
                            <Switch
                                checked={false}
                                tabIndex={-1}
                                aria-hidden="true"
                                className="pointer-events-none"
                            />
                            Allow writes
                        </button>
                    </ConfirmPopover>
                )}
                <div className="ml-auto flex items-center gap-2">
                    <HistoryPopover
                        history={data.history}
                        onPick={setStatement}
                    />
                    <Button
                        size="sm"
                        onClick={() => void execute()}
                        disabled={busy || statement.trim() === ''}
                    >
                        <Play aria-hidden="true" />
                        Run
                        <kbd className="ml-1 hidden font-sans text-[11px] opacity-60 sm:inline">
                            ⌘↵
                        </kbd>
                    </Button>
                </div>
            </div>
            {busy && (
                <p className="type-small text-muted-foreground">Running…</p>
            )}
            {!busy && result && (
                <div className="flex flex-col gap-2">
                    <ResultLine result={result} />
                    {result.status === 'ok' && result.columns && (
                        <ResultGrid
                            columns={result.columns}
                            rows={result.rows ?? []}
                        />
                    )}
                    {result.status === 'ok' && result.reply !== undefined && (
                        <pre className="max-h-[min(60vh,560px)] overflow-auto rounded-xl border bg-recessed p-4 type-mono-small whitespace-pre-wrap">
                            {result.reply === null ? (
                                <NullChip />
                            ) : (
                                JSON.stringify(result.reply, null, 2)
                            )}
                        </pre>
                    )}
                </div>
            )}
        </div>
    );
}

function statusDot(status: App.Enums.Sessions.DatabaseQueryStatus): string {
    return status === 'ok'
        ? 'bg-status-ok'
        : status === 'sent'
          ? 'bg-status-idle'
          : 'bg-status-error';
}

function HistoryPopover({
    history,
    onPick,
}: {
    history: App.Data.Sessions.QueryHistoryItemData[];
    onPick: (s: string) => void;
}) {
    const [open, setOpen] = useState(false);

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button variant="ghost" size="sm">
                    <History aria-hidden="true" />
                    History
                </Button>
            </PopoverTrigger>
            <PopoverContent align="end" className="w-[min(28rem,90vw)] p-1.5">
                {history.length === 0 ? (
                    <p className="p-3 type-small text-muted-foreground">
                        Statements you run appear here.
                    </p>
                ) : (
                    <ul className="flex max-h-80 flex-col overflow-y-auto">
                        {history.map((item) => (
                            <li key={item.id}>
                                <button
                                    type="button"
                                    disabled={item.statement === null}
                                    onClick={() => {
                                        onPick(item.statement ?? '');
                                        setOpen(false);
                                    }}
                                    className="flex w-full items-start gap-2.5 rounded-md px-2.5 py-2 text-left hover:bg-hover disabled:opacity-50"
                                >
                                    <span
                                        aria-hidden="true"
                                        className={cn(
                                            'mt-1.5 size-1.5 shrink-0 rounded-full',
                                            statusDot(item.status),
                                        )}
                                    />
                                    <span className="min-w-0 flex-1 truncate type-mono-small">
                                        {item.statement ?? 'Unavailable'}
                                    </span>
                                    <span className="shrink-0 type-small text-faint">
                                        <TimeAgo value={item.createdAt} />
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
            </PopoverContent>
        </Popover>
    );
}

function HistoryList({
    history,
}: {
    history: App.Data.Sessions.QueryHistoryItemData[];
}) {
    if (history.length === 0) {
        return null;
    }

    return (
        <div className="flex flex-col gap-2">
            <p className="type-label">Your recent statements</p>
            <ul className="flex flex-col divide-y rounded-xl border bg-card">
                {history.slice(0, 5).map((item) => (
                    <li
                        key={item.id}
                        className="flex items-center gap-3 px-4 py-2.5"
                    >
                        <span
                            aria-hidden="true"
                            className={cn(
                                'size-1.5 shrink-0 rounded-full',
                                statusDot(item.status),
                            )}
                        />
                        <span className="min-w-0 flex-1 truncate type-mono-small">
                            {item.statement ?? 'Unavailable'}
                        </span>
                        {item.allowWrites && (
                            <Badge variant="outline">writes</Badge>
                        )}
                        <span className="shrink-0 type-small text-faint">
                            <TimeAgo value={item.createdAt} />
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}
