import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { endOnUnload, openSession, sendCommand } from './api';
import type { ServiceRef } from './api';
import { watchConnect } from './connect-watch';
import { endSentence } from './format';
import type { DataResult } from './result-types';
import { SessionClient } from './session-client';

export type DataPhase =
    | { kind: 'idle'; error: string | null }
    | { kind: 'connecting' }
    | { kind: 'live' }
    | { kind: 'ended'; reason: string };

type Waiter = { resolve: (result: DataResult) => void; timer: number };

const RESULT_TIMEOUT_MS = 30_000;

/**
 * A Data tab session: open, join
 * the channel, run commands and wait for their results by op id. Results
 * can arrive before the POST that sent the command returns, so early ones
 * are kept until someone asks.
 */
export function useDataSession(ref: ServiceRef, onEnded: () => void) {
    const realtime = usePage().props.realtime;
    const [phase, setPhase] = useState<DataPhase>({
        kind: 'idle',
        error: null,
    });
    const client = useRef<SessionClient | null>(null);
    const sessionId = useRef<string | null>(null);
    const waiters = useRef(new Map<string, Waiter>());
    const early = useRef(new Map<string, DataResult>());
    const unwatch = useRef<(() => void) | null>(null);
    // Each open() bumps it: late events of an earlier attempt are ignored.
    const attempt = useRef(0);

    const settle = useCallback((op: string, result: DataResult) => {
        const waiter = waiters.current.get(op);

        if (waiter) {
            window.clearTimeout(waiter.timer);
            waiters.current.delete(op);
            waiter.resolve(result);
        } else {
            early.current.set(op, result);
        }
    }, []);

    const failAll = useCallback((message: string) => {
        for (const [op, waiter] of waiters.current) {
            window.clearTimeout(waiter.timer);
            waiter.resolve({ op, status: 'error', message, durationMs: 0 });
        }

        waiters.current.clear();
    }, []);

    const stop = useCallback(() => {
        const current = client.current;
        client.current = null;
        unwatch.current?.();
        unwatch.current = null;

        if (current && sessionId.current) {
            endOnUnload(sessionId.current);
            current.close();
        }
    }, []);

    useEffect(() => {
        window.addEventListener('pagehide', stop);

        return () => {
            window.removeEventListener('pagehide', stop);
            // A session still being opened ends as soon as it arrives.
            attempt.current++;
            stop();
        };
    }, [stop]);

    const open = useCallback(async (): Promise<void> => {
        // A reconnect replaces the previous session (the server ends it too).
        stop();
        failAll('The session ended.');
        const mine = ++attempt.current;
        const current = () => attempt.current === mine;
        setPhase({ kind: 'connecting' });

        try {
            const session = await openSession(ref, 'data', {
                cols: 80,
                rows: 24,
            });

            if (!current()) {
                endOnUnload(session.id);

                return;
            }

            sessionId.current = session.id;
            const live = new SessionClient(
                session,
                {
                    onReady: () => {
                        unwatch.current?.();
                        unwatch.current = null;

                        if (current()) {
                            setPhase({ kind: 'live' });
                        }
                    },
                    onResult: (op, result) => settle(op, result as DataResult),
                    onEnd: (reason, message) => {
                        unwatch.current?.();
                        unwatch.current = null;

                        if (!current()) {
                            return;
                        }

                        client.current = null;
                        failAll('The session ended.');
                        setPhase({
                            kind: 'ended',
                            reason: message ?? endSentence(reason),
                        });
                        onEnded();
                    },
                },
                () => ({ t: 'hello' }),
            );
            client.current = live;
            unwatch.current = watchConnect(
                session.id,
                session.replica.serverName,
                (message) => {
                    if (!current()) {
                        return;
                    }

                    client.current = null;
                    live.close();
                    setPhase({ kind: 'idle', error: message });
                    onEnded();
                },
            );
            await live.connect(realtime);
        } catch (failure) {
            if (!current()) {
                return;
            }

            const message =
                failure instanceof Error
                    ? failure.message
                    : 'The data session couldn’t be opened.';

            setPhase({ kind: 'idle', error: message });
        }
    }, [ref, realtime, settle, failAll, onEnded, stop]);

    const run = useCallback(
        async (command: Record<string, unknown>): Promise<DataResult> => {
            if (!sessionId.current || !client.current) {
                return {
                    op: '',
                    status: 'error',
                    message: 'Not connected.',
                    durationMs: 0,
                };
            }

            let op: string;

            try {
                op = (await sendCommand(sessionId.current, command)).op;
            } catch (failure) {
                return {
                    op: '',
                    status: 'error',
                    message:
                        failure instanceof Error
                            ? failure.message
                            : 'The command couldn’t be sent.',
                    durationMs: 0,
                };
            }

            const done = early.current.get(op);

            if (done) {
                early.current.delete(op);

                return done;
            }

            return new Promise<DataResult>((resolve) => {
                const timer = window.setTimeout(() => {
                    waiters.current.delete(op);
                    resolve({
                        op,
                        status: 'error',
                        message: 'No answer from the server in time.',
                        durationMs: 0,
                    });
                }, RESULT_TIMEOUT_MS);
                waiters.current.set(op, { resolve, timer });
            });
        },
        [],
    );

    const end = useCallback(() => {
        stop();
        failAll('The session ended.');
        setPhase({ kind: 'ended', reason: 'You ended the session.' });
        onEnded();
    }, [stop, failAll, onEnded]);

    return { phase, setPhase, open, run, end };
}
