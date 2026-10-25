import { useCallback, useEffect, useRef, useState } from 'react';
import { requestJson } from '@/features/projects/http';

type Preview = App.Data.Canvas.PlanPreviewData;

/** Poll interval while the plan task runs. */
export const PLAN_POLL_MS = 2000;

/**
 * Give up waiting after this long: the server reports stuck tasks as
 * failed, but a page that never hears back still has to stop spinning.
 */
export const PLAN_TIMEOUT_MS = 180_000;

export const PLAN_TIMEOUT_MESSAGE =
    'Detection is taking longer than expected. Retry, or continue and let the first build detect it.';

export type PlanPreviewUpdate =
    | { preview: Preview | null; error: null }
    | { preview: null; error: string };

/**
 * One preview run without React: fetch, poll every {@link PLAN_POLL_MS}
 * while `pending`, and report an error once {@link PLAN_TIMEOUT_MS} passed
 * without an answer. `fetch(retry)` is called with `retry` only for the
 * first request of a retried run. Returns a stop function.
 */
export function startPlanPreview({
    fetch,
    onUpdate,
    retry,
    now = Date.now,
}: {
    fetch: (retry: boolean) => Promise<Preview>;
    onUpdate: (update: PlanPreviewUpdate) => void;
    retry: boolean;
    now?: () => number;
}): () => void {
    let stopped = false;
    let timer: ReturnType<typeof setTimeout> | undefined;
    const started = now();

    const run = (first: boolean) => {
        fetch(first && retry)
            .then((data) => {
                if (stopped) {
                    return;
                }

                if (data.status !== 'pending') {
                    onUpdate({ preview: data, error: null });

                    return;
                }

                if (now() - started >= PLAN_TIMEOUT_MS) {
                    onUpdate({ preview: null, error: PLAN_TIMEOUT_MESSAGE });

                    return;
                }

                onUpdate({ preview: data, error: null });
                timer = setTimeout(() => run(false), PLAN_POLL_MS);
            })
            .catch((failure: unknown) => {
                if (!stopped) {
                    onUpdate({
                        preview: null,
                        error:
                            failure instanceof Error
                                ? failure.message
                                : 'Detection failed.',
                    });
                }
            });
    };

    run(true);

    return () => {
        stopped = true;
        clearTimeout(timer);
    };
}

/**
 * "We detected…" for a repository (docs/08 §9): the preview of `key`
 * (null disables it), polled while pending, with a timeout and `retry()`,
 * which asks the server for a new plan task (it forgets a failed or stuck
 * one). `url(retry)` builds the request URL.
 */
export function usePlanPreview(
    url: (retry: boolean) => string,
    key: string | null,
): {
    preview: Preview | null;
    error: string | null;
    retry: () => void;
} {
    const [state, setState] = useState<PlanPreviewUpdate>({
        preview: null,
        error: null,
    });
    const [attempt, setAttempt] = useState(0);
    const urlRef = useRef(url);

    useEffect(() => {
        urlRef.current = url;
    });

    useEffect(() => {
        setState({ preview: null, error: null });

        if (key === null) {
            return;
        }

        const controller = new AbortController();
        const stop = startPlanPreview({
            fetch: (retry) =>
                requestJson<Preview>(
                    'get',
                    urlRef.current(retry),
                    undefined,
                    controller.signal,
                ),
            onUpdate: setState,
            retry: attempt > 0,
        });

        return () => {
            stop();
            controller.abort();
        };
    }, [key, attempt]);

    const retry = useCallback(() => setAttempt((current) => current + 1), []);

    return { preview: state.preview, error: state.error, retry };
}
