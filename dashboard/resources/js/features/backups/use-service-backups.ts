import { useQuery } from '@tanstack/react-query';
import { requestJson } from '@/lib/http';
import { vocabulary } from '@/lib/vocabulary';
import { lookup } from '@/routes/backups/services';

type Data = App.Data.Backups.ServiceBackupsData;

/** Public ids: prj_…, env_…, svc_… */
export type BackupsRef = {
    project: string;
    environment: string;
    service: string;
};

export function backupsKey({ project, environment, service }: BackupsRef) {
    return ['backups', project, environment, service] as const;
}

/** Waiting on something without a `service.activity` event (backup and restore runs have one). */
function isBusy(data: Data): boolean {
    return (
        data.listingStatus === 'pending' ||
        data.deletion?.status === 'backing_up' ||
        (data.verification !== null &&
            !vocabulary.Backups.VerificationStatus[data.verification.status]
                .isTerminal)
    );
}

/**
 * A service's backups (JSON, team-scoped on the server, one shared query
 * cache for the Backups page and the Data tab's "last backup" line).
 */
export function useServiceBackups(ref: BackupsRef, enabled = true) {
    return useQuery({
        queryKey: backupsKey(ref),
        queryFn: ({ signal }) =>
            requestJson<Data>('get', lookup(ref).url, undefined, signal),
        refetchInterval: (current) =>
            current.state.data && isBusy(current.state.data) ? 4000 : false,
        retry: false,
        enabled,
    });
}
