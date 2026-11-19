import { trace as traceRoute } from '@/routes';

/** The trace page, with a trace to decode or empty for the paste field. */
export function traceHref(trace?: string | null): string {
    return traceRoute(trace ? { query: { t: trace } } : undefined).url;
}
