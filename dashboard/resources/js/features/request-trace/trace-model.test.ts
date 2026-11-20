import { describe, expect, test } from 'vite-plus/test';
import { TRACE_FIXTURES } from './fixtures';
import {
    firewallWords,
    normaliseTrace,
    timing,
    tracePath,
    ttfb,
} from './trace-model';
import { dotLegs } from './trace-path';

const kinds = (key: keyof typeof TRACE_FIXTURES) =>
    tracePath(TRACE_FIXTURES[key]).nodes.map((node) => node.kind);

describe('tracePath', () => {
    test('puts the firewall on its own card and the app server on the service', () => {
        const path = tracePath(TRACE_FIXTURES.viaOtherServer);
        const service = path.nodes[3];

        expect(kinds('viaOtherServer')).toEqual([
            'visitor',
            'edge',
            'firewall',
            'service',
        ]);
        expect(service.kind === 'service' && service.runsOn?.name).toBe(
            'studio',
        );
        expect(path.reached).toBe(3);
        expect(path.end).toBe(3);
        expect(path.hops.map((hop) => hop.state)).toEqual([
            'travelled',
            'travelled',
            'travelled',
        ]);
        // Times are on the cards and in the TTFB bar, not on the lines.
        expect(path.hops.map((hop) => hop.pill)).toEqual([null, null, null]);
        expect(path.answers[3]).toMatchObject({
            words: '200 OK',
            state: 'active',
            note: null,
        });
    });

    test('runs on no other server when the edge runs the app', () => {
        const path = tracePath(TRACE_FIXTURES.sameServer);
        const [, edge, , service] = path.nodes;

        expect(edge.kind === 'edge' && edge.both).toBe(true);
        expect(service.kind === 'service' && service.runsOn).toBeNull();
    });

    test('ends at the firewall when it blocked the request', () => {
        const path = tracePath(TRACE_FIXTURES.blocked);

        expect(path.end).toBe(2);
        expect(path.reached).toBe(2);
        expect(path.answers[2]).toMatchObject({
            words: '403 Forbidden',
            state: 'failed',
        });
        expect(path.hops.at(-1)?.state).toBe('skipped');
        expect(dotLegs(path).map((leg) => [leg.hop, leg.to])).toEqual([
            [0, 1],
            [1, 1],
        ]);
    });

    test('a refused response goes to the app and back to the firewall', () => {
        const path = tracePath(TRACE_FIXTURES.responseBlocked);

        expect(path.end).toBe(2);
        expect(path.reached).toBe(3);
        expect(path.answers[3]).toMatchObject({ words: '200 OK' });
        expect(path.answers[2]).toMatchObject({
            words: '403 Forbidden',
            note: 'Blocked web’s response',
        });
        expect(dotLegs(path).map((leg) => [leg.hop, leg.to])).toEqual([
            [0, 1],
            [1, 1],
            [2, 1],
            [2, 0],
        ]);
    });

    test('a refused connection fails into the service; the edge answered', () => {
        const path = tracePath(TRACE_FIXTURES.refused);

        expect(path.end).toBe(1);
        expect(path.reached).toBe(2);
        expect(path.hops.map((hop) => hop.state)).toEqual([
            'travelled',
            'travelled',
            'failed',
        ]);
        expect(path.hops[2]).toMatchObject({
            pill: 'Connection refused',
            title: 'Connection refused after 3 attempts',
        });
        expect(path.answers[1]).toMatchObject({
            words: '502 Bad gateway',
            state: 'failed',
            note: 'Couldn’t reach web, tried 3 times',
        });
        // Halfway into the failed connection, then it stops.
        expect(dotLegs(path).at(-1)).toMatchObject({ hop: 2, to: 0.5 });
    });

    test('a timeout says how long the edge waited', () => {
        const path = tracePath(TRACE_FIXTURES.timeoutRemote);

        expect(path.hops[2]).toMatchObject({
            state: 'failed',
            pill: 'Timed out',
            title: 'Timed out after 5.1 s',
        });
    });

    test('no healthy replica fails between the firewall and the service', () => {
        const path = tracePath(TRACE_FIXTURES.unhealthy);

        expect(path.hops[2].state).toBe('failed');
        expect(path.answers[1]?.words).toBe('503 Unavailable');
    });

    test('puts a ghost where the service would be when no domain matched', () => {
        const path = tracePath(TRACE_FIXTURES.noRoute);

        expect(kinds('noRoute')).toEqual(['visitor', 'edge', 'nowhere']);
        expect(path.answers[1]).toMatchObject({
            words: '404 Not found',
            state: 'warning',
            note: 'No domain matched this address',
        });
        expect(path.hops[1].state).toBe('skipped');
    });

    test('ends at the dashboard for its own routes', () => {
        expect(kinds('dashboard')).toEqual(['visitor', 'edge', 'dashboard']);
        expect(tracePath(TRACE_FIXTURES.dashboard).end).toBe(2);
    });

    test('a stopped service: past the firewall, the edge answered', () => {
        const path = tracePath(TRACE_FIXTURES.stopped);

        expect(path.end).toBe(1);
        expect(path.reached).toBe(2);
        expect(path.hops.map((hop) => hop.state)).toEqual([
            'travelled',
            'travelled',
            'skipped',
        ]);
        expect(path.answers[1]?.note).toBe('web is stopped');
        // To the firewall, then back to the edge that answered.
        expect(dotLegs(path).map((leg) => [leg.hop, leg.to])).toEqual([
            [0, 1],
            [1, 1],
            [1, 0],
        ]);
    });

    test('redirects and refused sign-ins end at the edge with why', () => {
        expect(tracePath(TRACE_FIXTURES.redirect).answers[1]).toMatchObject({
            words: '308 Permanent redirect',
            state: 'idle',
        });
        expect(
            tracePath(TRACE_FIXTURES.accessRefused).answers[1],
        ).toMatchObject({ note: 'Refused by sign-in protection' });
    });
});

describe('words', () => {
    test('firewall decision with its rules', () => {
        expect(firewallWords(TRACE_FIXTURES.blocked)).toBe(
            'Blocked, 2 rules matched',
        );
        expect(firewallWords(TRACE_FIXTURES.detected)).toBe(
            'Would block, 1 rule matched',
        );
        expect(firewallWords(TRACE_FIXTURES.viaOtherServer)).toBe('Allowed');
        expect(firewallWords(TRACE_FIXTURES.redirect)).toBeNull();
    });
});

describe('timing', () => {
    test('splits the time into the edge and the app', () => {
        expect(timing(TRACE_FIXTURES.viaOtherServer)).toEqual({
            totalUs: 85_200,
            edgeUs: 900,
            appUs: 84_300,
        });
        expect(timing(TRACE_FIXTURES.stopped)?.appUs).toBeNull();
    });

    test('TTFB names the segments and the moments between them', () => {
        expect(ttfb(TRACE_FIXTURES.viaOtherServer)).toEqual({
            totalUs: 85_200,
            segments: [
                { key: 'edge', label: 'Edge', us: 900, note: null },
                { key: 'app', label: 'web', us: 84_300, note: null },
            ],
            milestones: [
                { key: 'received', label: 'Received', us: 0 },
                { key: 'sent', label: 'Sent to web', us: 900 },
                { key: 'first', label: 'First byte', us: 85_200 },
            ],
        });
        expect(
            ttfb(TRACE_FIXTURES.blocked)?.segments.map((s) => s.key),
        ).toEqual(['edge']);
        expect(
            ttfb({ ...TRACE_FIXTURES.viaOtherServer, attempts: 2 })?.segments[1]
                .note,
        ).toBe('2 attempts');
    });
});

describe('normaliseTrace', () => {
    const envelope = 'AQAAAB3k9fQx7Lm2_Zp-0aBcDeFgHiJkLmNoPq';
    const ray = `lr1.${envelope}`;

    test('takes the value alone or a header line, without whitespace and quotes', () => {
        expect(normaliseTrace(ray)).toBe(ray);
        expect(normaliseTrace(`  "${ray}"\n`)).toBe(ray);
        expect(normaliseTrace(`X-Launchroom-Ray: ${ray}\n`)).toBe(ray);
        expect(normaliseTrace(`x-launchroom-ray:'${ray}'`)).toBe(ray);
        expect(normaliseTrace(`${ray.slice(0, 20)}\n${ray.slice(20)}`)).toBe(
            ray,
        );
    });

    test('reads rays of older edges without the prefix', () => {
        expect(normaliseTrace(envelope)).toBe(envelope);
        expect(normaliseTrace(`Launchroom-Trace: ${envelope}\n`)).toBe(
            envelope,
        );
        expect(
            normaliseTrace(`${envelope.slice(0, 20)}\n${envelope.slice(20)}`),
        ).toBe(envelope);
    });

    test('finds the ray in a whole curl -i output', () => {
        const curl = [
            'HTTP/2 403',
            'content-type: text/html; charset=utf-8',
            'x-request-id: 3f1c9a2e-8b7d-4e6f-a5b4-c3d2e1f00a1b',
            `x-launchroom-ray: ${ray}`,
            'date: Fri, 20 Nov 2026 12:00:00 GMT',
            '',
            '<!doctype html><title>Blocked</title>',
        ].join('\r\n');

        expect(normaliseTrace(curl)).toBe(ray);
        expect(
            normaliseTrace(
                curl.replace(
                    `x-launchroom-ray: ${ray}`,
                    `Launchroom-Trace: ${envelope}`,
                ),
            ),
        ).toBe(envelope);
    });

    test('finds the ray in a HAR entry', () => {
        expect(
            normaliseTrace(
                `{ "name": "x-launchroom-ray", "value": "${ray}" },`,
            ),
        ).toBe(ray);
        expect(
            normaliseTrace(`{"name":"X-Launchroom-Ray","value":"${ray}"}`),
        ).toBe(ray);
    });

    test('keeps another prefix for the server to answer', () => {
        expect(normaliseTrace(`X-Launchroom-Ray: lr2.${envelope}`)).toBe(
            `lr2.${envelope}`,
        );
        expect(normaliseTrace(`some text lr2.${envelope} more`)).toBe(
            `lr2.${envelope}`,
        );
    });

    test('refuses text without a ray', () => {
        expect(normaliseTrace('not a trace!')).toBeNull();
        expect(normaliseTrace('')).toBeNull();
        expect(normaliseTrace('X-Launchroom-Ray:')).toBeNull();
        expect(
            normaliseTrace('HTTP/2 200\r\ncontent-type: text/html\r\n'),
        ).toBeNull();
    });
});
