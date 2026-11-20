import { Head } from '@inertiajs/react';
import { traceHref } from '@/features/request-trace/links';
import { TraceEmpty } from '@/features/request-trace/trace-empty';
import { TraceView } from '@/features/request-trace/trace-view';

/**
 * `/trace?t=…` (docs/23 §6): a decoded ray (X-Launchroom-Ray) drawn as
 * the request's path, or the paste field without one (and with the
 * server's answer when it couldn't read the last one).
 */
export default function TracePage({
    trace,
    error,
    input,
}: {
    trace?: App.Data.Traces.RequestTraceData;
    error?: string;
    input?: string;
}) {
    if (trace) {
        return (
            <>
                <Head title="Ray" />
                <TraceView key={trace.requestId} trace={trace} />
            </>
        );
    }

    return (
        <>
            <Head title="Paste a ray" />
            <TraceEmpty error={error} input={input} />
        </>
    );
}

TracePage.layout = {
    breadcrumbs: [{ title: 'Ray', href: traceHref() }],
};
