import { describe, expect, test } from 'vite-plus/test';
import { atEdge, followAfterScroll } from './use-follow-scroll';

const box = (scrollTop: number, scrollHeight = 2000, clientHeight = 500) => ({
    scrollTop,
    scrollHeight,
    clientHeight,
});

describe('log follow (oldest first, newest at the end)', () => {
    test('at the bottom counts as following', () => {
        expect(atEdge(box(1500), 'end')).toBe(true);
        expect(atEdge(box(1480), 'end')).toBe(true);
        expect(atEdge(box(1400), 'end')).toBe(false);
    });

    test('scrolling up pauses, scrolling back to the bottom resumes', () => {
        const paused = followAfterScroll(true, 1500, box(1200), 'end');
        expect(paused).toEqual({ follow: false, atEdge: false });

        const stillPaused = followAfterScroll(false, 1200, box(1300), 'end');
        expect(stillPaused.follow).toBe(false);

        const resumed = followAfterScroll(false, 1300, box(1500), 'end');
        expect(resumed).toEqual({ follow: true, atEdge: true });
    });

    test('content growing below while following does not pause', () => {
        // New rows were measured: scrollHeight grew, the reader did not move up.
        const grown = followAfterScroll(true, 1500, box(1500, 2600), 'end');
        expect(grown).toEqual({ follow: true, atEdge: false });
    });
});

describe('log follow (newest first, newest at the start)', () => {
    test('scrolling down pauses, back at the top resumes', () => {
        expect(followAfterScroll(true, 0, box(300), 'start').follow).toBe(
            false,
        );
        expect(followAfterScroll(false, 300, box(10), 'start')).toEqual({
            follow: true,
            atEdge: true,
        });
    });
});
