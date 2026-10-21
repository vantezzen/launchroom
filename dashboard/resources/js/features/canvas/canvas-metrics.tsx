import { useQuery } from '@tanstack/react-query';
import { createContext, useContext, useMemo } from 'react';
import type { ReactNode } from 'react';
import { requestJson } from '@/features/projects/http';
import { metrics } from '@/routes/projects';

type Series = App.Data.Metrics.ServiceUsageSeriesData;

const CanvasMetricsContext = createContext<Map<string, Series>>(new Map());

/**
 * Per-service CPU and memory of the last hour (`projects.metrics`) for
 * the node footers. Graceful without data: no entry → the node shows no
 * sparkline. Refreshed every minute (samples are per minute).
 */
export function CanvasMetricsProvider({
    project,
    environment,
    children,
}: {
    project: string;
    environment: string;
    children: ReactNode;
}) {
    const { data } = useQuery({
        queryKey: ['canvas-metrics', project, environment],
        queryFn: ({ signal }) =>
            requestJson<App.Data.Metrics.ProjectMetricsData>(
                'get',
                metrics(project, { query: { environment, range: '1h' } }).url,
                undefined,
                signal,
            ),
        refetchInterval: 60_000,
        retry: false,
        staleTime: 30_000,
    });

    const byService = useMemo(
        () =>
            new Map(
                (data?.services ?? []).map((series) => [
                    series.serviceId,
                    series,
                ]),
            ),
        [data],
    );

    return (
        <CanvasMetricsContext.Provider value={byService}>
            {children}
        </CanvasMetricsContext.Provider>
    );
}

export function useServiceMetrics(serviceId: string): Series | undefined {
    return useContext(CanvasMetricsContext).get(serviceId);
}

export function formatMemory(mb: number): string {
    return mb >= 1024 ? `${(mb / 1024).toFixed(1)} GB` : `${Math.round(mb)} MB`;
}
