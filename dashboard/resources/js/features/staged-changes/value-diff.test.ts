import { expect, test } from 'vitest';
import { diffValues, keyLabel } from './value-diff';

test('lists only the changed leaves of two objects', () => {
    const before = JSON.stringify({
        type: 'http',
        path: '/',
        timeoutSeconds: 3,
        intervalSeconds: 5,
    });
    const after = JSON.stringify({
        type: 'http',
        path: '/api/health',
        timeoutSeconds: 5,
        intervalSeconds: 5,
    });

    expect(diffValues(before, after)).toEqual([
        { key: 'path', label: 'Path', before: '/', after: '/api/health' },
        {
            key: 'timeoutSeconds',
            label: 'Timeout seconds',
            before: '3',
            after: '5',
        },
    ]);
});

test('walks nested objects and names added or removed keys', () => {
    expect(
        diffValues(
            JSON.stringify({ limits: { memory: 512 } }),
            JSON.stringify({ limits: { memory: 1024, cpu: 1 } }),
        ),
    ).toEqual([
        {
            key: 'limits.memory',
            label: 'Limits memory',
            before: '512',
            after: '1024',
        },
        {
            key: 'limits.cpu',
            label: 'Limits cpu',
            before: 'default',
            after: '1',
        },
    ]);
});

test('leaves scalars and arrays to the one-line display', () => {
    expect(diffValues('"a"', '"b"')).toBeNull();
    expect(diffValues('["npm","start"]', '["node","server.js"]')).toBeNull();
    expect(diffValues(null, '{"a":1}')).toBeNull();
});

test('turns keys into words', () => {
    expect(keyLabel('startPeriodSeconds')).toBe('Start period seconds');
});
