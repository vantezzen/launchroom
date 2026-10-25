import { Head, router } from '@inertiajs/react';
import { useEffect } from 'react';
import { useConnection } from '@/components/reconnect-banner';
import { Status } from '@/components/status';
import type { StatusState } from '@/components/status';
import { TimeAgo } from '@/components/time-ago';
import { SettingsCard, SettingsRow } from '@/features/settings/settings-card';
import { ServerVersions } from '@/features/updates/server-versions';
import { UpdatePreferences } from '@/features/updates/update-preferences';
import { UpdateAttention, UpdateHero } from '@/features/updates/update-status';
import type { UpdatesPageProps } from '@/features/updates/update-status';
import { checkConnection, connectionState } from '@/lib/connection';
import { index as settingsHome } from '@/routes/settings';
import { edit } from '@/routes/updates';

const outcome: Record<App.Enums.Updates.ControlPlaneUpdateStatus, StatusState> =
    {
        pending: 'progress',
        backing_up: 'progress',
        updating: 'progress',
        succeeded: 'active',
        failed: 'failed',
        rolled_back: 'warning',
    };

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
                        state={outcome[update.status]}
                        label={update.statusLabel}
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

        const timer = window.setInterval(() => {
            if (document.visibilityState !== 'visible') {
                return;
            }

            const wasOnline = connectionState() === 'online';

            void checkConnection().then((reachable) => {
                // Coming back online refreshes the page by itself.
                if (reachable && wasOnline) {
                    router.reload();
                }
            });
        }, 3000);

        return () => window.clearInterval(timer);
    }, [props.running]);

    const update = props.controlPlaneUpdate;
    const attention =
        update !== null &&
        !props.running &&
        (update.status === 'failed' || update.status === 'rolled_back');

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
                        props.check.available !== null &&
                        props.check.available.version === update.toVersion
                            ? {
                                  release: props.check.available,
                                  hasBackupDestination:
                                      props.hasBackupDestination,
                                  backupDestinationName:
                                      props.backupDestinationName,
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

UpdateSettings.layout = {
    breadcrumbs: [
        { title: 'Settings', href: settingsHome() },
        {
            title: 'Updates',
            href: edit(),
        },
    ],
};
