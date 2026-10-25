/**
 * Session frames (docs/03 §11.2): the payload `p` is a JSON string, `m` is
 * base64url(HMAC-SHA256(K, "launchroom-session-v1\n" + dir + "\n" +
 * session + "\n" + p)). The browser signs `b2d` frames and accepts only
 * `d2b` frames whose MAC verifies and whose sequence number grows.
 */

import { hmacSha256 } from './hmac';
import type { Hmac } from './hmac';

export const FRAME_EVENT = 'client-frame';
/** Raw bytes per `in`/`out` frame. */
export const MAX_DATA = 4096;

const MAC_PREFIX = 'launchroom-session-v1\n';
const encoder = new TextEncoder();
const decoder = new TextDecoder();

export type WireFrame = { p: string; m: string };

/** What the browser sends. */
export type OutgoingPayload =
    | { t: 'hello'; cols?: number; rows?: number }
    | { t: 'in'; d: string }
    | { t: 'resize'; cols: number; rows: number }
    | { t: 'ping' }
    | { t: 'bye' };

/** What launchroomd sends (fields are omitted when zero). */
export type IncomingPayload = {
    s: number;
    /** ready · started · out · exit · result · end · pong (others are ignored). */
    t: string;
    d?: string;
    kind?: string;
    shell?: string;
    code?: number;
    op?: string;
    part?: number;
    parts?: number;
    reason?: string;
    /** Why a session couldn't start (end frames). */
    message?: string;
};

export function base64UrlEncode(bytes: Uint8Array): string {
    let binary = '';

    for (let i = 0; i < bytes.length; i += 0x8000) {
        binary += String.fromCharCode(...bytes.subarray(i, i + 0x8000));
    }

    return btoa(binary)
        .replaceAll('+', '-')
        .replaceAll('/', '_')
        .replace(/=+$/, '');
}

export function base64UrlDecode(value: string): Uint8Array<ArrayBuffer> {
    const base64 = value.replaceAll('-', '+').replaceAll('_', '/');
    const binary = atob(base64 + '='.repeat((4 - (base64.length % 4)) % 4));
    const bytes = new Uint8Array(binary.length);

    for (let i = 0; i < binary.length; i++) {
        bytes[i] = binary.charCodeAt(i);
    }

    return bytes;
}

export function utf8(bytes: Uint8Array): string {
    return decoder.decode(bytes);
}

/** Splits text into base64url chunks of at most MAX_DATA bytes. */
export function encodeInput(text: string): string[] {
    const bytes = encoder.encode(text);
    const chunks: string[] = [];

    for (let i = 0; i < bytes.length; i += MAX_DATA) {
        chunks.push(base64UrlEncode(bytes.subarray(i, i + MAX_DATA)));
    }

    return chunks;
}

/**
 * Signs outgoing and verifies incoming frames of one session.
 */
export class FrameCodec {
    private sent = 0;
    private received = 0;

    private constructor(
        private readonly key: Hmac,
        private readonly session: string,
    ) {}

    static async create(
        sessionKey: string,
        session: string,
    ): Promise<FrameCodec> {
        const key = await hmacSha256(base64UrlDecode(sessionKey));

        return new FrameCodec(key, session);
    }

    private input(dir: 'b2d' | 'd2b', p: string): Uint8Array<ArrayBuffer> {
        return encoder.encode(`${MAC_PREFIX}${dir}\n${this.session}\n${p}`);
    }

    async seal(payload: OutgoingPayload): Promise<WireFrame> {
        this.sent += 1;
        const p = JSON.stringify({ s: this.sent, ...payload });
        const mac = await this.key.sign(this.input('b2d', p));

        return { p, m: base64UrlEncode(mac) };
    }

    /** The payload, or null when the frame is forged, replayed or malformed. */
    async open(frame: unknown): Promise<IncomingPayload | null> {
        if (
            typeof frame !== 'object' ||
            frame === null ||
            typeof (frame as WireFrame).p !== 'string' ||
            typeof (frame as WireFrame).m !== 'string' ||
            (frame as WireFrame).p.length > 8192
        ) {
            return null;
        }

        const { p, m } = frame as WireFrame;
        let signature: Uint8Array<ArrayBuffer>;

        try {
            signature = base64UrlDecode(m);
        } catch {
            return null;
        }

        const valid = await this.key.verify(signature, this.input('d2b', p));

        if (!valid) {
            return null;
        }

        let payload: IncomingPayload;

        try {
            payload = JSON.parse(p) as IncomingPayload;
        } catch {
            return null;
        }

        if (
            typeof payload.s !== 'number' ||
            typeof payload.t !== 'string' ||
            payload.s <= this.received
        ) {
            return null;
        }

        this.received = payload.s;

        return payload;
    }
}
