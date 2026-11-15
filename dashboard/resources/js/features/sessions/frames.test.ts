import { readFileSync } from 'node:fs';
import { expect, test } from 'vite-plus/test';
import type { OutgoingPayload } from './frames';
import {
    encodeInput,
    FrameCodec,
    MAC_PREFIX,
    MAX_DATA,
    MAX_PAYLOAD,
} from './frames';

type Frame = { p: string; m: string };

// Shared with launchroomd's session test (daemon/internal/session).
const fixture = JSON.parse(
    readFileSync(
        new URL(
            '../../../../../protocol/fixtures/session/frames.json',
            import.meta.url,
        ),
        'utf8',
    ),
) as {
    macPrefix: string;
    maxPayload: number;
    maxData: number;
    key: string;
    session: string;
    browserFrames: (Frame & { payload: OutgoingPayload })[];
    daemonFrames: Frame[];
    refusedAfterDaemonFrames: Frame[];
};

test('constants match launchroomd', () => {
    expect(MAC_PREFIX).toBe(fixture.macPrefix);
    expect(MAX_PAYLOAD).toBe(fixture.maxPayload);
    expect(MAX_DATA).toBe(fixture.maxData);
    expect(encodeInput('a'.repeat(MAX_DATA + 1))).toHaveLength(2);
});

test('the browser seals the frames launchroomd verifies', async () => {
    const codec = await FrameCodec.create(fixture.key, fixture.session);

    for (const frame of fixture.browserFrames) {
        expect(await codec.seal(frame.payload)).toEqual({
            p: frame.p,
            m: frame.m,
        });
    }
});

test('the browser opens launchroomd frames and drops forged or replayed ones', async () => {
    const codec = await FrameCodec.create(fixture.key, fixture.session);

    for (const frame of fixture.daemonFrames) {
        expect(await codec.open(frame)).toEqual(JSON.parse(frame.p));
    }

    for (const frame of fixture.refusedAfterDaemonFrames) {
        expect(await codec.open(frame)).toBeNull();
    }
});
