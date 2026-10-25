import { describe, expect, test } from 'vite-plus/test';
import { tickIndexes } from '@/components/charts/stacked-bars';
import {
    agentRows,
    formatShare,
    pathRows,
    projectSeries,
    referrerRows,
    share,
    statusSeries,
} from './shape';

type Analytics = App.Data.Analytics.AnalyticsData;

const data = (over: Partial<Analytics> = {}): Analytics => ({
    range: '7d',
    bucketSeconds: 3600,
    timestamps: ['2026-10-25T10:00:00+00:00', '2026-10-25T11:00:00+00:00'],
    status2xx: [10, 20],
    status3xx: [1, 0],
    status4xx: [2, 0],
    status5xx: [0, 1],
    statusOther: [0, 0],
    totals: {
        requests: 34,
        status2xx: 30,
        status3xx: 1,
        status4xx: 2,
        status5xx: 1,
        errorRatePct: 2.94,
        previousRequests: null,
        previousErrorRatePct: null,
    },
    servers: [],
    services: [],
    paths: [
        { path: '/', requests: 20, status4xx: 0, status5xx: 0 },
        { path: '/users/:id', requests: 10, status4xx: 2, status5xx: 1 },
    ],
    pathRequests: 34,
    referrers: [{ host: 'news.ycombinator.com', requests: 6 }],
    referrerRequests: 8,
    agents: { browsers: 30, bots: 4, other: 0 },
    serversWithoutPaths: [],
    ...over,
});

describe('analytics shaping', () => {
    test('status series keep the bucket alignment and mark server errors', () => {
        const series = statusSeries(data());

        expect(series.map((s) => s.key)).toEqual([
            '2xx',
            '3xx',
            '4xx',
            '5xx',
            '1xx',
        ]);
        expect(series[0].values).toEqual([10, 20]);
        expect(series.find((s) => s.key === '5xx')?.hatched).toBe(true);
    });

    test('path rows add an "other" row for what the list does not cover', () => {
        const rows = pathRows(data());

        expect(rows.map((r) => [r.path, r.requests])).toEqual([
            ['/', 20],
            ['/users/:id', 10],
            ['Other paths', 4],
        ]);
        expect(rows[1].clientErrorPct).toBe(20);
        expect(rows[1].serverErrorPct).toBe(10);
        expect(rows[2].other).toBe(true);
        expect(pathRows(data({ pathRequests: 30 }))).toHaveLength(2);
    });

    test('referrers and agents report shares', () => {
        expect(referrerRows(data()).map((r) => [r.label, r.value])).toEqual([
            ['news.ycombinator.com', 6],
            ['Other sites', 2],
        ]);
        expect(agentRows(data()).map((r) => r.key)).toEqual([
            'browsers',
            'bots',
        ]);
        expect(agentRows(data({ agents: null }))).toEqual([]);
    });

    test('shares and their formatting', () => {
        expect(share(1, 0)).toBeNull();
        expect(formatShare(share(1, 4))).toBe('25.0%');
        expect(formatShare(0.31)).toBe('0.31%');
        expect(formatShare(0)).toBe('0%');
        expect(formatShare(null)).toBe('–');
    });
});

describe('stacked bars time axis', () => {
    test('hourly buckets over days are labelled at local midnights', () => {
        const start = new Date(2026, 9, 18, 0, 0, 0).getTime();
        const times = Array.from(
            { length: 168 },
            (_, i) => start + i * 3_600_000,
        );
        const ticks = tickIndexes(times, 3_600_000, 7);

        expect(ticks).toEqual([0, 24, 48, 72, 96, 120, 144]);
        expect(tickIndexes(times, 3_600_000, 3)).toEqual([0, 72, 144]);
    });

    test('short ranges are labelled evenly', () => {
        const times = Array.from({ length: 24 }, (_, i) => i * 3_600_000);

        expect(tickIndexes(times, 3_600_000, 4)).toEqual([0, 8, 15, 23]);
        expect(tickIndexes([0], 3_600_000, 4)).toEqual([0]);
    });
});

type Team = App.Data.Analytics.TeamAnalyticsData;

const project = (
    id: string,
    requests: number,
    colorSlot: number,
): App.Data.Analytics.AnalyticsProjectData => ({
    id,
    name: id,
    colorSlot,
    environmentId: null,
    requests,
    previousRequests: null,
    errorRatePct: null,
    clientErrorPct: null,
    requestsSeries: [requests - 1, 1],
});

describe('team project series', () => {
    const team = (projects: Team['projects']): Team => ({
        range: '7d',
        bucketSeconds: 3600,
        timestamps: ['a', 'b'],
        totals: data().totals,
        projects,
        servers: [],
        paths: [],
        pathRequests: 0,
    });

    test('the busiest projects get their slot, the rest folds into Other', () => {
        const series = projectSeries(
            team([
                project('small', 3, 4),
                project('shop', 100, 0),
                project('blog', 50, 1),
                project('api', 20, 2),
                project('docs', 10, 3),
                project('tiny', 2, 5),
            ]),
        );

        expect(series.map((s) => s.label)).toEqual([
            'shop',
            'blog',
            'api',
            'docs',
            'Other (2)',
        ]);
        expect(series[0].color).toBe('var(--chart-1)');
        expect(series[4].color).toBe('var(--chart-other)');
        expect(series[4].values).toEqual([3, 2]);
    });

    test('two projects with the same slot never share a colour', () => {
        const series = projectSeries(
            team([
                project('a', 10, 2),
                project('b', 5, 2),
                project('c', 1, 10),
            ]),
        );

        expect(series.map((s) => s.color)).toEqual([
            'var(--chart-3)',
            'var(--chart-4)',
            'var(--chart-5)',
        ]);
    });

    test('no projects, no series', () => {
        expect(projectSeries(team([]))).toEqual([]);
    });
});
