import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useRef } from 'react';
import { ChannelEvents } from '@/components/channel-events';
import { requestJson } from '@/lib/http';
import {
    index as fleetMetrics,
    show as serverMetrics,
} from '@/routes/servers/metrics';
import { channelName } from '@/lib/realtime';

type Range = App.Enums.MetricRange;

const POLL_MS = 60_000;
const NUDGE_DEBOUNCE_MS = 1_500;

/** `all` covers the fleet's and every server's metrics. */
const metricsKeys = {
    all: ['server-metrics'] as const,
    fleet: (range: Range) => ['server-metrics', 'fleet', range] as const,
    server: (server: string, range: Range) =>
        ['server-metrics', server, range] as const,
};

/**
 * Usage for every server card (`GET /servers/-/metrics`). Pages work
 * without it: while it loads or when the pipeline has no samples yet the
 * cards show specs and status only.
 */
export function useFleetMetrics(range: Range = '1h') {
    return useQuery({
        queryKey: metricsKeys.fleet(range),
        queryFn: ({ signal }) =>
            requestJson<App.Data.Metrics.FleetMetricsData>(
                'get',
                fleetMetrics({ query: { range } }).url,
                undefined,
                signal,
            ),
        staleTime: 20_000,
    });
}

/** One server's host series, current usage and workloads. */
export function useServerMetrics(server: string, range: Range) {
    return useQuery({
        queryKey: metricsKeys.server(server, range),
        queryFn: ({ signal }) =>
            requestJson<App.Data.Metrics.ServerMetricsData>(
                'get',
                serverMetrics({ server }, { query: { range } }).url,
                undefined,
                signal,
            ),
        staleTime: 20_000,
        placeholderData: (previous) => previous,
    });
}

/**
 * Refetches the server metrics queries when a server delivered new minutes
 * (`server.metrics` on `servers.{team}`, about once a minute per server),
 * debounced so a fleet reporting together causes one request. Mount it
 * beside every reader of these queries: it is also their polling fallback.
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

    const nudge = () => {
        if (timer.current !== null) {
            window.clearTimeout(timer.current);
        }

        timer.current = window.setTimeout(() => {
            void queryClient.invalidateQueries({ queryKey: metricsKeys.all });
        }, NUDGE_DEBOUNCE_MS);
    };

    return (
        <ChannelEvents
            channel={channelName('servers', team)}
            event="server.metrics"
            pollMs={POLL_MS}
            onEvent={nudge}
        />
    );
}
