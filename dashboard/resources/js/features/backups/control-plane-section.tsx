import { Form, router } from '@inertiajs/react';
import { Check, FileKey2, Shield } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { MetaLine } from '@/components/patterns/meta-line';
import { SidePanel, SheetSection } from '@/components/patterns/sheet';
import { Status, StatusPill } from '@/components/status';
import type { StatusState } from '@/components/status';
import { TimeAgo } from '@/components/time-ago';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { DestinationPanel } from '@/features/backups/destinations-section';
import { formatBytes } from '@/features/backups/format';
import { ScheduleRow } from '@/features/backups/schedules-section';
import { SettingsCard, SettingsRow } from '@/features/settings/settings-card';
import { run } from '@/routes/backups/control-plane';
import { confirm, kit, passphrase } from '@/routes/backups/recovery';

export type InstanceBackups = {
    destination: App.Data.Backups.BackupDestinationData | null;
    schedule: App.Data.Backups.BackupScheduleData | null;
    recoveryKit: App.Data.Backups.RecoveryKitStatusData;
    runs: App.Data.Backups.BackupRunData[];
};

const runStates: Record<
    App.Enums.Backups.BackupRunStatus,
    [StatusState, string]
> = {
    pending: ['progress', 'Queued'],
    running: ['progress', 'Running'],
    succeeded: ['active', 'Succeeded'],
    failed: ['failed', 'Failed'],
};

function RecentRuns({ runs }: { runs: App.Data.Backups.BackupRunData[] }) {
    return (
        <SheetSection title="Recent backups">
            {runs.length === 0 ? (
                <p className="type-body text-muted-foreground">
                    No backup of launchroom itself yet.
                </p>
            ) : (
                <ul className="flex flex-col divide-y">
                    {runs.map((item) => (
                        <li
                            key={item.id}
                            className="flex flex-col gap-0.5 py-2.5 first:pt-0 last:pb-0"
                        >
                            <div className="flex flex-wrap items-center justify-between gap-x-3">
                                <Status
                                    state={runStates[item.status][0]}
                                    label={runStates[item.status][1]}
                                    detail={item.reasonLabel}
                                />
                                <MetaLine className="type-small text-muted-foreground tabular-nums">
                                    <TimeAgo value={item.createdAt} />
                                    {item.sizeBytes !== null &&
                                        formatBytes(item.sizeBytes)}
                                </MetaLine>
                            </div>
                            {item.shortSnapshotId !== null && (
                                <span className="pl-5 type-mono-small text-muted-foreground">
                                    {item.shortSnapshotId}
                                </span>
                            )}
                            {item.error !== null && (
                                <p className="pl-5 type-mono-small break-words text-muted-foreground">
                                    {item.error}
                                </p>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </SheetSection>
    );
}

/**
 * "launchroom itself" as the first row of What's protected (docs/14 §2):
 * the dashboard's database and keys, every 6 hours and before upgrades.
 * Without a destination of its own, the row offers to set one up.
 */
export function LaunchroomRow({
    instance,
    protection,
}: {
    instance: InstanceBackups;
    protection: App.Data.Backups.BackupProtectionData[];
}) {
    const [settingUp, setSettingUp] = useState(false);
    const { destination, schedule, recoveryKit, runs } = instance;

    if (destination === null || schedule === null) {
        return (
            <SettingsRow
                media={
                    <span className="flex size-9 items-center justify-center rounded-md border bg-card text-muted-foreground">
                        <Shield aria-hidden="true" className="size-4" />
                    </span>
                }
                label="launchroom itself"
                description="Projects, settings and keys aren’t backed up off this server yet."
            >
                <Status state="warning" label="Not backed up" />
                <Button size="sm" onClick={() => setSettingUp(true)}>
                    Set up
                </Button>
                <DestinationPanel
                    open={settingUp}
                    onOpenChange={setSettingUp}
                    scope="instance"
                />
            </SettingsRow>
        );
    }

    return (
        <ScheduleRow
            schedule={schedule}
            protection={protection.find(
                (item) => item.scheduleId === schedule.id,
            )}
            label="launchroom itself"
            description={
                <MetaLine>
                    Projects, settings and keys
                    {schedule.cronDescription ?? 'Custom schedule'}
                </MetaLine>
            }
            actions={
                <Button
                    variant="outline"
                    size="sm"
                    disabled={!recoveryKit.passphraseSet}
                    title={
                        recoveryKit.passphraseSet
                            ? undefined
                            : 'Set a recovery passphrase first'
                    }
                    onClick={() =>
                        router.post(run().url, {}, { preserveScroll: true })
                    }
                >
                    Back up now
                </Button>
            }
            sheetExtra={<RecentRuns runs={runs} />}
        />
    );
}

function StepMark({ done }: { done: boolean }) {
    return done ? (
        <span className="flex size-9 items-center justify-center rounded-full bg-status-ok-soft text-status-ok">
            <Check aria-hidden="true" className="size-4" />
        </span>
    ) : (
        <span className="flex size-9 items-center justify-center rounded-full border border-dashed text-muted-foreground">
            <span className="size-1.5 rounded-full bg-current" />
        </span>
    );
}

function PassphrasePanel({
    open,
    onOpenChange,
    isSet,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    isSet: boolean;
}) {
    return (
        <SidePanel
            open={open}
            onOpenChange={onOpenChange}
            size="sm"
            title={
                isSet
                    ? 'Change the recovery passphrase'
                    : 'Set a recovery passphrase'
            }
            description="launchroom keeps only a key derived from it, so write it down."
        >
            <SheetSection>
                <Form
                    {...passphrase.form()}
                    options={{ preserveScroll: true }}
                    resetOnSuccess
                    onSuccess={() => onOpenChange(false)}
                    className="grid gap-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="recovery-passphrase">
                                    {isSet ? 'New passphrase' : 'Passphrase'}
                                </Label>
                                <Input
                                    id="recovery-passphrase"
                                    name="passphrase"
                                    type="password"
                                    autoComplete="new-password"
                                    required
                                    minLength={12}
                                />
                                <p className="type-small text-muted-foreground">
                                    At least 12 characters.
                                </p>
                                <InputError message={errors.passphrase} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="recovery-passphrase-confirmation">
                                    Repeat it
                                </Label>
                                <Input
                                    id="recovery-passphrase-confirmation"
                                    name="passphrase_confirmation"
                                    type="password"
                                    autoComplete="new-password"
                                    required
                                />
                            </div>
                            <div>
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    {isSet
                                        ? 'Change passphrase'
                                        : 'Set passphrase'}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </SheetSection>
        </SidePanel>
    );
}

/**
 * The recovery kit as a card with its own state (docs/14 §4): passphrase,
 * kit file, stored safely. Instance owners only.
 */
export function RecoveryKitCard({ instance }: { instance: InstanceBackups }) {
    const { destination, recoveryKit } = instance;
    const [settingPassphrase, setSettingPassphrase] = useState(false);
    const downloaded = recoveryKit.kitDownloadedAt !== null;
    const confirmed = recoveryKit.kitConfirmedAt !== null;
    const ready =
        destination !== null && recoveryKit.passphraseSet && confirmed;

    return (
        <SettingsCard
            title="Recovery kit"
            setting="recovery-kit"
            description="Use it to restore everything on a new server."
            actions={
                ready ? (
                    <StatusPill state="active" label="Stored safely" />
                ) : (
                    <StatusPill state="warning" label="Not ready" />
                )
            }
            footer="Keep the kit and the passphrase in two different places. The installer’s restore uses both."
        >
            <SettingsRow
                media={<StepMark done={recoveryKit.passphraseSet} />}
                label="Recovery passphrase"
                description={
                    recoveryKit.passphraseSetAt !== null ? (
                        <>
                            Set <TimeAgo value={recoveryKit.passphraseSetAt} />
                        </>
                    ) : (
                        'Encrypts launchroom’s keys inside every backup.'
                    )
                }
            >
                <Button
                    size="sm"
                    variant={recoveryKit.passphraseSet ? 'ghost' : 'default'}
                    onClick={() => setSettingPassphrase(true)}
                >
                    {recoveryKit.passphraseSet ? 'Change' : 'Set passphrase'}
                </Button>
            </SettingsRow>
            <SettingsRow
                media={
                    <StepMark done={downloaded && recoveryKit.passphraseSet} />
                }
                label="Kit file"
                description={
                    downloaded ? (
                        <>
                            Downloaded{' '}
                            <TimeAgo value={recoveryKit.kitDownloadedAt} />
                        </>
                    ) : destination !== null && recoveryKit.passphraseSet ? (
                        'Contains the destination, credentials and repository password, but not the passphrase.'
                    ) : (
                        'Add launchroom’s destination and set the passphrase first.'
                    )
                }
            >
                {destination !== null && recoveryKit.passphraseSet && (
                    <Button
                        size="sm"
                        variant={downloaded ? 'ghost' : 'outline'}
                        asChild
                    >
                        <a href={kit().url}>
                            <FileKey2 aria-hidden="true" />
                            {downloaded ? 'Download again' : 'Download'}
                        </a>
                    </Button>
                )}
            </SettingsRow>
            <SettingsRow
                media={<StepMark done={confirmed} />}
                label="Stored safely"
                description={
                    confirmed ? (
                        <>
                            Confirmed{' '}
                            <TimeAgo value={recoveryKit.kitConfirmedAt} />
                        </>
                    ) : (
                        'Confirm once the kit and the passphrase are put away.'
                    )
                }
            >
                {!confirmed && (
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={!downloaded}
                        onClick={() =>
                            router.post(
                                confirm().url,
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        I stored them
                    </Button>
                )}
            </SettingsRow>
            <PassphrasePanel
                open={settingPassphrase}
                onOpenChange={setSettingPassphrase}
                isSet={recoveryKit.passphraseSet}
            />
        </SettingsCard>
    );
}
