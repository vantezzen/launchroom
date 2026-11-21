import { useCallback, useEffect, useState } from 'react';
import { ChannelEvents } from '@/components/channel-events';
import { Logo } from '@/components/logo';
import { Section } from '@/components/patterns/section';
import { SettingsPage } from '@/components/settings/settings-page';
import { Button } from '@/components/ui/button';
import { useLiveProps } from '@/hooks/use-live-props';
import { listNames, plural } from '@/lib/format';
import { liveReload } from '@/lib/live-reload';
import { channelName } from '@/lib/realtime';
import { ActionReason, BackupsFixContext } from './action-reason';
import {
    BackupsHeadline,
    BatchOutcome,
    EverythingButton,
} from './backups-hero';
import { BackupItemRow, ItemGroup } from './item-row';
import { LaunchroomHistory } from './launchroom-history';
import { NotBackedUp } from './not-backed-up';
import { groupSummary } from './overview-model';
import type { BackupsPage } from './page-types';
import { RecoveryKitCard } from './recovery-kit';
import { SetupSheet } from './setup-sheet';
import type { SetupStart } from './setup-sheet';
import { StorageSection } from './storage-section';

/** Nothing connected yet: what would be protected, and one way in. */
function ConnectStorage({
    page,
    onConnect,
}: {
    page: BackupsPage;
    onConnect: () => void;
}) {
    const items = page.groups.flatMap((group) => group.items);
    const databases = items.filter((item) => item.kind === 'database').length;
    const volumes = items.filter((item) => item.kind === 'volume').length;
    const ready = [
        databases > 0 ? plural(databases, 'database') : null,
        volumes > 0 ? plural(volumes, 'volume') : null,
    ].filter((part): part is string => part !== null);
    const add = page.storage.add;

    return (
        <div className="flex flex-col items-center gap-5 rounded-xl border border-dashed px-6 py-12 text-center">
            <div className="flex gap-2" aria-hidden="true">
                {['cloudflare', 'hetzner', 's3', 'backblaze'].map((kind) => (
                    <Logo key={kind} kind={kind} size={44} decorative />
                ))}
            </div>
            <div className="flex max-w-md flex-col gap-1">
                <p className="type-heading">Keep a copy off your servers</p>
                <p className="type-body text-muted-foreground">
                    {ready.length > 0
                        ? `Connect any S3-compatible bucket and your ${listNames(ready)} are backed up every day.`
                        : 'Connect any S3-compatible bucket. Every database and volume you add is backed up every day.'}
                </p>
            </div>
            {add.url !== null ? (
                <Button onClick={onConnect}>Connect storage</Button>
            ) : (
                add.disabled !== null && <ActionReason reason={add.disabled} />
            )}
        </div>
    );
}

/**
 * Settings → Backups (D114, docs/design/flows.md §14): the state as the
 * page's description with Back up everything now, one row per thing that holds
 * data grouped by project, what isn't backed up and why, where backups go
 * and the recovery kit. It stays live: Reverb nudges, and polling while
 * something runs or a test is pending.
 */
export function BackupsOverview({ page }: { page: BackupsPage }) {
    const [setup, setSetup] = useState<SetupStart | null>(null);
    const [setupKey, setSetupKey] = useState(0);
    const [history, setHistory] = useState(false);
    const launchroom = page.launchroom;
    const kit = launchroom?.isOwner ? launchroom.recoveryKit : null;
    const unconfigured = page.summary.state === 'unconfigured';

    const openSetup = useCallback((start: SetupStart) => {
        setSetupKey((key) => key + 1);
        setSetup(start);
    }, []);

    const go = useCallback(
        (hash: string) => {
            if (hash === '#setup') {
                openSetup({ step: 'where' });

                return;
            }

            if (hash === '#setup-launchroom') {
                openSetup({ step: 'launchroom' });

                return;
            }

            document
                .getElementById(hash.slice(1))
                ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        },
        [openSetup],
    );

    // Links from elsewhere (a service tab's "Connect storage", alerts).
    useEffect(() => {
        if (window.location.hash !== '') {
            go(window.location.hash);
        }
    }, [go]);

    useLiveProps(['page'], {
        while: page.live.pollMs !== null,
        everyMs: page.live.pollMs ?? 5000,
    });

    return (
        <BackupsFixContext.Provider value={go}>
            <ChannelEvents
                channel={channelName('backups', page.teamId)}
                event="backups.changed"
                pollMs={60_000}
                onEvent={() => liveReload(['page'])}
            />

            <SettingsPage
                title="Backups"
                description={
                    unconfigured ? (
                        'Copies of your databases and volumes, off your servers.'
                    ) : (
                        <BackupsHeadline summary={page.summary} />
                    )
                }
                actions={<EverythingButton everything={page.everything} />}
                className="gap-10"
            >
                <BatchOutcome everything={page.everything} />

                {unconfigured ? (
                    <ConnectStorage
                        page={page}
                        onConnect={() => openSetup({ step: 'where' })}
                    />
                ) : (
                    <Section
                        title="What’s protected"
                        description={plural(page.summary.total, 'item')}
                    >
                        <div className="overflow-hidden rounded-xl border bg-card shadow-card">
                            {page.groups.map((group) => (
                                <ItemGroup
                                    key={group.key}
                                    title={
                                        group.kind === 'launchroom'
                                            ? null
                                            : group.name
                                    }
                                    summary={groupSummary(group.items)}
                                >
                                    {group.items.map((item) => (
                                        <BackupItemRow
                                            key={item.id}
                                            item={item}
                                            onShowHistory={
                                                item.kind === 'control_plane' &&
                                                launchroom?.isOwner
                                                    ? () => setHistory(true)
                                                    : undefined
                                            }
                                        />
                                    ))}
                                </ItemGroup>
                            ))}
                        </div>
                    </Section>
                )}

                <NotBackedUp items={page.notBackedUp} />

                <StorageSection
                    storage={page.storage}
                    onAdd={() => openSetup({ step: 'where' })}
                    onReconnect={(removed) =>
                        openSetup({ step: 'reconnect', removed })
                    }
                />

                {kit !== null &&
                    kit !== undefined &&
                    launchroom?.destination !== null && (
                        <RecoveryKitCard kit={kit} />
                    )}

                {setup !== null && (
                    <SetupSheet
                        key={setupKey}
                        open
                        onOpenChange={(open) => {
                            if (!open) {
                                setSetup(null);
                            }
                        }}
                        start={setup}
                        page={page}
                    />
                )}

                {launchroom?.isOwner && (
                    <LaunchroomHistory
                        runs={launchroom.runs}
                        open={history}
                        onOpenChange={setHistory}
                    />
                )}
            </SettingsPage>
        </BackupsFixContext.Provider>
    );
}
