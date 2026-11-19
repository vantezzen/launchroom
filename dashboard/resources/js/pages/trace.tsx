import { Head } from '@inertiajs/react';
import { traceHref } from '@/features/request-trace/links';
import { TraceEmpty } from '@/features/request-trace/trace-empty';
import { TraceView } from '@/features/request-trace/trace-view';

/**
 * `/trace?t=…` (docs/23 §6): a decoded Launchroom-Trace header drawn as
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
                <Head title="Request trace" />
                <TraceView key={trace.requestId} trace={trace} />
            </>
        );
    }

    return (
        <>
            <Head title="Trace a request" />
            <TraceEmpty error={error} input={input} />
        </>
    );
}

TracePage.layout = {
    breadcrumbs: [{ title: 'Trace a request', href: traceHref() }],
};
