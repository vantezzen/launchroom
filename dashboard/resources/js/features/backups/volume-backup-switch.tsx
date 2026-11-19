import { useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { toast } from 'sonner';
import { TimeAgo } from '@/components/time-ago';
import { Switch } from '@/components/ui/switch';
import { RequestFailed, requestJson } from '@/lib/http';
import { backupsKey, useServiceBackups } from './use-service-backups';
import type { BackupsRef } from './use-service-backups';

/**
 * "Back up this volume" on the volume card (owner decision 3), with its
 * consequence written under it. Off keeps the existing backups until they
 * expire and moves the volume to "Not backed up" on the Backups page.
 */
export function VolumeBackupSwitch({
    serviceRef,
    volumeId,
}: {
    serviceRef: BackupsRef;
    volumeId: string;
}) {
    const queryClient = useQueryClient();
    const query = useServiceBackups(serviceRef);
    const [busy, setBusy] = useState(false);
    const data = query.data;
    const volume = data?.volumes.find((item) => item.id === volumeId);

    if (data === undefined || volume === undefined) {
        return null;
    }

    const enabled = volume.backupEnabled;
    const url = volume.backupUrl;
    const retention = data.schedule?.retentionSummary ?? null;
    const id = `volume-backup-${volumeId}`;

    const toggle = async (next: boolean) => {
        if (url === null) {
            return;
        }

        setBusy(true);

        try {
            queryClient.setQueryData(
                backupsKey(serviceRef),
                await requestJson<App.Data.Backups.ServiceBackupsData>(
                    'put',
                    url,
                    { enabled: next },
                ),
            );
        } catch (e) {
            toast.error(
                e instanceof RequestFailed ? e.message : 'The change failed.',
            );
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="flex items-start justify-between gap-4 border-t px-5 py-3">
            <div className="flex min-w-0 flex-col gap-0.5">
                <label htmlFor={id} className="type-label">
                    Back up this volume
                </label>
                <p className="type-small text-muted-foreground">
                    {enabled ? (
                        `Copied with this service’s backups. Turned off, the existing backups stay until they expire${retention !== null ? ` (${retention})` : ''}.`
                    ) : (
                        <>
                            Turned off
                            {volume.turnedOffBy !== null &&
                                ` by ${volume.turnedOffBy}`}
                            {volume.turnedOffAt !== null && (
                                <>
                                    {' '}
                                    <TimeAgo value={volume.turnedOffAt} />
                                </>
                            )}
                            . This volume isn’t copied. Existing backups stay
                            until they expire
                            {retention !== null && ` (${retention})`}.
                        </>
                    )}
                </p>
                {enabled && volume.looksLikeCache && url !== null && (
                    <p className="type-small text-muted-foreground">
                        Looks like a cache. A cache can be left out.
                    </p>
                )}
                {url === null && (
                    <p className="type-small text-muted-foreground">
                        Owners and admins can change this.
                    </p>
                )}
            </div>
            <Switch
                id={id}
                checked={enabled}
                disabled={url === null || busy}
                onCheckedChange={(next) => void toggle(next)}
            />
        </div>
    );
}
