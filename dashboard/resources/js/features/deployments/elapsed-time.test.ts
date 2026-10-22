import { expect, test } from 'vitest';
import { elapsedSeconds } from './format';

test('running durations advance with the clock without a status update', () => {
    const start = '2026-10-22T12:00:00Z';
    expect(
        elapsedSeconds(start, null, Date.parse('2026-10-22T12:00:10Z')),
    ).toBe(10);
    expect(
        elapsedSeconds(start, null, Date.parse('2026-10-22T12:00:11Z')),
    ).toBe(11);
});

test('finished durations stay fixed as the clock advances', () => {
    expect(
        elapsedSeconds(
            '2026-10-22T12:00:00Z',
            '2026-10-22T12:00:10Z',
            Date.parse('2026-10-22T13:00:00Z'),
        ),
    ).toBe(10);
});
