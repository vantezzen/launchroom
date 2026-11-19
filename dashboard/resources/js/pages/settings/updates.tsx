import { Head } from '@inertiajs/react';
import { useEffect } from 'react';
import { useConnection } from '@/components/reconnect-banner';
import { presented, Status } from '@/components/status';
import { TimeAgo } from '@/components/time-ago';
import { ServerVersions } from '@/features/updates/server-versions';
import { UpdatePreferences } from '@/features/updates/update-preferences';
import { UpdateAttention, UpdateHero } from '@/features/updates/update-status';
import type { UpdatesPageProps } from '@/features/updates/update-status';
import { schedulePoll } from '@/hooks/use-live-props';
import { checkConnection, connectionState } from '@/lib/connection';
import { liveReload } from '@/lib/live-reload';
import { SettingsCard, SettingsRow } from '@/components/settings/settings-card';
import SettingsLayout from '@/features/settings/settings-layout';
import { vocabulary } from '@/lib/vocabulary';

const outcome = presented(vocabulary.Updates.ControlPlaneUpdateStatus);

function History({
    history,
}: {
    history: App.Data.Updates.ControlPlaneUpdateData[];
}) {
    if (history.length === 0) {
        return null;
    }

    return (
        <SettingsCard title="Earlier updates">
            {history.map((update) => (
                <SettingsRow
                    key={update.id}
                    inline
                    label={
                        <span className="type-mono">
                            {update.fromVersion} → {update.toVersion}
                        </span>
                    }
                    description={
                        <>
                            <TimeAgo value={update.finishedAt} />
                            {update.automatic ? ', automatically' : ''}
                        </>
                    }
                >
                    <Status
                        state={outcome[update.status].state}
                        label={outcome[update.status].label}
                    />
                </SettingsRow>
            ))}
        </SettingsCard>
    );
}

/**
 * Settings → Updates (docs/11 §4, docs/08 §11), instance owners only: one
 * guided flow from "ready" through backup, install and every server, with
 * what to do when an update didn't go through, plus the channel and
 * automatic updates.
 */
export default function UpdateSettings(props: UpdatesPageProps) {
    // Live status while an update runs. The dashboard itself restarts
    // during a control-plane update: each tick asks the tiny /up/version
    // first, so while it is away nothing piles up, and once the new build
    // answers the page reloads fully (lib/connection.ts).
    const connection = useConnection();

    useEffect(() => {
        if (!props.running) {
            return;
        }

        return schedulePoll(() => {
            const wasOnline = connectionState() === 'online';

            void checkConnection().then((reachable) => {
                // Coming back online refreshes the page by itself.
                if (reachable && wasOnline) {
                    liveReload([]);
                }
            });
        }, 3000);
    }, [props.running]);

    const update = props.controlPlaneUpdate;
    const attention =
        update !== null &&
        !props.running &&
        (vocabulary.Updates.ControlPlaneUpdateStatus[update.status].isFailed ||
            update.status === 'cancelled');
    const available = props.check.available;
    // "Try again" installs the same release: the newest one, or, while
    // releases can't be checked, the one this update had pinned.
    const retryRelease =
        update === null || props.currentVersion !== update.fromVersion
            ? null
            : available !== null
              ? available.version === update.toVersion &&
                available.daemonImage !== null
                  ? { version: available.version, url: available.url }
                  : null
              : { version: update.toVersion, url: null };

    return (
        <>
            <Head title="Updates" />

            {attention && (
                <UpdateAttention
                    update={update}
                    currentVersion={props.currentVersion}
                    fleetUpdate={props.fleetUpdate}
                    serverCount={props.serversBehindRelease}
                    retry={
                        retryRelease !== null
                            ? {
                                  ...retryRelease,
                                  hasBackupDestination:
                                      props.hasBackupDestination,
                                  backupDestinationName:
                                      props.backupDestinationName,
                                  latestBackup: props.latestBackup,
                                  runningBackup: props.runningBackup,
                                  disabled: props.running,
                              }
                            : undefined
                    }
                />
            )}

            <UpdateHero
                {...props}
                restarting={props.running && connection === 'reconnecting'}
            />

            <ServerVersions {...props} />

            <UpdatePreferences preferences={props.preferences} />

            <History
                history={props.history.filter(
                    (past) => !attention || past.id !== update.id,
                )}
            />
        </>
    );
}

UpdateSettings.layout = SettingsLayout;
