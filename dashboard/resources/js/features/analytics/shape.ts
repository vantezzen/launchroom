import { colorForSlot, OTHER, PALETTE_SIZE } from '@/components/charts/palette';
import type { StackedSeries } from '@/components/charts/stacked-bars';

type Analytics = App.Data.Analytics.AnalyticsData;
type TeamAnalytics = App.Data.Analytics.TeamAnalyticsData;

/** Projects with their own colour in the team chart; the rest is "Other". */
export const TOP_PROJECTS = 4;

/**
 * Requests per bucket stacked by project (design-system §3.4, §9): the
 * busiest `max` projects in their own palette slot (a slot already taken in
 * this chart moves to the next free one, so two series never share a
 * colour), every other project summed into one grey "Other (n)".
 */
export function projectSeries(
    data: TeamAnalytics,
    max = TOP_PROJECTS,
): StackedSeries[] {
    const sorted = [...data.projects].sort((a, b) => b.requests - a.requests);
    const used = new Set<number>();
    const series: StackedSeries[] = sorted.slice(0, max).map((project) => {
        let slot =
            (((project.colorSlot % PALETTE_SIZE) + PALETTE_SIZE) %
                PALETTE_SIZE) +
            1;

        for (let tries = 0; used.has(slot) && tries < PALETTE_SIZE; tries++) {
            slot = (slot % PALETTE_SIZE) + 1;
        }

        used.add(slot);

        return {
            key: project.id,
            label: project.name,
            color: colorForSlot(slot),
            values: project.requestsSeries,
        };
    });
    const rest = sorted.slice(max);

    if (rest.length > 0) {
        series.push({
            key: '__other',
            label: `Other (${rest.length})`,
            color: OTHER,
            values: data.timestamps.map((_, i) =>
                rest.reduce((sum, p) => sum + (p.requestsSeries[i] ?? 0), 0),
            ),
        });
    }

    return series;
}

/**
 * Requests per bucket stacked by status class (docs/15 §5.4): successes in
 * the signal colour, redirects grey, client errors amber and server errors
 * red and hatched. 1xx (WebSocket upgrades) only appear when present.
 */
export function statusSeries(data: Analytics): StackedSeries[] {
    return [
        {
            key: '2xx',
            label: 'Success (2xx)',
            color: 'var(--signal)',
            values: data.status2xx,
        },
        {
            key: '3xx',
            label: 'Redirects (3xx)',
            color: 'var(--chart-other)',
            values: data.status3xx,
        },
        {
            key: '4xx',
            label: 'Client errors (4xx)',
            color: 'var(--status-progress)',
            values: data.status4xx,
        },
        {
            key: '5xx',
            label: 'Server errors (5xx)',
            color: 'var(--status-error)',
            hatched: true,
            values: data.status5xx,
        },
        {
            key: '1xx',
            label: 'Upgrades (1xx)',
            color: 'color-mix(in oklab, var(--chart-other) 45%, transparent)',
            values: data.statusOther,
        },
    ];
}

/** Share in percent, null without a total. */
export function share(part: number, total: number): number | null {
    return total > 0 ? (part / total) * 100 : null;
}

export type PathRow = {
    key: string;
    path: string;
    requests: number;
    /** Share of the analysed requests, 0–100. */
    sharePct: number;
    clientErrorPct: number | null;
    serverErrorPct: number | null;
    other: boolean;
};

/**
 * The top routes table: the listed paths, then one "Other paths" row with
 * the analysed requests the list doesn't cover.
 */
export function pathRows(data: Analytics): PathRow[] {
    const total = data.pathRequests;
    const rows: PathRow[] = data.paths.map((p) => ({
        key: p.path,
        path: p.path,
        requests: p.requests,
        sharePct: share(p.requests, total) ?? 0,
        clientErrorPct: share(p.status4xx, p.requests),
        serverErrorPct: share(p.status5xx, p.requests),
        other: false,
    }));
    const listed = data.paths.reduce((sum, p) => sum + p.requests, 0);
    const rest = total - listed;

    if (rest > 0) {
        rows.push({
            key: '__other',
            path: 'Other paths',
            requests: rest,
            sharePct: share(rest, total) ?? 0,
            clientErrorPct: null,
            serverErrorPct: null,
            other: true,
        });
    }

    return rows;
}

export type ShareRow = {
    key: string;
    label: string;
    value: number;
    sharePct: number;
};

/** Referrer hosts with their share of the requests that had a referrer. */
export function referrerRows(data: Analytics): ShareRow[] {
    const rows = data.referrers.map((r) => ({
        key: r.host,
        label: r.host,
        value: r.requests,
        sharePct: share(r.requests, data.referrerRequests) ?? 0,
    }));
    const rest =
        data.referrerRequests - rows.reduce((sum, r) => sum + r.value, 0);

    return rest > 0
        ? [
              ...rows,
              {
                  key: '__other',
                  label: 'Other sites',
                  value: rest,
                  sharePct: share(rest, data.referrerRequests) ?? 0,
              },
          ]
        : rows;
}

/** Browsers, bots and other clients, largest first; empty without data. */
export function agentRows(data: Analytics): ShareRow[] {
    const agents = data.agents;

    if (!agents) {
        return [];
    }

    const total = agents.browsers + agents.bots + agents.other;

    return [
        { key: 'browsers', label: 'Browsers', value: agents.browsers },
        { key: 'bots', label: 'Bots and crawlers', value: agents.bots },
        { key: 'other', label: 'Other clients', value: agents.other },
    ]
        .filter((row) => row.value > 0)
        .map((row) => ({ ...row, sharePct: share(row.value, total) ?? 0 }));
}

/** "4.2%", "0.31%", "0%". */
export function formatShare(value: number | null): string {
    if (value === null) {
        return '–';
    }

    if (value === 0) {
        return '0%';
    }

    if (value < 0.01) {
        return '<0.01%';
    }

    return value < 1 ? `${value.toFixed(2)}%` : `${value.toFixed(1)}%`;
}
