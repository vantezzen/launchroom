import { useCallback, useEffect, useRef, useState } from 'react';
import { requestJson } from '@/features/projects/http';
import { logs as logsRoute } from '@/routes/deployments';

type LogPage = App.Data.Deployments.LogPageData;
type LogLine = App.Data.Deployments.LogLineData;

/** Live nudges are coalesced: at most one fetch every 200 ms (docs/08 §4). */
const BATCH_MS = 200;

/** The lines of one deployment and the cursor after them. */
export type DeploymentLogState = {
    /** The deployment these lines belong to. */
    deployment: string;
    lines: LogLine[];
    hasMore: boolean;
    cursor: number;
};

export function initialLogState(
    deployment: string,
    page: LogPage,
): DeploymentLogState {
    return {
        deployment,
        lines: page.lines,
        hasMore: page.hasMore,
        cursor: page.cursor,
    };
}

/**
 * The state for `deployment`: the current one while it is the same
 * deployment, else a fresh one from its first page. Inertia keeps the page
 * component mounted when it visits another deployment (Redeploy redirects
 * to the new one), so state seeded from the first props would otherwise
 * keep showing the previous deployment's lines.
 */
export function logStateFor(
    current: DeploymentLogState,
    deployment: string,
    page: LogPage,
): DeploymentLogState {
    return current.deployment === deployment
        ? current
        : initialLogState(deployment, page);
}

/**
 * Applies a page fetched for `deployment` after the cursor. Pages of
 * another deployment (a request still in flight while the user navigated)
 * and pages that do not move the cursor are dropped.
 */
export function appendLogPage(
    current: DeploymentLogState,
    deployment: string,
    page: LogPage,
): DeploymentLogState {
    if (current.deployment !== deployment) {
        return current;
    }

    if (page.cursor <= current.cursor) {
        return page.hasMore === current.hasMore
            ? current
            : { ...current, hasMore: page.hasMore };
    }

    return {
        deployment,
        lines: [...current.lines, ...page.lines],
        hasMore: page.hasMore,
        cursor: page.cursor,
    };
}

/**
 * The lines of a deployment: the first page from the page props, then
 * pages after the cursor ("load more", live nudges). Lines are appended in
 * chunk order, so numbers (and `#L42` anchors) are stable. Switching to
 * another deployment starts over from its first page.
 */
export function useDeploymentLogs(
    args: { project: string; environment: string; deployment: string },
    initial: LogPage,
) {
    const [stored, setStored] = useState(() =>
        initialLogState(args.deployment, initial),
    );
    const state = logStateFor(stored, args.deployment, initial);

    if (state !== stored) {
        // Another deployment: reset during render (no stale frame).
        setStored(state);
    }

    const [loading, setLoading] = useState(false);
    const latest = useRef(state);
    const inFlight = useRef<Promise<void> | null>(null);
    const timer = useRef<number | null>(null);
    const url = logsRoute(args).url;
    const deployment = args.deployment;

    useEffect(() => {
        latest.current = state;
    });

    const fetchNext = useCallback((): Promise<void> => {
        if (inFlight.current) {
            return inFlight.current;
        }

        setLoading(true);
        const request = requestJson<LogPage>(
            'get',
            `${url}?after=${latest.current.cursor}`,
        )
            .then((page) => {
                const next = appendLogPage(latest.current, deployment, page);
                latest.current = next;
                setStored(next);
            })
            .finally(() => {
                inFlight.current = null;
                setLoading(false);
            });
        inFlight.current = request;
        return request;
    }, [url, deployment]);

    /** A new chunk arrived: fetch soon, coalescing bursts. */
    const nudge = useCallback(() => {
        if (timer.current !== null) {
            return;
        }

        timer.current = window.setTimeout(() => {
            timer.current = null;
            if (inFlight.current) {
                void inFlight.current.finally(nudge).catch(() => undefined);
                return;
            }
            void fetchNext().catch(() => undefined);
        }, BATCH_MS);
    }, [fetchNext]);

    /** Every line, fetching the pages not loaded yet (copy and download). */
    const loadAll = useCallback(async (): Promise<LogLine[]> => {
        while (latest.current.hasMore) {
            if (latest.current.deployment !== deployment) {
                throw new Error('The deployment changed while loading the log');
            }
            const before = latest.current.cursor;
            await fetchNext();

            if (latest.current.hasMore && latest.current.cursor <= before) {
                throw new Error('Could not load the rest of the log');
            }
        }

        return latest.current.lines;
    }, [fetchNext, deployment]);

    useEffect(
        () => () => {
            if (timer.current !== null) {
                window.clearTimeout(timer.current);
                timer.current = null;
            }
        },
        [deployment],
    );

    return {
        lines: state.lines,
        hasMore: state.hasMore,
        loading,
        loadMore: () => fetchNext().catch(() => undefined),
        loadAll,
        nudge,
    };
}
