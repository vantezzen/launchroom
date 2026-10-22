import { usePage } from '@inertiajs/react';
import { Circle, Radio, SquareTerminal } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { EmptyState } from '@/components/patterns/empty-state';
import { LiveWait } from '@/components/patterns/live-wait';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';
import { endOnUnload, openSession } from './api';
import type { ServiceRef } from './api';
import { clock, endSentence, shellLabel } from './format';
import { RecentSessions } from './recent-sessions';
import { SessionClient } from './session-client';
import { Terminal } from './terminal';
import type { TerminalHandle } from './terminal';
import { useTabData } from './use-tab-data';

type Data = App.Data.Sessions.ConsoleTabData;
type Opened = App.Data.Sessions.OpenedSessionData;

type Phase =
    | { kind: 'idle' }
    | { kind: 'connecting' }
    | { kind: 'live'; shell: string | null; since: number }
    | { kind: 'ended'; reason: string };

/** Show the idle hint this long before the idle timeout. */
const IDLE_HINT_SECONDS = 180;

/**
 * The Console tab (docs/08 §15): pick a replica and a shell, open a
 * terminal inside that container. The session ends when the tab, the panel
 * or the page goes away.
 */
export function ConsoleTab(ref: ServiceRef) {
    const query = useTabData<Data>(ref, 'console');
    const data = query.data;

    if (query.isPending) {
        return <Skeleton className="h-64 w-full rounded-xl" />;
    }

    if (!data) {
        return (
            <EmptyState icon={SquareTerminal} title="Console unavailable">
                {query.error instanceof Error
                    ? query.error.message
                    : 'Try again in a moment.'}
            </EmptyState>
        );
    }

    return (
        <ConsoleView
            serviceRef={ref}
            data={data}
            refresh={() => void query.refetch()}
        />
    );
}

function ConsoleView({
    serviceRef,
    data,
    refresh,
}: {
    serviceRef: ServiceRef;
    data: Data;
    refresh: () => void;
}) {
    const realtime = usePage().props.realtime;
    const [workload, setWorkload] = useState<string | null>(
        data.replicas[0]?.workload ?? null,
    );
    const [shell, setShell] = useState('');
    const [phase, setPhase] = useState<Phase>({ kind: 'idle' });
    const [opened, setOpened] = useState<Opened | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [now, setNow] = useState(() => Date.now());
    const lastInput = useRef(Date.now());
    const terminal = useRef<TerminalHandle>(null);
    const client = useRef<SessionClient | null>(null);
    const openedRef = useRef<Opened | null>(null);
    const [attempt, setAttempt] = useState(0);
    // The attempt a session was requested for: mounting twice (dev
    // StrictMode) must not open two sessions.
    const requested = useRef(-1);
    const replica =
        data.replicas.find((r) => r.workload === workload) ??
        data.replicas[0] ??
        null;

    const stop = useCallback(() => {
        const current = client.current;
        client.current = null;

        if (current) {
            endOnUnload(openedRef.current?.id ?? '');
            current.close();
        }
    }, []);

    // End the session with the tab, the panel or the page.
    useEffect(() => {
        window.addEventListener('pagehide', stop);

        return () => {
            window.removeEventListener('pagehide', stop);
            stop();
        };
    }, [stop]);

    useEffect(() => {
        if (phase.kind !== 'live') {
            return;
        }

        const timer = window.setInterval(() => setNow(Date.now()), 1000);

        return () => window.clearInterval(timer);
    }, [phase.kind]);

    const connect = useCallback(
        async (cols: number, rows: number) => {
            setError(null);

            try {
                const session = await openSession(serviceRef, 'console', {
                    workload: replica?.workload ?? null,
                    shell: shell === '' ? null : shell,
                    cols,
                    rows,
                });
                openedRef.current = session;
                setOpened(session);
                lastInput.current = Date.now();

                const live = new SessionClient(
                    session,
                    {
                        onStarted: (started) => {
                            setPhase({
                                kind: 'live',
                                shell: started ?? session.shell,
                                since: Date.now(),
                            });
                            terminal.current?.focus();
                        },
                        onOutput: (bytes) => terminal.current?.write(bytes),
                        onExit: (code) =>
                            terminal.current?.write(
                                `\r\n\x1b[90m[exited${code === null ? '' : ` with code ${code}`}]\x1b[0m\r\n`,
                            ),
                        onEnd: (reason) => {
                            client.current = null;
                            setPhase({
                                kind: 'ended',
                                reason: endSentence(reason),
                            });
                            refresh();
                        },
                    },
                    () => ({
                        t: 'hello',
                        ...(terminal.current?.size() ?? { cols, rows }),
                    }),
                );
                client.current = live;
                await live.connect(realtime);
            } catch (failure) {
                const message =
                    failure instanceof Error
                        ? failure.message
                        : 'The console couldn’t be opened.';

                setError(message);
                setPhase({ kind: 'idle' });
            }
        },
        [replica?.workload, shell, serviceRef, realtime, refresh],
    );

    const end = () => {
        stop();
        setPhase({ kind: 'ended', reason: 'You ended the session.' });
        refresh();
        // The server closes it within a second or two; show that in the list.
        window.setTimeout(refresh, 2500);
    };

    const elapsed = phase.kind === 'live' ? (now - phase.since) / 1000 : 0;
    const idleLeft = opened
        ? opened.idleSeconds - (now - lastInput.current) / 1000
        : Infinity;
    const showTerminal =
        phase.kind === 'connecting' ||
        phase.kind === 'live' ||
        phase.kind === 'ended';

    return (
        <div className="flex flex-col gap-8">
            {phase.kind === 'idle' && (
                <div className="flex flex-col gap-4">
                    {!data.available ? (
                        <EmptyState
                            icon={SquareTerminal}
                            title="No console right now"
                            variant="framed"
                        >
                            {data.unavailableReason}
                        </EmptyState>
                    ) : (
                        <div className="flex flex-col gap-4 rounded-xl border bg-card p-5 shadow-card">
                            <div className="flex flex-col gap-1">
                                <p className="type-heading">Open a console</p>
                                <p className="type-small text-muted-foreground">
                                    A shell inside one replica’s container, as
                                    the container’s own user. It ends after{' '}
                                    {data.idleMinutes} minutes without input, at
                                    most after {data.maxMinutes} minutes.
                                </p>
                            </div>
                            <ReplicaPicker
                                replicas={data.replicas}
                                value={replica?.workload ?? null}
                                onChange={setWorkload}
                            />
                            <div className="flex flex-wrap items-center gap-3">
                                <Select
                                    value={shell === '' ? 'auto' : shell}
                                    onValueChange={(value) =>
                                        setShell(value === 'auto' ? '' : value)
                                    }
                                >
                                    <SelectTrigger
                                        className="w-40"
                                        size="sm"
                                        aria-label="Shell"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {data.shells.map((option) => (
                                            <SelectItem
                                                key={option || 'auto'}
                                                value={
                                                    option === ''
                                                        ? 'auto'
                                                        : option
                                                }
                                            >
                                                {shellLabel(option)}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <Button
                                    onClick={() => {
                                        setAttempt((n) => n + 1);
                                        setPhase({ kind: 'connecting' });
                                    }}
                                >
                                    <SquareTerminal aria-hidden="true" />
                                    Open console
                                </Button>
                                {data.record && (
                                    <span className="flex items-center gap-1.5 type-small text-muted-foreground">
                                        <Radio
                                            aria-hidden="true"
                                            className="size-3.5"
                                        />
                                        This session is recorded
                                    </span>
                                )}
                            </div>
                            {error && (
                                <p className="type-small text-status-error">
                                    {error}
                                </p>
                            )}
                        </div>
                    )}
                </div>
            )}

            {showTerminal && (
                <div className="flex flex-col gap-2.5">
                    <div className="relative">
                        <Terminal
                            key={attempt}
                            ref={terminal}
                            className={cn(
                                'h-[min(60vh,520px)]',
                                phase.kind === 'connecting' && 'invisible',
                            )}
                            disabled={phase.kind !== 'live'}
                            onMounted={(cols, rows) => {
                                if (
                                    phase.kind === 'connecting' &&
                                    requested.current !== attempt
                                ) {
                                    requested.current = attempt;
                                    void connect(cols, rows);
                                }
                            }}
                            onData={(text) => {
                                lastInput.current = Date.now();
                                client.current?.input(text);
                            }}
                            onResize={(cols, rows) =>
                                client.current?.resize(cols, rows)
                            }
                        />
                        {phase.kind === 'connecting' && (
                            <LiveWait
                                className="absolute inset-0 justify-center"
                                status={`Connecting to ${replica?.label.toLowerCase() ?? 'the replica'}${replica ? ` on ${replica.serverName}` : ''}…`}
                                hint="Usually a second or two."
                                timeoutSeconds={20}
                                fallback={
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={end}
                                    >
                                        Cancel
                                    </Button>
                                }
                            />
                        )}
                    </div>
                    {phase.kind === 'live' && (
                        <div className="flex flex-wrap items-center gap-x-3 gap-y-1 type-small text-muted-foreground">
                            <span className="flex items-center gap-1.5 text-foreground">
                                <Circle
                                    aria-hidden="true"
                                    className="size-2 fill-status-ok text-status-ok"
                                />
                                Connected
                            </span>
                            <span>
                                {shellLabel(phase.shell)} on{' '}
                                {opened?.replica.label.toLowerCase()} ·{' '}
                                {opened?.replica.serverName}
                            </span>
                            <span className="tabular-nums">
                                {clock(elapsed)}
                            </span>
                            {idleLeft < IDLE_HINT_SECONDS && (
                                <span className="text-status-progress">
                                    Ends in{' '}
                                    {Math.max(1, Math.ceil(idleLeft / 60))} min
                                    without input
                                </span>
                            )}
                            <Button
                                variant="ghost"
                                size="sm"
                                className="ml-auto"
                                onClick={end}
                            >
                                End session
                            </Button>
                        </div>
                    )}
                    {phase.kind === 'ended' && (
                        <div className="flex flex-wrap items-center gap-x-3 gap-y-2 type-small text-muted-foreground">
                            <span className="flex items-center gap-1.5 text-foreground">
                                <span
                                    aria-hidden="true"
                                    className="size-2 rounded-full border border-status-idle"
                                />
                                Session ended
                            </span>
                            <span>{phase.reason}</span>
                            <Button
                                variant="outline"
                                size="sm"
                                className="ml-auto"
                                onClick={() => {
                                    setOpened(null);
                                    setPhase({ kind: 'idle' });
                                }}
                            >
                                <SquareTerminal aria-hidden="true" />
                                Open a new console
                            </Button>
                        </div>
                    )}
                </div>
            )}

            <RecentSessions sessions={data.sessions} onChanged={refresh} />
        </div>
    );
}

function ReplicaPicker({
    replicas,
    value,
    onChange,
}: {
    replicas: App.Data.Sessions.SessionReplicaData[];
    value: string | null;
    onChange: (workload: string) => void;
}) {
    if (replicas.length <= 1) {
        const only = replicas[0];

        return only ? (
            <p className="type-small text-muted-foreground">
                {only.label} on{' '}
                <span className="text-foreground">{only.serverName}</span>
            </p>
        ) : null;
    }

    return (
        <div
            role="radiogroup"
            aria-label="Replica"
            className="flex flex-wrap gap-2"
        >
            {replicas.map((replica) => {
                const selected = replica.workload === value;

                return (
                    <button
                        key={replica.workload}
                        type="button"
                        role="radio"
                        aria-checked={selected}
                        onClick={() => onChange(replica.workload)}
                        className={cn(
                            'flex items-center gap-2 rounded-md border px-3 py-1.5 type-small transition-colors duration-fast focus-visible:ring-2 focus-visible:ring-signal focus-visible:outline-none',
                            selected
                                ? 'border-foreground bg-background text-foreground'
                                : 'border-border text-muted-foreground hover:text-foreground',
                        )}
                    >
                        <span className="font-medium">{replica.label}</span>
                        <span className="text-faint">·</span>
                        <span>{replica.serverName}</span>
                        {replica.phase === 'unhealthy' && (
                            <span className="text-status-progress">
                                unhealthy
                            </span>
                        )}
                    </button>
                );
            })}
        </div>
    );
}
