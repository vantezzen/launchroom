import { useCallback, useEffect, useRef, useState } from 'react';
import { fetchOutput, fetchRun } from './api';
import type { Run } from './api';

type Line = App.Data.Commands.CommandOutputLineData;

const POLL_MS = 1000;

/**
 * One run and its output (docs/08 §22): the output is read by cursor, and
 * both are polled every second until the run ended and every stored line
 * arrived. No Reverb needed.
 */
export function useRun(id: string, initial: Run | null) {
    const [run, setRun] = useState<Run | null>(initial);
    const [lines, setLines] = useState<Line[]>([]);
    const [error, setError] = useState<string | null>(null);
    const cursor = useRef(0);

    const refresh = useCallback(
        async (signal: AbortSignal): Promise<boolean> => {
            // The status first: a run that ended has shipped all its lines.
            const next = await fetchRun(id, signal);
            let more = true;

            while (more) {
                const page = await fetchOutput(id, cursor.current, signal);
                cursor.current = page.cursor;
                more = page.hasMore;

                if (page.lines.length > 0) {
                    setLines((current) => [...current, ...page.lines]);
                }
            }

            setRun(next);

            return next.finished;
        },
        [id],
    );

    useEffect(() => {
        const controller = new AbortController();
        let timer: number | undefined;

        const loop = async () => {
            try {
                const finished = await refresh(controller.signal);
                setError(null);

                if (finished) {
                    return;
                }
            } catch (failure) {
                if (controller.signal.aborted) {
                    return;
                }

                setError(
                    failure instanceof Error
                        ? failure.message
                        : 'Couldn’t load this run.',
                );
            }

            timer = window.setTimeout(() => void loop(), POLL_MS);
        };

        void loop();

        return () => {
            controller.abort();
            window.clearTimeout(timer);
        };
    }, [refresh]);

    return { run, lines, error, setRun };
}
