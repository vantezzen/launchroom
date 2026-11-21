import { hashKey, queryOptions } from '@tanstack/react-query';
import { useCallback, useSyncExternalStore } from 'react';
import { requestJson } from '@/lib/http';
import { queryClient } from '@/lib/query-client';
import { liveStatus as liveStatusRoute } from '@/routes/projects';
import type { LiveStatus, ServiceStatus } from './live-status';
import { newer, optimisticStatus, statusOf, withStatus } from './live-status';

/**
 * The live-status store (D108, docs/08 §23): one TanStack query per
 * environment holding its EnvironmentLiveStatusData. The shell prop
 * `liveStatus` seeds it, ServiceStatusSync refetches it, and every status
 * surface reads it here instead of from its own props.
 */
export function liveStatusKey(environment: string) {
    return ['live-status', environment] as const;
}

export function liveStatusQuery(project: string, environment: string) {
    const queryKey = liveStatusKey(environment);

    return queryOptions({
        queryKey,
        queryFn: async ({ signal }) => {
            const next = await requestJson<LiveStatus>(
                'get',
                liveStatusRoute({ project, environment }).url,
                undefined,
                signal,
            );

            return newer(queryClient.getQueryData(queryKey), next);
        },
        staleTime: 10_000,
    });
}

/** A snapshot from the page props, unless the store holds a newer one. */
export function seedLiveStatus(snapshot: LiveStatus): void {
    queryClient.setQueryData<LiveStatus>(
        liveStatusKey(snapshot.environmentId),
        (current) => newer(current, snapshot),
    );
}

/** Refetches the environment's snapshot (a nudge, a reconnect). */
export function refreshLiveStatus(environment: string): Promise<void> {
    return queryClient.invalidateQueries({
        queryKey: liveStatusKey(environment),
    });
}

/** Refetches every environment's snapshot (an action failed somewhere). */
export function refreshAllLiveStatus(): Promise<void> {
    return queryClient.invalidateQueries({ queryKey: ['live-status'] });
}

/**
 * Shows the status an action is about to cause on every surface at once.
 * Returns the undo for a failed request: the server's snapshot again.
 */
export function holdOptimisticStatus(
    environment: string,
    service: string,
    reason: App.Enums.NodeStatusReason,
    label?: string,
): () => void {
    const key = liveStatusKey(environment);
    const current = queryClient.getQueryData<LiveStatus>(key);

    if (current !== undefined) {
        queryClient.setQueryData<LiveStatus>(
            key,
            withStatus(current, service, optimisticStatus(reason, label)),
        );
    }

    return () => void refreshLiveStatus(environment);
}

/**
 * The environment's snapshot as the store holds it (undefined until the
 * page props or a fetch bring one). Reading never fetches: only
 * ServiceStatusSync does.
 */
export function useLiveStatus(
    environment: string | null | undefined,
): LiveStatus | undefined {
    const hash = hashKey(liveStatusKey(environment ?? ''));
    const subscribe = useCallback(
        (onChange: () => void) =>
            queryClient.getQueryCache().subscribe((event) => {
                if (event.query.queryHash === hash) {
                    onChange();
                }
            }),
        [hash],
    );

    return useSyncExternalStore(subscribe, () =>
        environment
            ? queryClient.getQueryData<LiveStatus>(liveStatusKey(environment))
            : undefined,
    );
}

/** One service's status: the store's, else `fallback` (the props' copy). */
export function useServiceStatus(
    environment: string,
    service: string,
    fallback: ServiceStatus | null = null,
): ServiceStatus | null {
    return statusOf(useLiveStatus(environment), service) ?? fallback;
}
