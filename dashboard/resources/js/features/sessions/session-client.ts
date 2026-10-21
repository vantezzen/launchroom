import Pusher from 'pusher-js';
import type { Channel } from 'pusher-js';
import { authorizeChannel } from './api';
import {
    base64UrlDecode,
    encodeInput,
    FRAME_EVENT,
    FrameCodec,
    utf8,
} from './frames';
import type { IncomingPayload, OutgoingPayload } from './frames';

type Realtime = App.Data.Instance.RealtimeConfigData | null | undefined;

export type SessionEvents = {
    /** launchroomd is on the channel (the hello went out). */
    onReady?: () => void;
    onStarted?: (shell: string | null) => void;
    onOutput?: (bytes: Uint8Array) => void;
    onExit?: (code: number | null) => void;
    /** A data result, reassembled from its parts. */
    onResult?: (op: string, result: unknown) => void;
    /** The session is over: launchroomd said why, or the connection is gone. */
    onEnd?: (reason: string) => void;
};

const PING_MS = 15_000;

function pusherFor(
    realtime: Realtime,
    authorize: (socketId: string) => Promise<string>,
): Pusher | null {
    const key = realtime?.key || import.meta.env.VITE_REVERB_APP_KEY;

    if (!key) {
        return null;
    }

    const scheme =
        realtime?.scheme ||
        import.meta.env.VITE_REVERB_SCHEME ||
        window.location.protocol.replace(':', '');
    const tls = scheme === 'https';
    const port = Number(
        realtime?.port ||
            import.meta.env.VITE_REVERB_PORT ||
            window.location.port ||
            (tls ? 443 : 80),
    );

    return new Pusher(key, {
        cluster: '',
        wsHost:
            realtime?.host ||
            import.meta.env.VITE_REVERB_HOST ||
            window.location.hostname,
        wsPort: port,
        wssPort: port,
        forceTLS: tls,
        enabledTransports: ['ws', 'wss'],
        channelAuthorization: {
            endpoint: '',
            transport: 'ajax',
            customHandler: (params, callback) => {
                authorize(params.socketId).then(
                    (auth) => callback(null, { auth }),
                    (error: unknown) =>
                        callback(
                            error instanceof Error
                                ? error
                                : new Error('Not authorized'),
                            null,
                        ),
                );
            },
        },
    });
}

/**
 * One interactive session's live connection (docs/03 §11.2): its own
 * Pusher client on `private-session.<ses>`, joined once with the ticket.
 * Frames are signed and verified by FrameCodec; processing is serialized
 * so sequence numbers are checked in order.
 */
export class SessionClient {
    private pusher: Pusher | null = null;
    private channel: Channel | null = null;
    private codec: FrameCodec | null = null;
    private sending: Promise<unknown> = Promise.resolve();
    private receiving: Promise<unknown> = Promise.resolve();
    private ping: number | null = null;
    private greeted = false;
    private closed = false;
    private parts = new Map<
        string,
        { chunks: (string | undefined)[]; got: number }
    >();

    constructor(
        private readonly opened: App.Data.Sessions.OpenedSessionData,
        private readonly events: SessionEvents,
        private readonly hello: () => OutgoingPayload,
    ) {}

    async connect(realtime: Realtime): Promise<void> {
        this.codec = await FrameCodec.create(this.opened.key, this.opened.id);
        let authorized = false;

        this.pusher = pusherFor(realtime, async (socketId) => {
            if (authorized) {
                // Tickets are single use: a reconnect can't rejoin this session.
                throw new Error('This session can’t be rejoined.');
            }

            authorized = true;

            return (
                await authorizeChannel(
                    this.opened.id,
                    socketId,
                    this.opened.ticket,
                )
            ).auth;
        });

        if (!this.pusher) {
            this.finish('Live sessions need realtime updates, which are off.');

            return;
        }

        this.pusher.connection.bind(
            'state_change',
            (states: { current: string }) => {
                if (
                    authorized &&
                    ['disconnected', 'unavailable', 'failed'].includes(
                        states.current,
                    )
                ) {
                    this.finish('The live connection was lost.');
                }
            },
        );

        this.channel = this.pusher.subscribe(`private-${this.opened.channel}`);
        this.channel.bind('pusher:subscription_error', () =>
            this.finish('Couldn’t join the session. Open a new one.'),
        );
        this.channel.bind(FRAME_EVENT, (data: unknown) => {
            this.receiving = this.receiving.then(() => this.receive(data));
        });
        this.ping = window.setInterval(() => this.send({ t: 'ping' }), PING_MS);
    }

    send(payload: OutgoingPayload): void {
        if (this.closed || !this.codec || !this.channel) {
            return;
        }

        const codec = this.codec;
        const channel = this.channel;
        this.sending = this.sending
            .then(() => codec.seal(payload))
            .then((frame) => channel.trigger(FRAME_EVENT, frame))
            .catch(() => undefined);
    }

    input(text: string): void {
        for (const chunk of encodeInput(text)) {
            this.send({ t: 'in', d: chunk });
        }
    }

    resize(cols: number, rows: number): void {
        this.send({ t: 'resize', cols, rows });
    }

    /** Says goodbye and disconnects (the dashboard is told separately). */
    close(): void {
        if (this.closed) {
            return;
        }

        this.send({ t: 'bye' });
        void this.sending.finally(() => this.teardown());
        this.closed = true;
    }

    private async receive(data: unknown): Promise<void> {
        const payload = await this.codec?.open(data);

        if (!payload || this.closed) {
            return;
        }

        this.handle(payload);
    }

    private handle(payload: IncomingPayload): void {
        switch (payload.t) {
            case 'ready':
                if (!this.greeted) {
                    this.greeted = true;
                    this.send(this.hello());
                    this.events.onReady?.();
                }

                break;
            case 'started':
                this.events.onStarted?.(payload.shell ?? null);
                break;
            case 'out':
                if (payload.d) {
                    this.events.onOutput?.(base64UrlDecode(payload.d));
                }

                break;
            case 'exit':
                this.events.onExit?.(payload.code ?? null);
                break;
            case 'result':
                this.collect(payload);
                break;
            case 'end':
                this.finish(payload.reason ?? 'The session ended.');
                break;
            default:
                break;
        }
    }

    private collect(payload: IncomingPayload): void {
        const op = payload.op;
        const parts = payload.parts ?? 1;
        const part = payload.part ?? 0;

        if (!op || part < 0 || part >= parts || parts > 512) {
            return;
        }

        const entry = this.parts.get(op) ?? {
            chunks: Array.from<string | undefined>({ length: parts }),
            got: 0,
        };

        if (entry.chunks.length !== parts) {
            return;
        }

        if (entry.chunks[part] === undefined) {
            entry.chunks[part] = payload.d ?? '';
            entry.got += 1;
        }

        this.parts.set(op, entry);

        if (entry.got < parts) {
            return;
        }

        const chunks = entry.chunks.map((chunk) => chunk ?? '');
        this.parts.delete(op);

        try {
            const bytes = chunks.map(base64UrlDecode);
            const total = new Uint8Array(
                bytes.reduce((sum, b) => sum + b.length, 0),
            );
            let offset = 0;

            for (const b of bytes) {
                total.set(b, offset);
                offset += b.length;
            }

            this.events.onResult?.(op, JSON.parse(utf8(total)));
        } catch {
            this.events.onResult?.(op, {
                op,
                status: 'error',
                message: 'The result couldn’t be read.',
                durationMs: 0,
            });
        }
    }

    private finish(reason: string): void {
        if (this.closed) {
            return;
        }

        this.closed = true;
        this.teardown();
        this.events.onEnd?.(reason);
    }

    private teardown(): void {
        if (this.ping !== null) {
            window.clearInterval(this.ping);
            this.ping = null;
        }

        this.pusher?.disconnect();
        this.pusher = null;
        this.channel = null;
    }
}
