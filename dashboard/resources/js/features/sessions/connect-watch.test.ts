import { describe, expect, test } from 'vite-plus/test';
import { watchConnect } from './connect-watch';
import type { ConnectWatchDeps } from './connect-watch';
import { serverEndReason } from './session-client';

type Item = App.Data.Sessions.SessionHistoryItemData;

function item(overrides: Partial<Item>): Item {
    return {
        id: 'ses_1',
        kind: 'console',
        status: 'pending',
        userName: 'Ada',
        isMine: true,
        replicaLabel: 'Replica 1',
        serverName: 'control plane',
        shell: null,
        openedAt: '2026-10-25T08:00:00Z',
        endedAt: null,
        durationSeconds: null,
        endReason: null,
        exitCode: null,
        canEnd: true,
        hasRecording: false,
        failure: null,
        ...overrides,
    } as Item;
}

/** Fake timers: run() fires due timers in order of their due time. */
function fakeDeps(answers: Item[]) {
    let now = 0;
    let seq = 0;
    const timers = new Map<number, { at: number; callback: () => void }>();
    const ended: string[] = [];
    const deps: ConnectWatchDeps = {
        fetch: async () =>
            answers.shift() ?? answers[answers.length - 1] ?? item({}),
        end: async (id) => {
            ended.push(id);
        },
        setTimer: (callback, ms) => {
            timers.set(++seq, { at: now + ms, callback });

            return seq;
        },
        clearTimer: (timer) => {
            timers.delete(timer);
        },
    };
    const advance = async (ms: number) => {
        const until = now + ms;

        for (;;) {
            const next = [...timers.entries()]
                .filter(([, t]) => t.at <= until)
                .sort((a, b) => a[1].at - b[1].at)[0];

            if (!next) {
                break;
            }

            timers.delete(next[0]);
            now = next[1].at;
            next[1].callback();
            // Let the awaited fetch settle.
            await Promise.resolve();
            await Promise.resolve();
            await Promise.resolve();
        }

        now = until;
    };

    return { deps, advance, ended, timers };
}

describe('connect watch', () => {
    test('a session the server failed to start reports its reason', async () => {
        const fake = fakeDeps([
            item({ status: 'pending' }),
            item({
                status: 'ended',
                endReason: 'Something went wrong',
                failure:
                    'This image has no shell (looked for bash, ash and sh), so there is no console for it.',
            }),
        ]);
        const failures: string[] = [];
        watchConnect(
            'ses_1',
            'control plane',
            (m) => failures.push(m),
            fake.deps,
        );

        await fake.advance(2_000);
        expect(failures).toEqual([]);
        await fake.advance(2_000);
        expect(failures).toEqual([
            'This image has no shell (looked for bash, ash and sh), so there is no console for it.',
        ]);
        expect(fake.timers.size).toBe(0);
    });

    test('a server that never answers ends the session after the timeout', async () => {
        const fake = fakeDeps([item({ status: 'pending' })]);
        const failures: string[] = [];
        watchConnect(
            'ses_1',
            'control plane',
            (m) => failures.push(m),
            fake.deps,
            10_000,
        );

        await fake.advance(10_000);
        expect(fake.ended).toEqual(['ses_1']);
        expect(failures).toHaveLength(1);
        expect(failures[0]).toContain(
            'control plane didn’t answer within 10 seconds',
        );
        expect(fake.timers.size).toBe(0);
    });

    test('stopping once live cancels everything (reconnects don’t fire old failures)', async () => {
        const fake = fakeDeps([item({ status: 'ended', endReason: 'Closed' })]);
        const failures: string[] = [];
        const stop = watchConnect(
            'ses_1',
            'x',
            (m) => failures.push(m),
            fake.deps,
        );

        stop();
        await fake.advance(60_000);
        expect(failures).toEqual([]);
        expect(fake.ended).toEqual([]);
    });
});

describe('session.end server event', () => {
    test('reads the reason from the signed payload', () => {
        const payload = JSON.stringify({
            command: 'end',
            reason: 'Replaced by a new session.',
        });

        expect(serverEndReason({ payload, signature: 'x' })).toBe(
            'Replaced by a new session.',
        );
        expect(
            serverEndReason(JSON.stringify({ payload, signature: 'x' })),
        ).toBe('Replaced by a new session.');
        expect(serverEndReason({})).toBeUndefined();
        expect(serverEndReason('not json')).toBeUndefined();
    });
});
