import { requestJson } from '@/lib/http';
import runs from '@/routes/commands/runs';
import services from '@/routes/commands/services';

export type ServiceRef = {
    project: string;
    environment: string;
    service: string;
};

export type Run = App.Data.Commands.CommandRunData;
export type TabData = App.Data.Commands.CommandsTabData;
export type OutputPage = App.Data.Commands.CommandOutputPageData;

/** The Commands tab's JSON; `cursor` pages back through older runs. */
export function fetchTab(
    ref: ServiceRef,
    cursor?: string | null,
    signal?: AbortSignal,
): Promise<TabData> {
    return requestJson(
        'get',
        services.show(ref, { query: cursor ? { cursor } : {} }).url,
        undefined,
        signal,
    );
}

export function startRun(
    ref: ServiceRef,
    body: { argv?: string[]; name?: string; timeoutSeconds: number },
): Promise<Run> {
    return requestJson('post', services.run(ref).url, body);
}

export function fetchRun(id: string, signal?: AbortSignal): Promise<Run> {
    return requestJson('get', runs.show(id).url, undefined, signal);
}

export function fetchOutput(
    id: string,
    after: number,
    signal?: AbortSignal,
): Promise<OutputPage> {
    return requestJson(
        'get',
        runs.output(id, { query: { after } }).url,
        undefined,
        signal,
    );
}

export function cancelRun(id: string): Promise<Run> {
    return requestJson('post', runs.cancel(id).url, {});
}
