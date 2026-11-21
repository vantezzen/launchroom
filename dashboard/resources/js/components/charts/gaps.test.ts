import { describe, expect, test } from 'vite-plus/test';
import { findGaps, gapAt, gapLabel } from './gaps';

const MIN = 60_000;
const times = Array.from({ length: 10 }, (_, index) => index * MIN);

describe('chart gaps', () => {
    test('a stretch between two reports becomes a gap up to the next report', () => {
        const values = [1, 2, null, null, null, 3, 4, 5, 6, 7];

        expect(findGaps(times, [values])).toEqual([
            { from: 2 * MIN, to: 5 * MIN, first: 2, last: 4, ongoing: false },
        ]);
    });

    test('one missing bucket is not a gap', () => {
        expect(findGaps(times, [[1, 2, null, 3, 4, 5, 6, 7, 8, 9]])).toEqual(
            [],
        );
    });

    test('leading empty buckets are not a gap', () => {
        expect(
            findGaps(times, [[null, null, null, null, 1, 2, 3, 4, 5, 6]]),
        ).toEqual([]);
    });

    test('a gap that runs to the end needs three buckets and is ongoing', () => {
        expect(findGaps(times, [[1, 2, 3, 4, 5, 6, 7, 8, null, null]])).toEqual(
            [],
        );

        expect(
            findGaps(times, [[1, 2, 3, 4, 5, 6, 7, null, null, null]]),
        ).toEqual([
            { from: 7 * MIN, to: 10 * MIN, first: 7, last: 9, ongoing: true },
        ]);
    });

    test('a bucket with data in any series is not part of a gap', () => {
        const a = [1, null, null, null, 2, 3, 4, 5, 6, 7];
        const b = [1, null, 9, null, 2, 3, 4, 5, 6, 7];

        expect(findGaps(times, [a, b])).toEqual([]);
    });

    test('no data at all has no gaps', () => {
        expect(findGaps(times, [times.map(() => null)])).toEqual([]);
    });

    test('a bucket finds its gap', () => {
        const gaps = findGaps(times, [[1, 2, null, null, null, 3, 4, 5, 6, 7]]);

        expect(gapAt(gaps, 3)).toBe(gaps[0]);
        expect(gapAt(gaps, 5)).toBeNull();
    });

    test('the label says when nothing was reported', () => {
        const day = new Date(2026, 10, 12, 18, 30).getTime();
        const next = new Date(2026, 10, 12, 21, 0).getTime();

        expect(
            gapLabel({
                from: day,
                to: next,
                first: 0,
                last: 1,
                ongoing: false,
            }),
        ).toBe('No reports 12 Nov 18:30 to 21:00');
        expect(
            gapLabel({ from: day, to: next, first: 0, last: 1, ongoing: true }),
        ).toBe('No reports since 12 Nov 18:30');
    });
});
