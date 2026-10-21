import { Link } from '@inertiajs/react';
import { Archive, CopyPlus, Download, History, RefreshCw } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { toast } from 'sonner';
import type { StatusState } from '@/components/status';
import { EmptyState } from '@/components/patterns/empty-state';
import { OptionCards } from '@/components/patterns/option-cards';
import { Section } from '@/components/patterns/section';
import { Status, StatusIcon } from '@/components/status';
import { TimeAgo } from '@/components/time-ago';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { BackupRequestFailed, backupsApi } from '@/features/backups/api';
import { BackupHealthStatus } from '@/features/backups/backup-health';
import { BackupTrend } from '@/features/backups/backup-trend';
import {
    PointInTimeCard,
    PointInTimePicker,
    pointLabel,
    windowEndText,
} from '@/features/backups/point-in-time';
import { formatBytes, reasonLabel } from '@/features/backups/format';
import { cn } from '@/lib/utils';
import { PendingDeletion } from '@/features/backups/pending-deletion';
import { MoveDataSection } from '@/features/data-transfer/move-data-section';
import type { SnapshotDownload } from '@/features/data-transfer/move-data-section';
import { lookup } from '@/routes/backups/services';
import { settings as backupSettings } from '@/routes/backups';

type Data = App.Data.Backups.ServiceBackupsData;
type Snapshot = App.Data.Backups.SnapshotData;
type RestoreRun = App.Data.Backups.RestoreRunData;
type Verification = App.Data.Backups.BackupVerificationData;

/** The timeline entry that stands for "any point in time" (PITR). */
const POINT_IN_TIME = 'point-in-time';

export type BackupsTabProps = {
    /** Public ids: prj_…, env_…, svc_… */
    project: string;
    environment: string;
    service: string;
};

const runStates: Record<App.Enums.Backups.BackupRunStatus, StatusState> = {
    pending: 'progress',
    running: 'progress',
    succeeded: 'active',
    failed: 'failed',
};

const runLabels: Record<App.Enums.Backups.BackupRunStatus, string> = {
    pending: 'Queued',
    running: 'Running',
    succeeded: 'Succeeded',
    failed: 'Failed',
};

const verificationStates: Record<
    App.Enums.Backups.VerificationStatus,
    StatusState
> = {
    pending: 'progress',
    running: 'progress',
    passed: 'active',
    failed: 'failed',
};

const subjectLabels: Record<App.Enums.Backups.BackupSubject, string> = {
    database: '',
    volume: '',
    control_plane: '',
    base_backup: 'Base backup',
};

const restoreLabels: Record<App.Enums.Backups.RestoreRunStatus, string> = {
    waiting_for_safety_backup: 'Taking a safety snapshot',
    waiting_for_target: 'Waiting for the service to run',
    restoring: 'Restoring',
    succeeded: 'Restored',
    failed: 'Failed',
};

function restoreState(status: App.Enums.Backups.RestoreRunStatus): StatusState {
    if (status === 'succeeded') {
        return 'active';
    }

    return status === 'failed' ? 'failed' : 'progress';
}

function isBusy(data: Data): boolean {
    return (
        data.listingStatus === 'pending' ||
        data.deletion?.status === 'backing_up' ||
        data.verification?.status === 'pending' ||
        data.verification?.status === 'running' ||
        data.runs.some(
            (run) => run.status === 'pending' || run.status === 'running',
        ) ||
        data.restores.some(
            (restore) =>
                restore.status !== 'succeeded' && restore.status !== 'failed',
        )
    );
}

/**
 * Service panel → Backups for databases and services with volumes (docs/08
 * §1, docs/14 §2, §5.1; design-system §13.9 "Backups tab"): a protection
 * card (health, schedule sentence, Back up now), the snapshot timeline
 * (pick one), restore as option cards ("As a new service" default, "Into
 * this service" with typed confirmation), and one history list of restores
 * and runs. A staged removal waiting for its final backup sits on top.
 *
 * Mounted by the canvas service panel. Fetches its own data (JSON,
 * team-scoped on the server) and polls while something runs.
 */
export function BackupsTab({ project, environment, service }: BackupsTabProps) {
    const [data, setData] = useState<Data | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [busyAction, setBusyAction] = useState<string | null>(null);
    const [restoring, setRestoring] = useState<Restoring | null>(null);
    const [swapping, setSwapping] = useState<RestoreRun | null>(null);
    const [selectedId, setSelectedId] = useState<string | null>(null);
    const [restoreMode, setRestoreMode] =
        useState<App.Enums.Backups.RestoreMode>('new_service');
    const [allSnapshots, setAllSnapshots] = useState(false);
    /** ISO time picked for a point-in-time restore (null: the newest point). */
    const [pointInTime, setPointInTime] = useState<string | null>(null);
    const [snapshotDownload, setSnapshotDownload] =
        useState<SnapshotDownload | null>(null);
    const clearSnapshotDownload = useCallback(
        () => setSnapshotDownload(null),
        [],
    );
    const abort = useRef<AbortController | null>(null);

    const url = useMemo(
        () => lookup({ project, environment, service }).url,
        [project, environment, service],
    );

    const load = useCallback(async () => {
        abort.current?.abort();
        const controller = new AbortController();
        abort.current = controller;

        try {
            setData(
                await backupsApi<Data>(
                    'get',
                    url,
                    undefined,
                    controller.signal,
                ),
            );
            setError(null);
        } catch (e) {
            if (!controller.signal.aborted) {
                setError(
                    e instanceof BackupRequestFailed
                        ? e.message
                        : 'Backups could not be loaded.',
                );
            }
        }
    }, [url]);

    useEffect(() => {
        void load();

        return () => abort.current?.abort();
    }, [load]);

    useEffect(() => {
        if (data === null || !isBusy(data)) {
            return;
        }

        const timer = window.setTimeout(() => void load(), 4000);

        return () => window.clearTimeout(timer);
    }, [data, load]);

    const act = async (
        name: string,
        target: string,
        body?: unknown,
        success?: string,
        method: 'post' | 'put' = 'post',
    ) => {
        setBusyAction(name);

        try {
            setData(await backupsApi<Data>(method, target, body));

            if (success) {
                toast.success(success);
            }

            return true;
        } catch (e) {
            toast.error(
                e instanceof BackupRequestFailed
                    ? e.message
                    : 'The request failed.',
            );

            return false;
        } finally {
            setBusyAction(null);
        }
    };

    if (error !== null && data === null) {
        return <p className="type-body text-muted-foreground">{error}</p>;
    }

    if (data === null) {
        return (
            <div className="flex items-center gap-2 type-body text-muted-foreground">
                <Spinner /> Loading backups
            </div>
        );
    }

    if (!data.supported) {
        return (
            <p className="max-w-[65ch] type-body text-muted-foreground">
                Backups cover databases and services with volumes. Add a volume
                in Settings to keep files across deploys and back them up.
            </p>
        );
    }

    const volumes = data.subject === 'volume';
    const canRestore =
        data.restoreSupported &&
        (data.canRestoreAsNew || data.canRestoreInPlace);
    const pitr = data.pointInTime;
    const pitrRestorable =
        pitr !== null && pitr.enabled && pitr.windowStart !== null;
    const pitrSelected =
        pitrRestorable &&
        (selectedId === POINT_IN_TIME ||
            (selectedId === null && data.snapshots.length === 0));
    const selected = pitrSelected
        ? null
        : (data.snapshots.find((snapshot) => snapshot.id === selectedId) ??
          data.snapshots[0] ??
          null);
    const mode: App.Enums.Backups.RestoreMode =
        restoreMode === 'in_place' && data.canRestoreInPlace
            ? 'in_place'
            : data.canRestoreAsNew
              ? 'new_service'
              : 'in_place';
    const shownSnapshots = allSnapshots
        ? data.snapshots
        : data.snapshots.slice(0, 6);

    return (
        <div className="flex flex-col gap-8">
            {data.deletion !== null && (
                <PendingDeletion
                    request={data.deletion}
                    onChange={() => void load()}
                />
            )}

            <ProtectionCard
                data={data}
                busy={busyAction !== null}
                backingUp={busyAction === 'backup'}
                verifying={busyAction === 'verify'}
                onBackup={() =>
                    void act(
                        'backup',
                        data.urls.backup,
                        undefined,
                        'Backup started.',
                    )
                }
                onVerify={() =>
                    void act(
                        'verify',
                        data.urls.verify,
                        undefined,
                        'Verification started: restoring into a throwaway database.',
                    )
                }
            />

            {pitr !== null &&
                pitr.supported &&
                (pitr.enabled || pitr.canConfigure) && (
                    <PointInTimeCard
                        pitr={pitr}
                        busy={busyAction === 'pitr'}
                        onChange={(enabled, keepDays) =>
                            void act(
                                'pitr',
                                pitr.updateUrl,
                                { enabled, keepDays },
                                enabled
                                    ? pitr.enabled
                                        ? 'Saved.'
                                        : 'Point-in-time recovery is on. Postgres restarts once.'
                                    : 'Point-in-time recovery is off.',
                                'put',
                            )
                        }
                    />
                )}

            {volumes && data.volumes.length > 0 && (
                <Section
                    level={3}
                    title="Volumes"
                    description="Pause for files written in place, like SQLite."
                >
                    <div className="overflow-hidden rounded-xl border bg-card shadow-card">
                        <ul className="divide-y">
                            {data.volumes.map((volume) => (
                                <li
                                    key={volume.id}
                                    className="flex flex-wrap items-center justify-between gap-3 px-4 py-3"
                                >
                                    <div className="flex min-w-0 flex-col">
                                        <span className="truncate type-mono">
                                            {volume.mountPath}
                                        </span>
                                        <span className="type-small text-muted-foreground">
                                            {volume.server ??
                                                'Created on the next deploy'}
                                        </span>
                                    </div>
                                    {data.canConfigure ? (
                                        <Select
                                            value={volume.consistency}
                                            disabled={busyAction !== null}
                                            onValueChange={(value) =>
                                                void act(
                                                    `volume-${volume.id}`,
                                                    volume.updateUrl,
                                                    { consistency: value },
                                                    'Backup setting saved.',
                                                    'put',
                                                )
                                            }
                                        >
                                            <SelectTrigger
                                                className="w-56"
                                                aria-label={`Consistency of ${volume.mountPath}`}
                                            >
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="live">
                                                    Live (keeps running)
                                                </SelectItem>
                                                <SelectItem value="pause">
                                                    Pause while reading
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                    ) : (
                                        <span className="type-small text-muted-foreground">
                                            {volume.consistency === 'pause'
                                                ? 'Pauses while reading'
                                                : 'Live'}
                                        </span>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </div>
                </Section>
            )}

            <Section
                level={3}
                title="Snapshots"
                description={
                    data.snapshots.length > 0
                        ? `${data.snapshots.length} kept · newest first`
                        : undefined
                }
                actions={
                    data.destinationName !== null && (
                        <Button
                            size="sm"
                            variant="ghost"
                            disabled={
                                busyAction !== null ||
                                data.listingStatus === 'pending'
                            }
                            onClick={() =>
                                void act('refresh', data.urls.refresh)
                            }
                        >
                            {data.listingStatus === 'pending' ? (
                                <Spinner />
                            ) : (
                                <RefreshCw aria-hidden="true" />
                            )}
                            Refresh
                        </Button>
                    )
                }
            >
                {data.listingError !== null && (
                    <p className="type-small text-muted-foreground">
                        Couldn’t read the snapshot list: {data.listingError}
                    </p>
                )}

                {data.snapshots.length === 0 && !pitrRestorable ? (
                    <EmptyState icon={Archive}>
                        Snapshots appear here after the first backup.
                    </EmptyState>
                ) : (
                    <SnapshotTimeline
                        pointInTime={
                            pitrRestorable && pitr !== null
                                ? {
                                      pitr,
                                      selected: pitrSelected,
                                      value: pointInTime,
                                      onChange: setPointInTime,
                                  }
                                : null
                        }
                        snapshots={shownSnapshots}
                        selectedId={
                            canRestore
                                ? pitrSelected
                                    ? POINT_IN_TIME
                                    : (selected?.id ?? null)
                                : null
                        }
                        onSelect={canRestore ? setSelectedId : undefined}
                        more={
                            !allSnapshots && data.snapshots.length > 6
                                ? data.snapshots.length - 6
                                : 0
                        }
                        onShowAll={() => setAllSnapshots(true)}
                    />
                )}

                {data.snapshots.length > 0 && !data.restoreSupported && (
                    <p className="type-small text-muted-foreground">
                        Restoring {data.engine} from here isn’t available yet;
                        the snapshots are in your bucket for restic.
                    </p>
                )}
            </Section>

            {canRestore && (selected !== null || pitrSelected) && (
                <Section
                    level={3}
                    title="Restore"
                    description={
                        pitrSelected
                            ? pointInTime === null
                                ? 'To the newest point'
                                : `To ${pointLabel(pointInTime)}:${String(new Date(pointInTime).getSeconds()).padStart(2, '0')}`
                            : selected !== null
                              ? `Snapshot from ${snapshotTime(selected.time)}`
                              : undefined
                    }
                >
                    <OptionCards
                        label="How to restore"
                        value={mode}
                        onChange={setRestoreMode}
                        options={[
                            ...(data.canRestoreAsNew
                                ? [
                                      {
                                          value: 'new_service' as const,
                                          title: 'As a new service',
                                          description: `Adds a copy next to ${data.serviceName}. Nothing existing changes.`,
                                          media: (
                                              <CopyPlus
                                                  aria-hidden="true"
                                                  className="size-5 text-muted-foreground"
                                              />
                                          ),
                                      },
                                  ]
                                : []),
                            ...(data.canRestoreInPlace
                                ? [
                                      {
                                          value: 'in_place' as const,
                                          title: `Into ${data.serviceName}`,
                                          description:
                                              'Replaces its data. A safety snapshot is taken first.',
                                          media: (
                                              <History
                                                  aria-hidden="true"
                                                  className="size-5 text-muted-foreground"
                                              />
                                          ),
                                      },
                                  ]
                                : []),
                        ]}
                    />
                    <div className="flex flex-wrap gap-2">
                        <Button
                            variant={
                                mode === 'in_place' ? 'outline' : 'default'
                            }
                            onClick={() =>
                                setRestoring(
                                    pitrSelected
                                        ? {
                                              pointInTime:
                                                  pointInTime ?? 'newest',
                                              mode,
                                          }
                                        : selected !== null
                                          ? { snapshot: selected, mode }
                                          : null,
                                )
                            }
                        >
                            {mode === 'in_place'
                                ? `Restore into ${data.serviceName}…`
                                : 'Restore as a new service…'}
                        </Button>
                        {selected !== null &&
                            !pitrSelected &&
                            data.canRestoreInPlace && (
                                <Button
                                    variant="ghost"
                                    onClick={() =>
                                        setSnapshotDownload({
                                            id: selected.id,
                                            label: selected.id.slice(0, 8),
                                        })
                                    }
                                >
                                    <Download aria-hidden="true" />
                                    Download this backup
                                </Button>
                            )}
                    </div>
                </Section>
            )}

            <MoveDataSection
                serviceInstanceId={data.serviceInstanceId}
                snapshotDownload={snapshotDownload}
                onSnapshotDownloadHandled={clearSnapshotDownload}
            />

            {data.trend.length >= 2 && (
                <Section
                    level={3}
                    title="Trend"
                    description={`Last ${data.trend.length} backups`}
                >
                    <BackupTrend points={data.trend} />
                </Section>
            )}

            {(data.restores.length > 0 ||
                data.runs.length > 0 ||
                data.verification !== null) && (
                <Section level={3} title="History">
                    <ul className="flex flex-col divide-y overflow-hidden rounded-xl border bg-card shadow-card">
                        {data.verification !== null && (
                            <VerificationItem
                                verification={data.verification}
                            />
                        )}
                        {data.restores.map((restore) => (
                            <li
                                key={`restore-${restore.id}`}
                                className="flex flex-col gap-1 px-4 py-3"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                                    <Status
                                        state={restoreState(restore.status)}
                                        label={`${restoreLabels[restore.status]} ${
                                            restore.mode === 'new_service'
                                                ? `as ${restore.targetServiceName ?? 'a new service'}`
                                                : 'into this service'
                                        }${
                                            restore.restoredTo !== null
                                                ? ` · to ${pointLabel(restore.restoredTo)}`
                                                : restore.pointInTime
                                                  ? restore.requestedTime !==
                                                    null
                                                      ? ` · to ${pointLabel(restore.requestedTime)}`
                                                      : ' · to the newest point'
                                                  : ''
                                        }`}
                                    />
                                    <span className="type-small text-muted-foreground">
                                        <TimeAgo value={restore.createdAt} />
                                    </span>
                                </div>
                                {restore.error !== null && (
                                    <p className="type-mono-small break-words text-muted-foreground">
                                        {restore.error}
                                    </p>
                                )}
                                {restore.swapUrl !== null && (
                                    <div>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => setSwapping(restore)}
                                        >
                                            Swap in…
                                        </Button>
                                    </div>
                                )}
                            </li>
                        ))}
                        {data.runs.slice(0, 5).map((run) => (
                            <li
                                key={run.id}
                                className="flex flex-col gap-1 px-4 py-3"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                                    <Status
                                        state={runStates[run.status]}
                                        label={`${subjectLabels[run.subject] !== '' ? `${subjectLabels[run.subject]} (${run.reasonLabel.toLowerCase()})` : `${run.reasonLabel} backup`} ${runLabels[run.status].toLowerCase()}`}
                                    />
                                    <span className="type-small text-muted-foreground tabular-nums">
                                        {run.sizeBytes !== null &&
                                            `${formatBytes(run.sizeBytes)} · `}
                                        <TimeAgo value={run.createdAt} />
                                    </span>
                                </div>
                                {run.error !== null && (
                                    <p className="type-mono-small break-words text-muted-foreground">
                                        {run.error}
                                    </p>
                                )}
                            </li>
                        ))}
                    </ul>
                </Section>
            )}

            <SwapDialog
                data={data}
                swapping={swapping}
                busy={busyAction === 'swap'}
                onClose={() => setSwapping(null)}
                onConfirm={async (confirmation) => {
                    if (swapping?.swapUrl == null) {
                        return;
                    }

                    const ok = await act(
                        'swap',
                        swapping.swapUrl,
                        { confirmation },
                        'Swap staged. Review the changes and deploy to apply them.',
                    );

                    if (ok) {
                        setSwapping(null);
                    }
                }}
            />

            <RestoreDialog
                data={data}
                restoring={restoring}
                busy={busyAction === 'restore'}
                onClose={() => setRestoring(null)}
                onConfirm={async (confirmation) => {
                    if (restoring === null) {
                        return;
                    }

                    const ok = await act(
                        'restore',
                        data.urls.restore,
                        'snapshot' in restoring
                            ? {
                                  snapshot: restoring.snapshot.id,
                                  mode: restoring.mode,
                                  confirmation,
                              }
                            : {
                                  pointInTime: true,
                                  at:
                                      restoring.pointInTime === 'newest'
                                          ? null
                                          : restoring.pointInTime,
                                  mode: restoring.mode,
                                  confirmation,
                              },
                        restoring.mode === 'new_service'
                            ? 'Restore started: a new service is being created.'
                            : 'Restore started: taking a safety snapshot first.',
                    );

                    if (ok) {
                        setRestoring(null);
                    }
                }}
            />
        </div>
    );
}

type Restoring =
    | { snapshot: Snapshot; mode: App.Enums.Backups.RestoreMode }
    | { pointInTime: string; mode: App.Enums.Backups.RestoreMode };

function RestoreDialog({
    data,
    restoring,
    busy,
    onClose,
    onConfirm,
}: {
    data: Data;
    restoring: Restoring | null;
    busy: boolean;
    onClose: () => void;
    onConfirm: (confirmation: string | null) => Promise<void>;
}) {
    const [typed, setTyped] = useState('');
    const inPlace = restoring?.mode === 'in_place';
    const taken =
        restoring === null
            ? ''
            : 'snapshot' in restoring
              ? `the snapshot from ${new Date(restoring.snapshot.time).toLocaleString()}`
              : restoring.pointInTime === 'newest'
                ? 'the newest point (the last change that reached the backup destination)'
                : `its state at ${new Date(restoring.pointInTime).toLocaleString()}`;

    return (
        <Dialog
            open={restoring !== null}
            onOpenChange={(open) => {
                if (!open) {
                    setTyped('');
                    onClose();
                }
            }}
        >
            <DialogContent>
                <DialogTitle>
                    {inPlace
                        ? `Restore into ${data.serviceName}?`
                        : 'Restore as a new service?'}
                </DialogTitle>
                <DialogDescription>
                    {data.subject === 'volume'
                        ? inPlace
                            ? `This replaces the files in ${restoring !== null && 'snapshot' in restoring ? (restoring.snapshot.mountPath ?? 'the volume') : 'the volume'} with ${taken}. A safety snapshot is taken first; ${data.serviceName} is stopped while the volume is replaced and starts again afterwards.`
                            : `This adds a copy of ${data.serviceName} (same settings and variables), in this environment only, and restores ${taken} into its volume. Nothing existing is changed.`
                        : inPlace
                          ? `This replaces the data in ${data.serviceName} with ${taken}. A safety snapshot is taken first; ${data.serviceName} and the services connected to it are stopped during the restore and start again afterwards.`
                          : `This adds a new database next to ${data.serviceName}, in this environment only, and restores ${taken} into it. Nothing existing is changed.`}
                </DialogDescription>

                {inPlace && (
                    <div className="flex flex-col gap-2">
                        <Label htmlFor="restore-confirmation">
                            Type{' '}
                            <span className="type-mono">
                                {data.confirmationText}
                            </span>{' '}
                            to confirm
                        </Label>
                        <Input
                            id="restore-confirmation"
                            className="font-mono"
                            autoComplete="off"
                            value={typed}
                            onChange={(event) => setTyped(event.target.value)}
                        />
                    </div>
                )}

                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button variant="secondary" type="button">
                            Cancel
                        </Button>
                    </DialogClose>
                    <Button
                        variant={inPlace ? 'destructive' : 'default'}
                        disabled={
                            busy || (inPlace && typed !== data.confirmationText)
                        }
                        onClick={() =>
                            void onConfirm(inPlace ? typed : null).then(() =>
                                setTyped(''),
                            )
                        }
                    >
                        {busy && <Spinner />}
                        {inPlace ? 'Replace data' : 'Restore as new service'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function SwapDialog({
    data,
    swapping,
    busy,
    onClose,
    onConfirm,
}: {
    data: Data;
    swapping: RestoreRun | null;
    busy: boolean;
    onClose: () => void;
    onConfirm: (confirmation: string) => Promise<void>;
}) {
    const [typed, setTyped] = useState('');
    const copy = swapping?.targetServiceName ?? 'the restored service';

    return (
        <Dialog
            open={swapping !== null}
            onOpenChange={(open) => {
                if (!open) {
                    setTyped('');
                    onClose();
                }
            }}
        >
            <DialogContent>
                <DialogTitle>{`Swap ${copy} in for ${data.serviceName}?`}</DialogTitle>
                <DialogDescription>
                    {data.subject === 'volume'
                        ? `In this environment, ${copy} takes over ${data.serviceName}’s domains and connections, and ${data.serviceName} is removed.`
                        : `In this environment, every service connected to ${data.serviceName} is connected to ${copy} instead, and ${data.serviceName} is removed.`}{' '}
                    This is staged: nothing changes until you deploy, and
                    Discard undoes it. Other environments keep{' '}
                    {data.serviceName}. Its volume is kept.
                </DialogDescription>

                <div className="flex flex-col gap-2">
                    <Label htmlFor="swap-confirmation">
                        Type{' '}
                        <span className="type-mono">
                            {data.confirmationText}
                        </span>{' '}
                        to confirm
                    </Label>
                    <Input
                        id="swap-confirmation"
                        className="font-mono"
                        autoComplete="off"
                        value={typed}
                        onChange={(event) => setTyped(event.target.value)}
                    />
                </div>

                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button variant="secondary" type="button">
                            Cancel
                        </Button>
                    </DialogClose>
                    <Button
                        variant="destructive"
                        disabled={busy || typed !== data.confirmationText}
                        onClick={() =>
                            void onConfirm(typed).then(() => setTyped(''))
                        }
                    >
                        {busy && <Spinner />}
                        Stage swap
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/** "Today, 05:08" · "Yesterday, 05:08" · "17 Oct, 05:08". */
export function snapshotTime(iso: string): string {
    const date = new Date(iso);
    const now = new Date();
    const day = new Date(date.getFullYear(), date.getMonth(), date.getDate());
    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const diff = Math.round((today.getTime() - day.getTime()) / 86_400_000);
    const time = date.toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
    });

    if (diff === 0) {
        return `today, ${time}`;
    }

    if (diff === 1) {
        return `yesterday, ${time}`;
    }

    return `${date.toLocaleDateString([], { day: 'numeric', month: 'short' })}, ${time}`;
}

function capitalise(text: string): string {
    return text.charAt(0).toUpperCase() + text.slice(1);
}

/**
 * The calm summary on top: are we protected (status word), how (one
 * sentence: schedule, retention, destination) and the one action.
 */
function ProtectionCard({
    data,
    busy,
    backingUp,
    verifying,
    onBackup,
    onVerify,
}: {
    data: Data;
    busy: boolean;
    backingUp: boolean;
    verifying: boolean;
    onBackup: () => void;
    onVerify: () => void;
}) {
    if (data.destinationName === null) {
        return (
            <div className="flex flex-col gap-3 rounded-xl border border-dashed p-5">
                <BackupHealthStatus health={data.status} />
                <p className="type-body text-muted-foreground">
                    Add a place for backups and this database is backed up
                    daily.
                </p>
                <div>
                    <Button asChild size="sm" variant="outline">
                        <Link href={backupSettings()}>Set up backups</Link>
                    </Button>
                </div>
            </div>
        );
    }

    const schedule = data.schedule;
    const facts: { label: string; value: ReactNode }[] = [
        {
            label: 'Next backup',
            value:
                schedule?.enabled && schedule.nextRunAt ? (
                    <TimeAgo value={schedule.nextRunAt} />
                ) : schedule?.enabled === false ? (
                    'Paused'
                ) : (
                    'Not scheduled'
                ),
        },
        { label: 'Keeps', value: schedule?.retentionSummary ?? '—' },
        { label: 'Stored in', value: data.destinationName },
        {
            label: 'Last verified',
            value: (
                <VerifiedFact
                    verification={data.verification}
                    canVerify={
                        data.canVerify && data.status.lastSuccessfulAt !== null
                    }
                    busy={busy}
                    verifying={verifying}
                    onVerify={onVerify}
                />
            ),
        },
    ];

    return (
        <div className="overflow-hidden rounded-xl border bg-card shadow-card">
            <div className="flex flex-wrap items-start justify-between gap-4 p-5">
                <div className="flex min-w-0 flex-col gap-1">
                    <BackupHealthStatus
                        health={data.status}
                        className="type-heading"
                    />
                    <p className="type-small text-muted-foreground">
                        {schedule === null
                            ? 'Only when you back up by hand.'
                            : capitalise(
                                  schedule.cronDescription ??
                                      `on a custom schedule (${schedule.cron} UTC)`,
                              )}
                    </p>
                </div>
                {data.canBackup && (
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={busy}
                        onClick={onBackup}
                    >
                        {backingUp && <Spinner />}
                        Back up now
                    </Button>
                )}
            </div>
            {data.status.lastError !== null && (
                <p className="border-t px-5 py-3 type-mono-small break-words text-status-error">
                    {data.status.lastError}
                </p>
            )}
            <dl className="grid grid-cols-1 gap-3 border-t bg-recessed px-5 py-3 sm:grid-cols-2 lg:grid-cols-4">
                {facts.map((fact) => (
                    <div key={fact.label} className="flex min-w-0 flex-col">
                        <dt className="type-small text-muted-foreground">
                            {fact.label}
                        </dt>
                        <dd className="truncate type-body">{fact.value}</dd>
                    </div>
                ))}
            </dl>
        </div>
    );
}

/**
 * "Last verified": when the newest backup was last restored into a
 * throwaway database and checked (docs/14 §10.4), with the one action.
 */
function VerifiedFact({
    verification,
    canVerify,
    busy,
    verifying,
    onVerify,
}: {
    verification: Verification | null;
    canVerify: boolean;
    busy: boolean;
    verifying: boolean;
    onVerify: () => void;
}) {
    const running =
        verification !== null &&
        (verification.status === 'pending' ||
            verification.status === 'running');

    return (
        <span className="flex min-w-0 flex-col items-start">
            {verification === null ? (
                <span className="text-muted-foreground">Not yet</span>
            ) : running ? (
                <Status state="progress" label="Verifying" />
            ) : (
                <span className="inline-flex min-w-0 items-center gap-1.5">
                    <StatusIcon
                        state={verificationStates[verification.status]}
                    />
                    <span className="truncate">
                        {verification.status === 'passed' ? '' : 'Failed '}
                        <TimeAgo
                            value={
                                verification.finishedAt ??
                                verification.createdAt ??
                                ''
                            }
                        />
                    </span>
                </span>
            )}
            {canVerify && !running && (
                <Button
                    size="sm"
                    variant="link"
                    className="h-auto p-0 type-small"
                    disabled={busy}
                    onClick={onVerify}
                >
                    {verifying && <Spinner />}
                    Verify now
                </Button>
            )}
        </span>
    );
}

/** The newest verification in History: what was restored and what the checks found. */
function VerificationItem({ verification }: { verification: Verification }) {
    const running =
        verification.status === 'pending' || verification.status === 'running';
    const findings = [
        verification.tables !== null
            ? `${verification.tables} ${verification.tables === 1 ? 'table' : 'tables'}`
            : null,
        verification.rows !== null
            ? `${verification.rows.toLocaleString()} ${verification.rows === 1 ? 'row' : 'rows'}`
            : null,
        verification.files !== null
            ? `${verification.files.toLocaleString()} ${verification.files === 1 ? 'file' : 'files'}`
            : null,
        verification.restoredTo !== null
            ? `replayed to ${pointLabel(verification.restoredTo)}`
            : null,
    ].filter((part): part is string => part !== null);

    return (
        <li className="flex flex-col gap-1 px-4 py-3">
            <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                <Status
                    state={verificationStates[verification.status]}
                    label={
                        running
                            ? `Verifying snapshot ${verification.shortSnapshotId}`
                            : verification.status === 'passed'
                              ? `Verified restorable · snapshot ${verification.shortSnapshotId}`
                              : `Verification failed · snapshot ${verification.shortSnapshotId}`
                    }
                />
                <span className="type-small text-muted-foreground">
                    {verification.createdAt !== null && (
                        <TimeAgo value={verification.createdAt} />
                    )}
                </span>
            </div>
            {findings.length > 0 && (
                <p className="type-small text-muted-foreground">
                    Restored into a throwaway database · {findings.join(' · ')}
                </p>
            )}
            {verification.error !== null && (
                <p className="type-mono-small break-words text-muted-foreground">
                    {verification.error}
                </p>
            )}
        </li>
    );
}

/**
 * Snapshots as a vertical timeline: a dot on a hairline rail, when, why,
 * how big. Picking one (radio) sets what "Restore" restores. With
 * point-in-time recovery the newest entry is "Any point in time": its
 * stretch of the rail is solid, and picking it opens the time picker.
 */
function SnapshotTimeline({
    snapshots,
    selectedId,
    onSelect,
    more,
    onShowAll,
    pointInTime,
}: {
    snapshots: Snapshot[];
    selectedId: string | null;
    onSelect?: (id: string) => void;
    more: number;
    onShowAll: () => void;
    pointInTime: {
        pitr: App.Data.Backups.PointInTimeData;
        selected: boolean;
        value: string | null;
        onChange: (value: string | null) => void;
    } | null;
}) {
    return (
        <div className="flex flex-col gap-2">
            <div
                role={onSelect ? 'radiogroup' : 'list'}
                aria-label="Snapshots"
                className="relative flex flex-col"
            >
                <span
                    aria-hidden="true"
                    className="absolute top-4 bottom-4 left-[19px] w-px bg-border"
                />
                {pointInTime !== null && (
                    <div role={onSelect ? undefined : 'listitem'}>
                        {(() => {
                            const active = pointInTime.selected;
                            const body = (
                                <>
                                    <span
                                        aria-hidden="true"
                                        className={cn(
                                            'relative z-10 size-2.5 shrink-0 rounded-full border-2 transition-colors duration-fast',
                                            active
                                                ? 'border-signal bg-signal'
                                                : 'border-foreground bg-card',
                                        )}
                                    />
                                    <span className="flex min-w-0 flex-1 flex-col">
                                        <span className="type-body">
                                            Any point in time
                                        </span>
                                        <span className="truncate type-small text-muted-foreground">
                                            {pointInTime.pitr.windowStart !==
                                                null &&
                                                `From ${pointLabel(pointInTime.pitr.windowStart)} to `}
                                            {windowEndText(pointInTime.pitr) ??
                                                (pointInTime.pitr
                                                    .lastArchivedAt !== null ? (
                                                    <TimeAgo
                                                        value={
                                                            pointInTime.pitr
                                                                .lastArchivedAt
                                                        }
                                                    />
                                                ) : (
                                                    'now'
                                                ))}
                                        </span>
                                    </span>
                                    <span className="type-small text-muted-foreground">
                                        Continuous
                                    </span>
                                </>
                            );

                            return onSelect ? (
                                <button
                                    type="button"
                                    role="radio"
                                    aria-checked={active}
                                    onClick={() => onSelect(POINT_IN_TIME)}
                                    className={cn(
                                        'flex w-full items-center gap-4 rounded-lg px-3.5 py-2.5 text-left transition-colors duration-fast focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                                        active
                                            ? 'bg-hover'
                                            : 'hover:bg-hover/60',
                                    )}
                                >
                                    {body}
                                </button>
                            ) : (
                                <div className="flex items-center gap-4 px-3.5 py-2.5">
                                    {body}
                                </div>
                            );
                        })()}
                        {pointInTime.selected && (
                            <PointInTimePicker
                                pitr={pointInTime.pitr}
                                value={pointInTime.value}
                                onChange={pointInTime.onChange}
                            />
                        )}
                    </div>
                )}
                {snapshots.map((snapshot) => {
                    const active = snapshot.id === selectedId;
                    const body = (
                        <>
                            <span
                                aria-hidden="true"
                                className={cn(
                                    'relative z-10 size-2.5 shrink-0 rounded-full border-2 bg-card transition-colors duration-fast',
                                    active
                                        ? 'border-signal bg-signal'
                                        : 'border-border-strong',
                                )}
                            />
                            <span className="flex min-w-0 flex-1 flex-col">
                                <span className="type-body">
                                    {capitalise(snapshotTime(snapshot.time))}
                                </span>
                                <span className="truncate type-small text-muted-foreground">
                                    {reasonLabel(snapshot.reason)}
                                    {snapshot.mountPath !== null &&
                                        ` · ${snapshot.mountPath}`}{' '}
                                    ·{' '}
                                    <span className="type-mono-small">
                                        {snapshot.shortId}
                                    </span>
                                </span>
                            </span>
                            <span className="type-small text-muted-foreground tabular-nums">
                                {formatBytes(snapshot.sizeBytes)}
                            </span>
                        </>
                    );

                    return (
                        <div
                            key={snapshot.id}
                            role={onSelect ? undefined : 'listitem'}
                        >
                            {onSelect ? (
                                <button
                                    type="button"
                                    role="radio"
                                    aria-checked={active}
                                    onClick={() => onSelect(snapshot.id)}
                                    className={cn(
                                        'flex w-full items-center gap-4 rounded-lg px-3.5 py-2.5 text-left transition-colors duration-fast focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                                        active
                                            ? 'bg-hover'
                                            : 'hover:bg-hover/60',
                                    )}
                                >
                                    {body}
                                </button>
                            ) : (
                                <div className="flex items-center gap-4 px-3.5 py-2.5">
                                    {body}
                                </div>
                            )}
                        </div>
                    );
                })}
            </div>
            {more > 0 && (
                <div>
                    <Button variant="ghost" size="sm" onClick={onShowAll}>
                        Show {more} older
                    </Button>
                </div>
            )}
        </div>
    );
}
