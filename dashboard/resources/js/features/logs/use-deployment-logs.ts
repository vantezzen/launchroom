import { useCallback, useEffect, useRef, useState } from 'react';
import { requestJson } from '@/features/projects/http';
import { logs as logsRoute } from '@/routes/deployments';

type LogPage = App.Data.Deployments.LogPageData;
type LogLine = App.Data.Deployments.LogLineData;

/** Live nudges are coalesced: at most one fetch every 200 ms (docs/08 §4). */
const BATCH_MS = 200;

/**
 * The lines of a deployment: the first page from the page props, then
 * pages after the cursor ("load more", live nudges). Lines are appended in
 * chunk order, so numbers (and `#L42` anchors) are stable.
 */
export function useDeploymentLogs(
    args: { project: string; environment: string; deployment: string },
    initial: LogPage,
) {
    const [lines, setLines] = useState<LogLine[]>(initial.lines);
    const [hasMore, setHasMore] = useState(initial.hasMore);
    const [loading, setLoading] = useState(false);
    const cursor = useRef(initial.cursor);
    const inFlight = useRef(false);
    const again = useRef(false);
    const timer = useRef<number | null>(null);
    const url = logsRoute(args).url;

    const fetchNext = useCallback(async (): Promise<void> => {
        if (inFlight.current) {
            again.current = true;

            return;
        }

        inFlight.current = true;
        setLoading(true);

        try {
            const page = await requestJson<LogPage>(
                'get',
                `${url}?after=${cursor.current}`,
            );

            if (page.cursor > cursor.current) {
                cursor.current = page.cursor;
                setLines((current) => [...current, ...page.lines]);
            }

            setHasMore(page.hasMore);
        } catch {
            // Transient: the next nudge or poll retries.
        } finally {
            inFlight.current = false;
            setLoading(false);
        }

        if (again.current) {
            again.current = false;
            await fetchNext();
        }
    }, [url]);

    /** A new chunk arrived: fetch soon, coalescing bursts. */
    const nudge = useCallback(() => {
        if (timer.current !== null) {
            return;
        }

        timer.current = window.setTimeout(() => {
            timer.current = null;
            void fetchNext();
        }, BATCH_MS);
    }, [fetchNext]);

    useEffect(
        () => () => {
            if (timer.current !== null) {
                window.clearTimeout(timer.current);
            }
        },
        [],
    );

    return { lines, hasMore, loading, loadMore: fetchNext, nudge };
}
