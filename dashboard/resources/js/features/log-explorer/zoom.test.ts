import { describe, expect, test } from 'vite-plus/test';
import { bucketsExtent, histogramZoom, linesExtent } from './zoom';

const MIN = 60_000;
const HOUR = 60 * MIN;
const DAY = 24 * HOUR;
const month = { from: 0, to: 30 * DAY };

describe('histogram zoom', () => {
    test('lines in a small part of the range zoom to them with round buckets', () => {
        const zoom = histogramZoom(
            { from: 29 * DAY + 8 * HOUR, to: 29 * DAY + 10 * HOUR },
            month,
        );

        expect(zoom).not.toBeNull();
        expect(zoom!.from).toBeLessThanOrEqual(29 * DAY + 8 * HOUR);
        expect(zoom!.to).toBeGreaterThanOrEqual(29 * DAY + 10 * HOUR);
        expect(zoom!.count).toBeLessThanOrEqual(60);
        expect(zoom!.count).toBeGreaterThanOrEqual(12);
        // Bucket edges fall on whole minutes.
        expect(((zoom!.to - zoom!.from) / zoom!.count) % MIN).toBe(0);
    });

    test('lines that cover much of the range keep the range', () => {
        expect(
            histogramZoom({ from: 5 * DAY, to: 25 * DAY }, month),
        ).toBeNull();
    });

    test('a single line still gets ten minutes of context', () => {
        const at = 15 * DAY;
        const zoom = histogramZoom({ from: at, to: at }, month)!;

        expect(zoom.to - zoom.from).toBeGreaterThanOrEqual(10 * MIN);
        expect(zoom.from).toBeLessThan(at);
        expect(zoom.to).toBeGreaterThan(at);
    });

    test('the zoom stays inside the range', () => {
        const zoom = histogramZoom({ from: 0, to: HOUR }, month)!;

        expect(zoom.from).toBe(0);
        expect(zoom.to).toBeLessThanOrEqual(month.to);
    });

    test('no lines, no zoom', () => {
        expect(histogramZoom(null, month)).toBeNull();
    });

    test('extents come from the line times or the filled buckets', () => {
        expect(linesExtent([5, 2, Number.NaN, 9])).toEqual({ from: 2, to: 9 });
        expect(linesExtent([])).toBeNull();
        expect(
            bucketsExtent([
                { start: 0, end: 10, total: 0 },
                { start: 10, end: 20, total: 3 },
                { start: 20, end: 30, total: 1 },
                { start: 30, end: 40, total: 0 },
            ]),
        ).toEqual({ from: 10, to: 30 });
    });
});
