import { Link } from '@inertiajs/react';
import {
    Archive,
    Check,
    ChevronRight,
    CopyPlus,
    Download,
    History,
    RefreshCw,
    TriangleAlert,
    X,
} from 'lucide-react';
import { useCallback, useState } from 'react';
import type { ReactNode } from 'react';
import { toast } from 'sonner';
import { MetaLine } from '@/components/patterns/meta-line';
import type { StatusState } from '@/components/status';
import { EmptyState } from '@/components/patterns/empty-state';
import { OptionCards } from '@/components/patterns/option-cards';
import { Section } from '@/components/patterns/section';
import { Status, StatusIcon, backupRunStatus } from '@/components/status';
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
import { RemoteAccessButton } from '@/features/remote-access/remote-access';
import { RequestFailed, requestJson } from '@/lib/http';
import { BackupHealthStatus } from '@/features/backups/backup-health';
import { BackupTrend } from '@/features/backups/backup-trend';
import { PointInTimeCard, pointLabel } from '@/features/backups/point-in-time';
import { reasonLabel, zonedTime } from '@/features/backups/format';
import { groupByDay } from '@/features/backups/timeline';
import { ActionReason } from '@/features/backups/action-reason';
import { runButtonLabel } from '@/features/backups/overview-model';
import { RunProgress } from '@/features/backups/run-progress';
import {
    backupsKey,
    useServiceBackups,
} from '@/features/backups/use-service-backups';
import { cn } from '@/lib/utils';
import { vocabulary } from '@/lib/vocabulary';
import { PendingDeletion } from '@/features/backups/pending-deletion';
import { MoveDataSection } from '@/features/data-transfer/move-data-section';
import type { SnapshotDownload } from '@/features/data-transfer/move-data-section';
import servicePanel from '@/routes/projects/services';
import { index as backupSettings } from '@/routes/backups';
import { formatBytes } from '@/lib/format';
import { useQueryClient } from '@tanstack/react-query';
import { ServiceActivityEvents } from '@/components/channel-events';

type Data = App.Data.Backups.ServiceBackupsData;
type Snapshot = App.Data.Backups.SnapshotData;
type RestoreRun = App.Data.Backups.RestoreRunData;
type Verification = App.Data.Backups.BackupVerificationData;

type BackupsTabProps = {
    /** Public ids: prj_…, env_…, svc_… */
    project: string;
    environment: string;
    service: string;
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
    waiting_for_safety_backup: 'Backing up the current data first',
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

function isVerifying(verification: Verification): boolean {
    return !vocabulary.Backups.VerificationStatus[verification.status]
        .isTerminal;
}

/**
 * Service settings → Backups for databases and services with volumes
 * (docs/08 §16, docs/14 §2, §5.1; design-system §13.9 "Backups"): a protection
 * card (health, schedule sentence, Back up now), point-in-time recovery with
 * its own Restore…, the snapshot timeline where each snapshot carries its
 * own Restore…, and one history list of restores and runs. The restore
 * dialog holds the choice (as a new service, default, or into this service
 * with typed confirmation). A staged removal waiting for its final backup
 * and a fresh "restored copy is ready" card sit on top.
 *
 * The body of the settings page (`BackupsSection`). Fetches its own data (JSON,
 * team-scoped on the server, shared query cache) and refetches on `service.activity`.
 */
export function ServiceBackups({
    project,
    environment,
    service,
}: BackupsTabProps) {
    const [busyAction, setBusyAction] = useState<string | null>(null);
    const [restoring, setRestoring] = useState<Restoring | null>(null);
    const [swapping, setSwapping] = useState<RestoreRun | null>(null);
    const [allSnapshots, setAllSnapshots] = useState(false);
    const [snapshotDownload, setSnapshotDownload] =
        useState<SnapshotDownload | null>(null);
    const clearSnapshotDownload = useCallback(
        () => setSnapshotDownload(null),
        [],
    );
    const queryClient = useQueryClient();
    const ref = { project, environment, service };
    const key = backupsKey(ref);
    const query = useServiceBackups(ref);
    const data = query.data ?? null;
    const error =
        query.error === null
            ? null
            : query.error instanceof RequestFailed
              ? query.error.message
              : 'Backups could not be loaded.';
    const setData = (next: Data) => queryClient.setQueryData(key, next);

    const act = async (
        name: string,
        target: string,
        body?: unknown,
        success?: string,
        method: 'post' | 'put' = 'post',
    ) => {
        setBusyAction(name);

        try {
            setData(await requestJson<Data>(method, target, body));

            if (success) {
                toast.success(success);
            }

            return true;
        } catch (e) {
            toast.error(
                e instanceof RequestFailed ? e.message : 'The request failed.',
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
    const shownSnapshots = allSnapshots
        ? data.snapshots
        : data.snapshots.slice(0, 6);
    const defaultMode: App.Enums.Backups.RestoreMode = data.canRestoreAsNew
        ? 'new_service'
        : 'in_place';

    return (
        <div className="flex flex-col gap-8">
            <ServiceActivityEvents
                environment={environment}
                service={service}
                topic="backups"
                onChange={() =>
                    void queryClient.invalidateQueries({ queryKey: key })
                }
            />
            {data.deletion !== null && (
                <PendingDeletion
                    request={data.deletion}
                    onChange={() => void query.refetch()}
                />
            )}

            <RestoredCard
                data={data}
                project={project}
                environment={environment}
                onSwap={setSwapping}
            />

            <ProtectionCard
                data={data}
                busy={busyAction !== null}
                backingUp={busyAction === 'backup'}
                verifying={busyAction === 'verify'}
                onBackup={() =>
                    void act(
                        'backup',
                        data.backUp?.url ?? data.urls.backup,
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
                        onRestore={
                            canRestore
                                ? (at) =>
                                      setRestoring({
                                          pointInTime: at ?? 'newest',
                                          mode: defaultMode,
                                      })
                                : undefined
                        }
                    />
                )}

            {volumes && data.volumes.length > 0 && (
                <Section
                    level={4}
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
                                    <span className="flex flex-wrap items-center gap-2">
                                        {volume.server !== null && (
                                            <RemoteAccessButton
                                                serviceRef={{
                                                    project,
                                                    environment,
                                                    service,
                                                }}
                                                kind="volume"
                                                volume={volume.id}
                                                variant="ghost"
                                                size="sm"
                                            />
                                        )}
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
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </div>
                </Section>
            )}

            <Section
                level={4}
                data-setting="snapshots"
                title="Snapshots"
                description={
                    data.snapshots.length > 0
                        ? `${data.snapshots.length} kept, newest first`
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

                {data.snapshots.length === 0 ? (
                    <EmptyState icon={Archive}>
                        Snapshots appear here after the first backup.
                    </EmptyState>
                ) : (
                    <SnapshotTimeline
                        snapshots={shownSnapshots}
                        onRestore={
                            canRestore
                                ? (snapshot) =>
                                      setRestoring({
                                          snapshot,
                                          mode: defaultMode,
                                      })
                                : undefined
                        }
                        onDownload={
                            data.canRestoreInPlace
                                ? (snapshot) =>
                                      setSnapshotDownload({
                                          id: snapshot.id,
                                          label: snapshot.id.slice(0, 8),
                                      })
                                : undefined
                        }
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
                        Restoring {data.engine} from the dashboard isn’t
                        available yet. The snapshots are in your bucket, where
                        you can restore them with restic.
                    </p>
                )}
            </Section>

            <MoveDataSection
                environment={environment}
                service={service}
                serviceInstanceId={data.serviceInstanceId}
                snapshotDownload={snapshotDownload}
                onSnapshotDownloadHandled={clearSnapshotDownload}
                canRestore={data.canRestoreInPlace}
                onRestoreSnapshot={(id) => {
                    const found = data.snapshots.find(
                        (snapshot) => snapshot.id === id,
                    );

                    if (found === undefined) {
                        toast.error(
                            'The snapshot isn’t in the list yet. Refresh the page and pick it under Snapshots.',
                        );

                        return;
                    }

                    setRestoring({ snapshot: found, mode: 'in_place' });
                }}
            />

            {data.trend.length >= 2 && (
                <Section
                    level={4}
                    title="Trend"
                    description={`Last ${data.trend.length} backups`}
                >
                    <BackupTrend points={data.trend} />
                </Section>
            )}

            {(data.restores.length > 0 ||
                data.runs.length > 0 ||
                data.verification !== null) && (
                <Section level={4} title="History">
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
                                        }`}
                                        detail={
                                            restore.restoredTo !== null
                                                ? `to ${pointLabel(restore.restoredTo)}`
                                                : restore.pointInTime
                                                  ? restore.requestedTime !==
                                                    null
                                                      ? `to ${pointLabel(restore.requestedTime)}`
                                                      : 'to the newest point'
                                                  : undefined
                                        }
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
                                        state={
                                            backupRunStatus[run.status].state
                                        }
                                        label={`${subjectLabels[run.subject] !== '' ? `${subjectLabels[run.subject]} (${reasonLabel(run.reason).toLowerCase()})` : `${reasonLabel(run.reason)} backup`} ${backupRunStatus[run.status].label.toLowerCase()}`}
                                    />
                                    <MetaLine className="type-small text-muted-foreground tabular-nums">
                                        {run.sizeBytes !== null &&
                                            formatBytes(run.sizeBytes)}
                                        <TimeAgo value={run.createdAt} />
                                    </MetaLine>
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
                onModeChange={(mode) =>
                    setRestoring((current) =>
                        current === null ? null : { ...current, mode },
                    )
                }
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
                            : 'Restore started: backing up the current data first.',
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

/** The name StartRestore gives the copy (`<name>-restored-<date>`, docs/14 §9). */
function restoredName(serviceName: string): string {
    // The server names it by its own (UTC) date.
    return `${serviceName.slice(0, 40)}-restored-${new Date().toISOString().slice(0, 10)}`;
}

/**
 * The restore dialog (docs/14 §9, DAT-1/2): how (option cards, "As a new
 * service" first), the facts (from when, size, the new service's name) and
 * a short list of what will happen. Into the service asks for the typed
 * name; as a new service doesn't, since nothing existing changes.
 */
function RestoreDialog({
    data,
    restoring,
    busy,
    onModeChange,
    onClose,
    onConfirm,
}: {
    data: Data;
    restoring: Restoring | null;
    busy: boolean;
    onModeChange: (mode: App.Enums.Backups.RestoreMode) => void;
    onClose: () => void;
    onConfirm: (confirmation: string | null) => Promise<void>;
}) {
    const [typed, setTyped] = useState('');
    const inPlace = restoring?.mode === 'in_place';
    const name = data.serviceName;
    const volume = data.subject === 'volume';
    const copyName = restoredName(name);
    const from =
        restoring === null
            ? ''
            : 'snapshot' in restoring
              ? zonedTime(restoring.snapshot.time)
              : restoring.pointInTime === 'newest'
                ? 'The newest point'
                : zonedTime(restoring.pointInTime);
    const size =
        restoring !== null && 'snapshot' in restoring
            ? restoring.snapshot.sizeBytes
            : null;
    const facts: { label: string; value: ReactNode }[] = [
        {
            label: 'From',
            value:
                restoring !== null && !('snapshot' in restoring) ? (
                    <>
                        {from}
                        <span className="block type-small text-muted-foreground">
                            Point in time
                        </span>
                    </>
                ) : (
                    from
                ),
        },
        ...(size !== null ? [{ label: 'Size', value: formatBytes(size) }] : []),
        ...(restoring !== null &&
        'snapshot' in restoring &&
        restoring.snapshot.mountPath !== null
            ? [{ label: 'Volume', value: restoring.snapshot.mountPath }]
            : []),
        inPlace
            ? { label: 'Into', value: name }
            : { label: 'New service', value: copyName },
    ];
    const steps: { text: string; warn?: boolean }[] = inPlace
        ? [
              ...(data.inPlaceTakesSafetyCopy
                  ? [
                        {
                            text: `A backup of ${name} as it is now is taken first, so you can go back.`,
                        },
                    ]
                  : []),
              {
                  text: volume
                      ? `${name} stops while the files in its volume are replaced.`
                      : `${name} stops while its data is replaced, and so do the services connected to it.`,
                  warn: true,
              },
              {
                  text: 'Everything starts again when the restore is done.',
              },
          ]
        : [
              { text: `${name} keeps running and isn’t changed.` },
              {
                  text: volume
                      ? `${copyName} is added in this environment, with the same settings and the restored files.`
                      : `${copyName} is added in this environment with the restored data.`,
              },
              {
                  text: `You can look at it first, then swap it in for ${name}.`,
              },
          ];

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
            <DialogContent className="sm:max-w-lg">
                <DialogTitle>Restore {name}</DialogTitle>
                <DialogDescription className="sr-only">
                    Pick how to restore, check what will happen, then confirm.
                </DialogDescription>

                {data.canRestoreAsNew && data.canRestoreInPlace && (
                    <OptionCards
                        label="How to restore"
                        value={restoring?.mode ?? 'new_service'}
                        onChange={(mode) => {
                            setTyped('');
                            onModeChange(mode);
                        }}
                        options={[
                            {
                                value: 'new_service' as const,
                                title: 'As a new service',
                                description: 'Nothing existing changes.',
                                media: (
                                    <CopyPlus
                                        aria-hidden="true"
                                        className="size-5 text-muted-foreground"
                                    />
                                ),
                            },
                            {
                                value: 'in_place' as const,
                                title: `Into ${name}`,
                                description: 'Replaces its data.',
                                media: (
                                    <History
                                        aria-hidden="true"
                                        className="size-5 text-muted-foreground"
                                    />
                                ),
                            },
                        ]}
                    />
                )}

                <dl className="grid grid-cols-[auto_minmax(0,1fr)] gap-x-6 gap-y-2 rounded-lg border bg-recessed px-4 py-3">
                    {facts.map((fact) => (
                        <div key={fact.label} className="contents">
                            <dt className="type-small text-muted-foreground">
                                {fact.label}
                            </dt>
                            <dd className="type-mono-small break-words">
                                {fact.value}
                            </dd>
                        </div>
                    ))}
                </dl>

                <ul className="flex flex-col gap-1.5" aria-label="What happens">
                    {steps.map((step) => (
                        <li
                            key={step.text}
                            className={cn(
                                'flex items-start gap-2 type-body',
                                step.warn
                                    ? 'text-status-progress'
                                    : 'text-foreground',
                            )}
                        >
                            {step.warn ? (
                                <TriangleAlert
                                    aria-hidden="true"
                                    className="mt-0.5 size-4 shrink-0"
                                />
                            ) : (
                                <Check
                                    aria-hidden="true"
                                    className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                                />
                            )}
                            <span>{step.text}</span>
                        </li>
                    ))}
                </ul>

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

const DISMISSED_KEY = 'launchroom.backups.restored-dismissed';

function readDismissed(): string[] {
    try {
        const raw = window.localStorage.getItem(DISMISSED_KEY);
        const parsed: unknown = raw === null ? [] : JSON.parse(raw);

        return Array.isArray(parsed)
            ? parsed.filter((v): v is string => typeof v === 'string')
            : [];
    } catch {
        return [];
    }
}

/**
 * After a restore as a new service (DAT-7): the copy is ready, and the two
 * next steps (look at its data, swap it in) sit right here instead of in
 * History. Shown for a day after it finished; dismissing it is remembered
 * in this browser.
 */
function RestoredCard({
    data,
    project,
    environment,
    onSwap,
}: {
    data: Data;
    project: string;
    environment: string;
    onSwap: (restore: RestoreRun) => void;
}) {
    const [dismissed, setDismissed] = useState(readDismissed);
    const restore = data.restores.find(
        (r) => r.mode === 'new_service' && r.status === 'succeeded',
    );

    if (
        restore === undefined ||
        restore !== data.restores[0] ||
        restore.targetServiceId === null ||
        restore.finishedAt === null ||
        Date.now() - new Date(restore.finishedAt).getTime() > 86_400_000 ||
        dismissed.includes(restore.id)
    ) {
        return null;
    }

    const copy = restore.targetServiceName ?? 'The restored copy';
    const dismiss = () => {
        const next = [...dismissed, restore.id].slice(-20);
        setDismissed(next);

        try {
            window.localStorage.setItem(DISMISSED_KEY, JSON.stringify(next));
        } catch {
            // Remembering is a convenience; the card still hides now.
        }
    };
    const target = {
        project,
        environment,
        service: restore.targetServiceId,
    };

    return (
        <div className="overflow-hidden rounded-xl border bg-card shadow-card">
            <div className="flex items-start justify-between gap-3 px-5 pt-4 pb-3">
                <div className="flex min-w-0 flex-col gap-0.5">
                    <Status state="active" label={`${copy} is ready`} />
                    <p className="type-small text-muted-foreground">
                        {restore.restoredTo !== null
                            ? `Restored to ${zonedTime(restore.restoredTo)}`
                            : restore.pointInTime
                              ? restore.requestedTime !== null
                                  ? `Restored to ${zonedTime(restore.requestedTime)}`
                                  : 'Restored to the newest point'
                              : `Restored from snapshot ${restore.shortSnapshotId}`}
                    </p>
                </div>
                <Button
                    variant="ghost"
                    size="icon-sm"
                    aria-label="Dismiss"
                    onClick={dismiss}
                >
                    <X />
                </Button>
            </div>
            <ul className="divide-y border-t">
                <li>
                    <Link
                        href={servicePanel.show({
                            ...target,
                            tab:
                                data.subject === 'volume' ? 'settings' : 'data',
                        })}
                        className="flex items-center justify-between gap-3 px-5 py-3 type-body transition-colors duration-fast hover:bg-hover"
                    >
                        {data.subject === 'volume'
                            ? `Open ${copy}`
                            : 'Look at its data'}
                        <ChevronRight
                            aria-hidden="true"
                            className="size-4 text-muted-foreground"
                        />
                    </Link>
                </li>
                {restore.swapUrl !== null && (
                    <li>
                        <button
                            type="button"
                            onClick={() => onSwap(restore)}
                            className="flex w-full items-center justify-between gap-3 px-5 py-3 text-left type-body transition-colors duration-fast hover:bg-hover"
                        >
                            {`Swap it in for ${data.serviceName}…`}
                            <ChevronRight
                                aria-hidden="true"
                                className="size-4 text-muted-foreground"
                            />
                        </button>
                    </li>
                )}
            </ul>
        </div>
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
                    The change is staged, so nothing happens until you deploy,
                    and Discard undoes it. Other environments keep{' '}
                    {data.serviceName}, and its volume is kept.
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
function snapshotTime(iso: string): string {
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
                    {data.subject === 'volume'
                        ? 'Connect storage to back up these files daily.'
                        : 'Connect storage to back up this database daily.'}
                </p>
                <div>
                    <Button asChild size="sm" variant="outline">
                        <Link href={`${backupSettings().url}#setup`}>
                            Connect storage
                        </Link>
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
                {data.inFlight.length > 0 ? (
                    // The button is the progress while a run is queued or
                    // in flight, so it can't be started twice.
                    <Button
                        size="sm"
                        variant="outline"
                        disabled
                        className="disabled:opacity-100"
                    >
                        <Spinner />
                        {runButtonLabel(data.inFlight[0])}
                    </Button>
                ) : (
                    data.backUp !== null &&
                    data.backUp.url !== null && (
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={busy}
                            onClick={onBackup}
                        >
                            {backingUp && <Spinner />}
                            Back up now
                        </Button>
                    )
                )}
            </div>
            {(data.inFlight.length > 0 ||
                (data.backUp?.disabled != null &&
                    data.backUp.disabled.code !== 'running')) && (
                <div className="flex flex-col gap-2 border-t px-5 py-3">
                    {data.inFlight.map((run) => (
                        <RunProgress
                            key={run.runId}
                            kind={
                                data.subject === 'volume'
                                    ? 'volume'
                                    : 'database'
                            }
                            run={run}
                        />
                    ))}
                    {data.inFlight.length === 0 &&
                        data.backUp?.disabled != null && (
                            <ActionReason reason={data.backUp.disabled} />
                        )}
                </div>
            )}
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
    const running = isVerifying(verification);
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
                              ? `Snapshot ${verification.shortSnapshotId} verified restorable`
                              : `Snapshot ${verification.shortSnapshotId} failed verification`
                    }
                />
                <span className="type-small text-muted-foreground">
                    {verification.createdAt !== null && (
                        <TimeAgo value={verification.createdAt} />
                    )}
                </span>
            </div>
            {findings.length > 0 && (
                <MetaLine className="type-small text-muted-foreground">
                    Restored into a throwaway database
                    {findings}
                </MetaLine>
            )}
            {verification.error !== null && (
                <p className="type-mono-small break-words text-muted-foreground">
                    {verification.error}
                </p>
            )}
        </li>
    );
}

/** "05:08": a restore point's time inside its day group. */
function clockTime(iso: string): string {
    return new Date(iso).toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
    });
}

/**
 * Snapshots as a vertical timeline grouped by day (Today, Yesterday,
 * Monday 3 November): a dot on a hairline rail, when, why, how big, and
 * the snapshot's own Restore… (and download) at the end of its row, so
 * restoring starts from the thing restored from (DAT-1).
 */
function SnapshotTimeline({
    snapshots,
    onRestore,
    onDownload,
    more,
    onShowAll,
}: {
    snapshots: Snapshot[];
    onRestore?: (snapshot: Snapshot) => void;
    onDownload?: (snapshot: Snapshot) => void;
    more: number;
    onShowAll: () => void;
}) {
    const days = groupByDay(snapshots, (snapshot) => snapshot.time);

    return (
        <div className="flex flex-col gap-4">
            {days.map((day) => (
                <section
                    key={day.key}
                    aria-label={day.label}
                    className="flex flex-col gap-1"
                >
                    <h4 className="px-3.5 type-label text-muted-foreground">
                        {day.label}
                    </h4>
                    <ul className="relative flex flex-col">
                        <span
                            aria-hidden="true"
                            className="absolute top-4 bottom-4 left-[19px] w-px bg-border"
                        />
                        {day.items.map((snapshot) => (
                            <li
                                key={snapshot.id}
                                className="flex items-center gap-4 rounded-lg px-3.5 py-2 transition-colors duration-fast hover:bg-hover/60"
                            >
                                <span
                                    aria-hidden="true"
                                    className="relative z-10 size-2.5 shrink-0 rounded-full border-2 border-border-strong bg-card"
                                />
                                <span className="flex min-w-0 flex-1 flex-col">
                                    <span className="type-body tabular-nums">
                                        {clockTime(snapshot.time)}
                                    </span>
                                    <MetaLine className="type-small text-muted-foreground">
                                        {reasonLabel(snapshot.reason)}
                                        {snapshot.mountPath}
                                        <span className="type-mono-small">
                                            {snapshot.shortId}
                                        </span>
                                        {snapshot.sizeBytes !== null && (
                                            <span className="tabular-nums">
                                                {formatBytes(
                                                    snapshot.sizeBytes,
                                                )}
                                            </span>
                                        )}
                                    </MetaLine>
                                </span>
                                <span className="flex shrink-0 items-center gap-1">
                                    {onDownload && (
                                        <Button
                                            variant="ghost"
                                            size="icon-sm"
                                            aria-label={`Download the snapshot from ${snapshotTime(snapshot.time)}`}
                                            title="Download this backup"
                                            onClick={() => onDownload(snapshot)}
                                        >
                                            <Download />
                                        </Button>
                                    )}
                                    {onRestore && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            aria-label={`Restore the snapshot from ${snapshotTime(snapshot.time)}`}
                                            onClick={() => onRestore(snapshot)}
                                        >
                                            Restore…
                                        </Button>
                                    )}
                                </span>
                            </li>
                        ))}
                    </ul>
                </section>
            ))}
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
