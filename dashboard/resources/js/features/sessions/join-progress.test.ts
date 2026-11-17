import {
    afterEach,
    beforeEach,
    describe,
    expect,
    test,
    vi,
} from 'vite-plus/test';
import { RequestFailed } from '@/lib/http';
import { initialProgress, joinFailure } from './join-progress';
import type { JoinProgress } from './join-progress';
import { SessionClient } from './session-client';

type Handler = (data?: unknown) => void;

const { FakePusher, authorize } = vi.hoisted(() => {
    /** A stand-in for pusher-js: tests drive the socket and the channel. */
    class FakePusher {
        static last: FakePusher | null = null;
        connectionHandlers = new Map<string, Handler>();
        channelHandlers = new Map<string, Handler>();
        disconnected = false;
        connection = {
            bind: (event: string, handler: Handler) =>
                this.connectionHandlers.set(event, handler),
        };

        constructor(
            public key: string,
            public options: {
                channelAuthorization: {
                    customHandler: (
                        params: { socketId: string },
                        callback: (error: Error | null, data: unknown) => void,
                    ) => void;
                };
            },
        ) {
            FakePusher.last = this;
        }

        subscribe() {
            return {
                bind: (event: string, handler: Handler) =>
                    this.channelHandlers.set(event, handler),
                trigger: () => true,
            };
        }

        disconnect() {
            this.disconnected = true;
        }

        /** The socket connected and pusher-js asks for the channel's auth. */
        connect(): Promise<unknown> {
            this.connectionHandlers.get('state_change')?.({
                current: 'connected',
            });

            return new Promise((resolve) =>
                this.options.channelAuthorization.customHandler(
                    { socketId: '1.2' },
                    (error, data) => {
                        if (error) {
                            this.channelHandlers.get(
                                'pusher:subscription_error',
                            )?.({
                                status: null,
                            });
                        }

                        resolve(data);
                    },
                ),
            );
        }
    }

    const authorize =
        vi.fn<
            (
                session: string,
                socketId: string,
                ticket: string,
                signal?: AbortSignal,
            ) => Promise<{ auth: string }>
        >();

    return { FakePusher, authorize };
});

vi.mock('pusher-js', () => ({ default: FakePusher }));
vi.mock('./api', () => ({
    authorizeChannel: (...args: Parameters<typeof authorize>) =>
        authorize(...args),
}));

const opened = {
    id: 'ses_1',
    channel: 'session.ses_1',
    ticket: 'lr_st_x',
    key: 'A'.repeat(43),
    replica: { serverName: 'web-1' },
} as unknown as App.Data.Sessions.OpenedSessionData;

const realtime = {
    key: 'k',
    host: 'localhost',
    port: 8080,
    scheme: 'http',
} as unknown as App.Data.Instance.RealtimeConfigData;

function progress(overrides: Partial<JoinProgress>): JoinProgress {
    return { ...initialProgress(), ...overrides };
}

describe('join failure messages', () => {
    test('name the step that didn’t finish', () => {
        expect(joinFailure(progress({}), 'web-1', 25)).toBe(
            'Your browser couldn’t reach the live connection.',
        );
        expect(
            joinFailure(
                progress({
                    socket: 'connected',
                    auth: 'failed',
                    authStatus: 403,
                }),
                'web-1',
                25,
            ),
        ).toBe(
            'Your browser couldn’t join the live connection. The dashboard answered with HTTP 403.',
        );
        expect(
            joinFailure(
                progress({ socket: 'connected', auth: 'timeout' }),
                'web-1',
                25,
            ),
        ).toBe(
            'Your browser couldn’t join the live connection. The dashboard didn’t authorize it within 10 seconds.',
        );
        expect(
            joinFailure(
                progress({ socket: 'connected', auth: 'ok' }),
                'web-1',
                25,
            ),
        ).toContain('the live connection didn’t confirm in time');
        expect(
            joinFailure(
                progress({ socket: 'connected', auth: 'ok', subscribed: true }),
                'web-1',
                25,
            ),
        ).toBe(
            'web-1 didn’t connect to the live connection within 25 seconds. The server may be offline, busy or unable to reach the dashboard.',
        );
        expect(
            joinFailure(
                progress({
                    socket: 'connected',
                    auth: 'ok',
                    subscribed: true,
                    serverJoined: true,
                }),
                'web-1',
                25,
            ),
        ).toContain('joined the live connection but didn’t start the session');
    });

    test('never call the server anything but the server', () => {
        const all = [
            progress({}),
            progress({ socket: 'connected', auth: 'pending' }),
            progress({ socket: 'connected', auth: 'ok', subscribed: true }),
        ].map((p) => joinFailure(p, 'web-1', 25));

        for (const message of all) {
            expect(message).not.toMatch(/daemon|agent|—/i);
        }
    });
});

describe('session client join', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.stubGlobal('window', globalThis);
        authorize.mockReset();
        FakePusher.last = null;
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        vi.useRealTimers();
    });

    async function start() {
        const failures: string[] = [];
        const client = new SessionClient(
            opened,
            { onJoinFailed: (message) => failures.push(message) },
            () => ({ t: 'hello' }),
        );
        await client.connect(realtime);
        const pusher = FakePusher.last;

        if (!pusher) {
            throw new Error('no pusher');
        }

        return { client, failures, pusher };
    }

    test('an authorization that never answers is given up and reported', async () => {
        let signal: AbortSignal | undefined;
        authorize.mockImplementation(
            (_s, _id, _t, abort) =>
                new Promise((_resolve, reject) => {
                    signal = abort;
                    abort?.addEventListener('abort', () =>
                        reject(new Error('canceled')),
                    );
                }),
        );
        const { client, failures, pusher } = await start();
        const joining = pusher.connect();

        expect(client.progress().auth).toBe('pending');
        await vi.advanceTimersByTimeAsync(10_000);
        await joining;

        expect(signal?.aborted).toBe(true);
        expect(client.progress().auth).toBe('timeout');
        expect(failures).toEqual([
            'Your browser couldn’t join the live connection. The dashboard didn’t authorize it within 10 seconds.',
        ]);
        expect(pusher.disconnected).toBe(true);
    });

    test('a refused authorization reports its HTTP status', async () => {
        authorize.mockRejectedValue(new RequestFailed('Expired', 403));
        const { failures, pusher } = await start();

        await pusher.connect();

        expect(failures).toEqual([
            'Your browser couldn’t join the live connection. The dashboard answered with HTTP 403.',
        ]);
    });

    test('a subscription that is never confirmed fails the browser’s side in time', async () => {
        authorize.mockResolvedValue({ auth: 'k:sig' });
        const { client, failures, pusher } = await start();

        await pusher.connect();
        expect(client.progress().auth).toBe('ok');
        await vi.advanceTimersByTimeAsync(15_000);

        expect(failures).toHaveLength(1);
        expect(failures[0]).toContain(
            'the live connection didn’t confirm in time',
        );
    });

    test('once subscribed, the browser’s deadline no longer applies', async () => {
        authorize.mockResolvedValue({ auth: 'k:sig' });
        const { client, failures, pusher } = await start();

        await pusher.connect();
        pusher.channelHandlers.get('pusher:subscription_succeeded')?.();
        await vi.advanceTimersByTimeAsync(30_000);

        expect(failures).toEqual([]);
        expect(client.progress()).toMatchObject({
            socket: 'connected',
            auth: 'ok',
            subscribed: true,
            serverJoined: false,
        });
        expect(
            joinFailure(client.progress(), 'web-1', 25).startsWith(
                'web-1 didn’t connect to the live connection',
            ),
        ).toBe(true);
    });
});
