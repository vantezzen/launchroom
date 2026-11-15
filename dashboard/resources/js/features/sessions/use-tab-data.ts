import { useQuery } from '@tanstack/react-query';
import { requestJson } from '@/lib/http';
import { tabUrl } from './api';
import type { ServiceRef, SessionKind } from './api';

/** The Console/Data tab's JSON (ConsoleTabData / DataTabData). */
export function useTabData<T>(ref: ServiceRef, kind: SessionKind) {
    return useQuery({
        queryKey: ['sessions', kind, ref.project, ref.environment, ref.service],
        queryFn: ({ signal }) =>
            requestJson<T>('get', tabUrl(ref, kind), undefined, signal),
        staleTime: 0,
    });
}
