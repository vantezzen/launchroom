import { describe, expect, test } from 'vite-plus/test';
import { TRACE_FIXTURES } from './fixtures';
import {
    firewallWords,
    normaliseTrace,
    outcomeOf,
    timing,
    tracePath,
} from './trace-model';

const kinds = (key: keyof typeof TRACE_FIXTURES) =>
    tracePath(TRACE_FIXTURES[key]).nodes.map((node) => node.kind);

describe('tracePath', () => {
    test('draws the app server between the edge and the service', () => {
        const path = tracePath(TRACE_FIXTURES.viaOtherServer);

        expect(path.nodes.map((node) => node.kind)).toEqual([
            'visitor',
            'server',
            'server',
            'service',
        ]);
        expect(path.reached).toBe(3);
        expect(path.end).toBe(3);
        expect(path.hops.map((hop) => hop.state)).toEqual([
            'reached',
            'reached',
            'reached',
        ]);
        // The edge's share on the first hop, the app's on the hop leaving the edge.
        expect(path.hops.map((hop) => hop.pill)).toEqual([
            '0.9 ms',
            '84 ms',
            null,
        ]);
    });

    test('draws one server with both roles when the edge runs the app', () => {
        const path = tracePath(TRACE_FIXTURES.sameServer);
        const server = path.nodes[1];

        expect(kinds('sameServer')).toEqual(['visitor', 'server', 'service']);
        expect(server.kind === 'server' && server.role).toBe('both');
    });

    test('ends at the edge when the firewall blocked the request', () => {
        const path = tracePath(TRACE_FIXTURES.blocked);

        expect(path.end).toBe(1);
        expect(path.reached).toBe(1);
        expect(path.outcome).toEqual({
            cls: 'blocked',
            word: 'Blocked by firewall',
        });
        expect(path.hops.at(-1)?.state).toBe('skipped');
    });

    test('draws a firewall-blocked response all the way to the app', () => {
        const path = tracePath({
            ...TRACE_FIXTURES.blocked,
            upstreamStatus: 200,
            upstreamServer: TRACE_FIXTURES.viaOtherServer.upstreamServer,
        });

        expect(path.end).toBe(1);
        expect(path.reached).toBe(3);
    });

    test('a refused connection reached the app server; the edge answered', () => {
        const path = tracePath(TRACE_FIXTURES.refused);

        expect(path.end).toBe(1);
        expect(path.reached).toBe(2);
        expect(path.hops.map((hop) => hop.state)).toEqual([
            'reached',
            'reached',
            'failed',
        ]);
        expect(path.hops[2]).toMatchObject({
            pill: 'Connection refused',
            title: 'Connection refused after 3 attempts',
        });
        expect(path.outcome).toEqual({
            cls: 'serverError',
            word: '502 Bad gateway',
        });
    });

    test('a timeout through another server fails on the way out of the edge', () => {
        const path = tracePath(TRACE_FIXTURES.timeoutRemote);

        expect(path.end).toBe(1);
        expect(path.reached).toBe(1);
        expect(path.hops.map((hop) => hop.state)).toEqual([
            'reached',
            'failed',
            'skipped',
        ]);
    });

    test('no healthy replica fails between the edge and the service', () => {
        const path = tracePath(TRACE_FIXTURES.unhealthy);

        expect(path.nodes.map((node) => node.kind)).toEqual([
            'visitor',
            'server',
            'service',
        ]);
        expect(path.end).toBe(1);
        expect(path.hops[1].state).toBe('failed');
    });

    test('a local replica fails between the edge and the service', () => {
        const path = tracePath({
            ...TRACE_FIXTURES.refused,
            upstreamServer: TRACE_FIXTURES.sameServer.upstreamServer,
            sameServer: true,
        });

        expect(path.nodes).toHaveLength(3);
        expect(path.hops[1].state).toBe('failed');
    });

    test('puts a ghost where the service would be when no domain matched', () => {
        expect(kinds('noRoute')).toEqual(['visitor', 'server', 'nowhere']);
        expect(outcomeOf(TRACE_FIXTURES.noRoute)).toEqual({
            cls: 'clientError',
            word: '404 Not found',
        });
    });

    test('ends at the dashboard for its own routes', () => {
        expect(kinds('dashboard')).toEqual(['visitor', 'server', 'dashboard']);
    });

    test('dims what came after a stopped service', () => {
        const path = tracePath(TRACE_FIXTURES.stopped);

        expect(path.end).toBe(1);
        expect(path.hops.map((hop) => hop.state)).toEqual([
            'reached',
            'skipped',
        ]);
    });
});

describe('words', () => {
    test('firewall outcome with its rules', () => {
        expect(firewallWords(TRACE_FIXTURES.blocked)).toBe(
            'Blocked, 2 rules matched',
        );
        expect(firewallWords(TRACE_FIXTURES.detected)).toBe(
            'Would have been blocked, 1 rule matched',
        );
        expect(firewallWords(TRACE_FIXTURES.viaOtherServer)).toBe('Passed');
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
});

test('normaliseTrace drops the header name and whitespace', () => {
    const value = 'AQAAAB3k9fQx7Lm2_Zp-0aBcDeFgHiJkLmNoPq';

    expect(normaliseTrace(`Launchroom-Trace: ${value}\n`)).toBe(value);
    expect(normaliseTrace(`${value.slice(0, 20)}\n${value.slice(20)}`)).toBe(
        value,
    );
    expect(normaliseTrace('not a trace!')).toBeNull();
    expect(normaliseTrace('')).toBeNull();
});
