import { describe, expect, test } from 'vite-plus/test';
import {
    backoffMs,
    classifyError,
    classifyResponse,
    isNewBuild,
} from './connection';

describe('response classification', () => {
    test('a restarting dashboard reads as unreachable, not as an error page', () => {
        for (const status of [0, 502, 503, 504]) {
            expect(classifyResponse({ status })).toBe('unreachable');
        }
    });

    test('409 with a location is a new build: reload fully', () => {
        expect(
            classifyResponse({
                status: 409,
                headers: { 'x-inertia-location': 'https://lr.test/x' },
            }),
        ).toBe('version-change');
        expect(classifyResponse({ status: 409, headers: {} })).toBe('error');
    });

    test('an expired session reloads, other errors stay errors', () => {
        expect(classifyResponse({ status: 419 })).toBe('expired');
        expect(classifyResponse({ status: 500 })).toBe('error');
        expect(classifyResponse({ status: 404 })).toBe('error');
        expect(classifyResponse({ status: 200 })).toBe('ok');
    });

    test('client errors: network and gateway failures are unreachable', () => {
        const network = { code: 'ERR_NETWORK', message: 'x', name: 'Error' };
        const cancelled = {
            code: 'ERR_CANCELLED',
            message: 'x',
            name: 'Error',
        };
        const gateway = {
            code: 'ERR_HTTP_RESPONSE',
            message: 'x',
            name: 'Error',
            response: { status: 502, data: '', headers: {} },
        };
        const server = {
            ...gateway,
            response: { ...gateway.response, status: 500 },
        };

        type Err = Parameters<typeof classifyError>[0];
        expect(classifyError(network as Err)).toBe('unreachable');
        expect(classifyError(cancelled as Err)).toBe('cancelled');
        expect(classifyError(gateway as Err)).toBe('unreachable');
        expect(classifyError(server as Err)).toBe('error');
    });
});

describe('reconnecting', () => {
    test('backs off from one second to at most fifteen', () => {
        expect([0, 1, 2, 3, 4, 5, 10].map(backoffMs)).toEqual([
            1000, 2000, 4000, 8000, 15_000, 15_000, 15_000,
        ]);
    });

    test('a different asset version is a new build', () => {
        expect(isNewBuild('abc', 'abc')).toBe(false);
        expect(isNewBuild(null, null)).toBe(false);
        expect(isNewBuild('abc', 'def')).toBe(true);
        expect(isNewBuild('abc', null)).toBe(true);
    });
});
