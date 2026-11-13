import { describe, expect, test } from 'vite-plus/test';
import { groupMarkers } from './marker-groups';

const x = (t: number) => t;
const marker = (t: number, label = `deploy ${t}`) => ({ t, label });

describe('marker groups', () => {
    test('markers a few pixels apart share one tick with a count', () => {
        const groups = groupMarkers(
            [marker(100), marker(104), marker(106), marker(300)],
            x,
            600,
        );

        expect(groups).toHaveLength(2);
        expect(groups[0].markers).toHaveLength(3);
        expect(groups[0].label).toBe('3 deploys');
        expect(groups[0].x).toBeCloseTo((100 + 104 + 106) / 3);
        expect(groups[1].label).toBe('deploy 300');
        expect(groups.every((group) => group.showLabel)).toBe(true);
    });

    test('labels that would collide are hidden, never drawn on top of each other', () => {
        const groups = groupMarkers(
            [marker(30, 'deploy 1a1db0c'), marker(60, 'deploy 3b7d5e1')],
            x,
            600,
        );

        expect(groups).toHaveLength(2);
        expect(groups[0].showLabel).toBe(false);
        expect(groups[1].showLabel).toBe(true);
    });

    test('a label that does not fit on the right moves left of its tick', () => {
        const groups = groupMarkers(
            [marker(100, 'deploy 1a1db0c'), marker(130, 'deploy 3b7d5e1')],
            x,
            600,
        );

        expect(groups.map((group) => [group.anchor, group.showLabel])).toEqual([
            ['end', true],
            ['start', true],
        ]);
    });

    test('a label near the right edge goes on the left of its tick', () => {
        const [group] = groupMarkers([marker(590, 'deploy 1a1db0c')], x, 600);

        expect(group.anchor).toBe('end');
        expect(group.showLabel).toBe(true);
    });

    test('narrow charts keep the ticks and drop labels that do not fit', () => {
        const groups = groupMarkers(
            [marker(10, 'deploy 1a1db0c'), marker(40, 'deploy 3b7d5e1')],
            x,
            60,
        );

        expect(groups).toHaveLength(2);
        expect(groups.some((group) => group.showLabel)).toBe(false);
    });
});
