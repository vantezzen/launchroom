import { useQuery } from '@tanstack/react-query';
import { requestJson } from '@/lib/http';
import remoteAccess from '@/routes/remote-access';
import services from '@/routes/remote-access/services';

export type AccessKind = App.Enums.RemoteAccess.RemoteAccessKind;
export type Panel = App.Data.RemoteAccess.RemoteAccessPanelData;
export type Access = App.Data.RemoteAccess.RemoteAccessData;
export type ServiceRef = {
    project: string;
    environment: string;
    service: string;
};

/**
 * The panel's data; polled while an access is starting, open or ending. A
 * volume access (D67) names its volume.
 */
export const remoteAccessKeys = {
    kind: (kind: AccessKind) => ['remote-access', kind] as const,
    panel: (ref: ServiceRef, kind: AccessKind, volume?: string) =>
        [
            'remote-access',
            kind,
            ref.project,
            ref.environment,
            ref.service,
            volume ?? null,
        ] as const,
};

export function usePanel(
    ref: ServiceRef,
    kind: AccessKind,
    enabled: boolean,
    volume?: string,
) {
    return useQuery({
        queryKey: remoteAccessKeys.panel(ref, kind, volume),
        queryFn: ({ signal }) =>
            requestJson<Panel>(
                'get',
                services.show(
                    { ...ref, kind },
                    volume ? { query: { volume } } : undefined,
                ).url,
                undefined,
                signal,
            ),
        enabled,
        staleTime: 0,
        refetchInterval: (query) => {
            const live = query.state.data?.accesses.filter(
                (a) => a.status !== 'ended',
            );

            if (!live || live.length === 0) {
                return false;
            }

            // Ending: the server closes it within a few seconds; show the
            // result as soon as it has.
            if (live.some((a) => a.ending)) {
                return 1000;
            }

            return live.some((a) => a.port === null) ? 2000 : 5000;
        },
    });
}

export function startAccess(
    ref: ServiceRef,
    kind: AccessKind,
    body: {
        allowFrom: string[];
        publicKeys: string[];
        durationMinutes: number;
        allowWrites: boolean;
        workload: string | null;
        volume?: string | null;
    },
): Promise<Access> {
    return requestJson('post', services.open({ ...ref, kind }).url, body);
}

export function endAccess(id: string): Promise<Access> {
    return requestJson('post', remoteAccess.end(id).url, {});
}

/** The last public key used, remembered in this browser only. */
const KEY_STORE = 'launchroom.remote-access.public-key';

export function rememberedKey(): string {
    try {
        return window.localStorage.getItem(KEY_STORE) ?? '';
    } catch {
        return '';
    }
}

export function rememberKey(key: string): void {
    try {
        window.localStorage.setItem(KEY_STORE, key);
    } catch {
        // Private windows: nothing to remember.
    }
}
