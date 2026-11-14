import { router } from '@inertiajs/react';
import { queryOptions } from '@tanstack/react-query';
import type { QueryClient } from '@tanstack/react-query';
import { requestJson } from '@/features/projects/http';
import { serviceDeploymentsUrl } from './deployment-actions';

export type ServiceDeployments = App.Data.Deployments.ServiceDeploymentsData;

export function serviceDeploymentsKey(environment: string, service: string) {
    return ['service-deployments', environment, service] as const;
}

/**
 * Service panel → Deployments as one query (docs/10 §3): the tab reads it,
 * the canvas starts it when a node is hovered or clicked (in parallel with
 * the panel visit), and a deep link seeds it from the page props.
 */
export function serviceDeploymentsQuery(
    project: string,
    environment: string,
    service: string,
) {
    return queryOptions({
        queryKey: serviceDeploymentsKey(environment, service),
        queryFn: ({ signal }) =>
            requestJson<ServiceDeployments>(
                'get',
                serviceDeploymentsUrl(project, environment, service),
                undefined,
                signal,
            ),
    });
}

/** Starts loading the list unless a fresh copy is cached. */
export function prefetchServiceDeployments(
    queryClient: QueryClient,
    project: string,
    environment: string,
    service: string,
) {
    void queryClient.prefetchQuery(
        serviceDeploymentsQuery(project, environment, service),
    );
}

/** The list that came with the page (a deep link): no second request. */
export function seedServiceDeployments(
    queryClient: QueryClient,
    environment: string,
    service: string,
    data: ServiceDeployments,
) {
    const key = serviceDeploymentsKey(environment, service);

    if (queryClient.getQueryData(key) === undefined) {
        queryClient.setQueryData(key, data);
    }
}

/**
 * Prefetched deployment pages carry these tags: a status change of any
 * deployment of the service drops them, so a click never opens a stale one.
 */
export function deploymentCacheTags(deployment: {
    id: string;
    environmentId: string;
    serviceId: string;
}): string[] {
    return [
        `deployment:${deployment.id}`,
        `service-deployments:${deployment.environmentId}:${deployment.serviceId}`,
    ];
}

export function flushDeploymentPages(environment: string, service: string) {
    router.flushByCacheTags(`service-deployments:${environment}:${service}`);
}
