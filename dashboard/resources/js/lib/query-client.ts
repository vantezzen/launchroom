import { QueryClient } from '@tanstack/react-query';

/**
 * TanStack Query for data outside page props: log history, service panel
 * tabs, the staged-changes pill (docs/10 §3). Realtime events invalidate
 * queries; polling is only a fallback when Reverb isn't configured.
 */
export const queryClient = new QueryClient({
    defaultOptions: {
        queries: {
            staleTime: 5_000,
            retry: 1,
            refetchOnWindowFocus: true,
        },
    },
});
