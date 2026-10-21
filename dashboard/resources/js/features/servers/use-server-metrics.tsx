import { echoIsConfigured, useEcho } from '@laravel/echo-react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useRef } from 'react';
import { requestJson } from '@/features/projects/http';
import {
    index as fleetMetrics,
    show as serverMetrics,
} from '@/routes/servers/metrics';

type Range = App.Enums.MetricRange;

const POLL_MS = 60_000;
const NUDGE_DEBOUNCE_MS = 1_500;

export const fleetMetricsKey = (range: Range) => ['servers', 'metrics', range];
export const serverMetricsKey = (server: string, range: Range) => [
    'servers',
    server,
    'metrics',
    range,
];

/**
 * Usage for every server card (`GET /servers/-/metrics`). Pages work
 * without it: while it loads or when the pipeline has no samples yet the
 * cards show specs and status only.
 */
export function useFleetMetrics(range: Range = '1h') {
    return useQuery({
        queryKey: fleetMetricsKey(range),
        queryFn: ({ signal }) =>
            requestJson<App.Data.Metrics.FleetMetricsData>(
                'get',
                fleetMetrics({ query: { range } }).url,
                undefined,
                signal,
            ),
        refetchInterval: echoIsConfigured() ? false : POLL_MS,
        staleTime: 20_000,
    });
}

/** One server's host series, current usage and workloads. */
export function useServerMetrics(server: string, range: Range) {
    return useQuery({
        queryKey: serverMetricsKey(server, range),
        queryFn: ({ signal }) =>
            requestJson<App.Data.Metrics.ServerMetricsData>(
                'get',
                serverMetrics({ server }, { query: { range } }).url,
                undefined,
                signal,
            ),
        refetchInterval: echoIsConfigured() ? false : POLL_MS,
        staleTime: 20_000,
        placeholderData: (previous) => previous,
    });
}

function Subscription({
    team,
    onNudge,
}: {
    team: string;
    onNudge: () => void;
}) {
    useEcho(`servers.${team}`, '.server.metrics', onNudge, [team]);

    return null;
}

/**
 * Refetches the server metrics queries when a server delivered new minutes
 * (`server.metrics` on `servers.{team}`, about once a minute per server),
 * debounced so a fleet reporting together causes one request.
 */
export function ServerMetricsLiveUpdates({ team }: { team: string }) {
    const queryClient = useQueryClient();
    const timer = useRef<number | null>(null);

    useEffect(
        () => () => {
            if (timer.current !== null) {
                window.clearTimeout(timer.current);
            }
        },
        [],
    );

    if (!echoIsConfigured()) {
        return null;
    }

    const nudge = () => {
        if (timer.current !== null) {
            window.clearTimeout(timer.current);
        }

        timer.current = window.setTimeout(() => {
            void queryClient.invalidateQueries({ queryKey: ['servers'] });
        }, NUDGE_DEBOUNCE_MS);
    };

    return <Subscription team={team} onNudge={nudge} />;
}
