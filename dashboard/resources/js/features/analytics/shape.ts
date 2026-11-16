import { colorForSlot, OTHER, PALETTE_SIZE } from '@/components/charts/palette';
import { REQUEST_CLASS } from '@/components/charts/request-breakdown';
import type { StackedSeries } from '@/components/charts/stacked-bars';

type Analytics = App.Data.Analytics.AnalyticsData;
type TeamAnalytics = App.Data.Analytics.TeamAnalyticsData;

/** Projects with their own colour in the team chart; the rest is "Other". */
const TOP_PROJECTS = 4;

/** What the team chart stacks by project: requests, 5xx or 4xx responses. */
export type TeamMeasure = 'requests' | 'serverErrors' | 'clientErrors';

const MEASURE: Record<
    TeamMeasure,
    (project: TeamAnalytics['projects'][number]) => number[]
> = {
    requests: (project) => project.requestsSeries,
    serverErrors: (project) => project.serverErrorSeries,
    clientErrors: (project) => project.clientErrorSeries,
};

const sum = (values: number[]) => values.reduce((total, v) => total + v, 0);

/**
 * A measure per bucket stacked by project (design-system §3.4, §9): the
 * `max` projects with the most of it in their own palette slot (a slot
 * already taken in this chart moves to the next free one, so two series
 * never share a colour), every other project summed into one grey
 * "Other (n)".
 */
export function projectSeries(
    data: TeamAnalytics,
    max = TOP_PROJECTS,
    measure: TeamMeasure = 'requests',
): StackedSeries[] {
    const pick = MEASURE[measure];
    const sorted = [...data.projects]
        .filter((p) => measure === 'requests' || sum(pick(p)) > 0)
        .sort((a, b) => sum(pick(b)) - sum(pick(a)) || b.requests - a.requests);
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
            values: pick(project),
        };
    });
    const rest = sorted.slice(max);

    if (rest.length > 0) {
        series.push({
            key: '__other',
            label: `Other (${rest.length})`,
            color: OTHER,
            values: data.timestamps.map((_, i) =>
                rest.reduce((total, p) => total + (pick(p)[i] ?? 0), 0),
            ),
        });
    }

    return series;
}

/**
 * Requests per bucket stacked by status class (docs/15 §5.4), named and
 * coloured by the shared request breakdown (components/charts/
 * request-breakdown.ts): successes in the signal colour, redirects grey,
 * the app's client errors amber, requests the web firewall refused violet
 * and hatched (carved out of 4xx by the server), server errors red and
 * hatched. 1xx (WebSocket upgrades) only appear when present.
 */
export function statusSeries(data: Analytics): StackedSeries[] {
    const series = (
        key: string,
        kind: keyof typeof REQUEST_CLASS,
        values: number[],
    ): StackedSeries => ({
        key,
        label: REQUEST_CLASS[kind].label,
        color: REQUEST_CLASS[kind].color,
        hatched: REQUEST_CLASS[kind].hatched,
        values,
    });

    return [
        series('2xx', 'success', data.status2xx),
        series('3xx', 'redirect', data.status3xx),
        series('4xx', 'clientError', data.status4xx),
        series('blocked', 'blocked', data.blocked),
        series('5xx', 'serverError', data.status5xx),
        series('1xx', 'upgrade', data.statusOther),
    ];
}

/** Share in percent, null without a total. */
export function share(part: number, total: number): number | null {
    return total > 0 ? (part / total) * 100 : null;
}

type PathRow = {
    key: string;
    path: string;
    requests: number;
    /** Share of the analysed requests, 0–100. */
    sharePct: number;
    clientErrorPct: number | null;
    serverErrorPct: number | null;
    /** Requests to this path the web firewall refused. */
    blocked: number;
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
        blocked: p.blocked,
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
            blocked: 0,
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
