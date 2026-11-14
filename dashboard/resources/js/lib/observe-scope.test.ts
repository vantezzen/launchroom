import { describe, expect, test } from 'vite-plus/test';
import {
    DEFAULT_RANGE,
    RANGE_SETS,
    rangeOptions,
    rangeWords,
    readScope,
    scopeQuery,
    snapRange,
    windowAround,
    withScope,
} from './observe-scope';

describe('observe scope', () => {
    test('a range the page has is kept', () => {
        expect(snapRange('7d', RANGE_SETS.resources)).toBe('7d');
        expect(snapRange('15m', RANGE_SETS.logs)).toBe('15m');
    });

    test('an unsupported range snaps to the nearest one the page has', () => {
        expect(snapRange('1h', RANGE_SETS.analytics)).toBe('24h');
        expect(snapRange('15m', RANGE_SETS.analytics)).toBe('24h');
        expect(snapRange('30d', RANGE_SETS.resources)).toBe('7d');
        expect(snapRange('15m', RANGE_SETS.resources)).toBe('1h');
    });

    test('missing or unknown ranges give the default', () => {
        expect(snapRange(null, RANGE_SETS.logs)).toBe(DEFAULT_RANGE);
        expect(snapRange('2y', RANGE_SETS.resources)).toBe(DEFAULT_RANGE);
    });

    test('labels and words are the same on every page', () => {
        expect(rangeOptions(RANGE_SETS.resources)).toEqual([
            { value: '1h', label: 'Hour' },
            { value: '24h', label: 'Day' },
            { value: '7d', label: 'Week' },
        ]);
        expect(rangeWords('30d')).toBe('last 30 days');
        expect(rangeWords('nonsense')).toBe('last 24 hours');
    });

    test('the scope reads from and writes to a query string', () => {
        expect(
            readScope('?range=7d&service=svc_1&from=1700000000&to=1700003600'),
        ).toEqual({
            range: '7d',
            service: 'svc_1',
            window: { from: 1_700_000_000_000, to: 1_700_003_600_000 },
        });
        expect(readScope('?range=nope&from=10&to=5')).toEqual({
            range: null,
            service: null,
            window: null,
        });
        expect(scopeQuery({ range: DEFAULT_RANGE, service: null })).toEqual({});
        expect(
            scopeQuery({
                range: '1h',
                service: 'svc_1',
                window: { from: 1_700_000_000_400, to: 1_700_003_600_200 },
            }),
        ).toEqual({
            range: '1h',
            service: 'svc_1',
            from: '1700000000',
            to: '1700003601',
        });
    });

    test('withScope appends to a path only when there is something to keep', () => {
        expect(withScope('/p/a/b/logs', { range: '24h' })).toBe('/p/a/b/logs');
        expect(withScope('/p/a/b/logs', { range: '7d' })).toBe(
            '/p/a/b/logs?range=7d',
        );
        expect(withScope('/p/a/b/logs?q=x', { service: 'web' })).toBe(
            '/p/a/b/logs?q=x&service=web',
        );
    });

    test('a narrow bucket opens at least ten minutes around it', () => {
        expect(windowAround(600_000, 660_000)).toEqual({
            from: 330_000,
            to: 930_000,
        });
        expect(windowAround(0, 3_600_000)).toEqual({ from: 0, to: 3_600_000 });
    });
});
