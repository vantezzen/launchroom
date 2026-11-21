import {
    ArrowRight,
    Archive,
    Check,
    DatabaseBackup,
    History,
    ShieldAlert,
    TriangleAlert,
    Zap,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { toast } from 'sonner';
import { OptionCards } from '@/components/patterns/option-cards';
import { SheetSection, SidePanel } from '@/components/patterns/sheet';
import { Status } from '@/components/status';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import {
    inspection as inspectionRoute,
    options as optionsRoute,
} from '@/routes/transfers/services/copies';
import { RequestFailed, requestJson } from '@/lib/http';
import {
    formatBytes,
    formatEstimate,
    listNames,
    relativeTime,
} from '@/lib/format';
import { cn } from '@/lib/utils';
import type {
    CopyInspection,
    CopyOptions,
    CopySource,
    Inspection,
    ServiceTransfers,
} from './api';

type Point = App.Enums.Transfers.CopySourcePoint;
type Backup = App.Data.Transfers.CopyBackupData;

export type CopyTarget = {
    /** The target volume (services with volumes); null for a database. */
    volume: App.Data.Transfers.TransferVolumeData | null;
};

type Checked = {
    state: 'idle' | 'pending' | 'done' | 'failed';
    target?: Inspection;
    source?: Inspection | null;
    message?: string;
};

function messageOf(error: unknown, fallback: string): string {
    return error instanceof RequestFailed || error instanceof Error
        ? error.message
        : fallback;
}

function backupLabel(backup: Backup): string {
    const when = new Date(backup.takenAt).toLocaleString([], {
        dateStyle: 'medium',
        timeStyle: 'short',
    });

    return backup.sizeBytes !== null
        ? `${when}, ${formatBytes(backup.sizeBytes)}`
        : when;
}

async function waitFor(
    serviceInstance: string,
    id: string,
    source: string | null,
    signal: AbortSignal,
): Promise<Inspection> {
    for (let attempt = 0; attempt < 120; attempt++) {
        const result = await requestJson<Inspection>(
            'get',
            inspectionRoute(
                { serviceInstance, task: id },
                source === null ? undefined : { query: { source } },
            ).url,
            undefined,
            signal,
        );

        if (result.status !== 'pending') {
            return result;
        }

        await new Promise((resolve) => window.setTimeout(resolve, 1500));
    }

    throw new Error('The server didn’t answer in time. Try again.');
}

/** The backups of a source that fit the target (its volume, for volumes). */
function backupsFor(
    source: CopySource | null,
    mountPath: string | null,
): Backup[] {
    if (source === null || !source.backupsReadable) {
        return [];
    }

    return source.backups.filter(
        (backup) => mountPath === null || backup.mountPath === mountPath,
    );
}

/** Why a source can't be used for this target, or null. */
function refusalOf(
    source: CopySource,
    mountPath: string | null,
): string | null {
    if (source.refusal !== null) {
        return source.refusal;
    }

    if (mountPath !== null && !source.mountPaths.includes(mountPath)) {
        return `No volume at ${mountPath} in ${source.environmentName}.`;
    }

    return null;
}

/** "From" and "into" as two environment tiles with an arrow between. */
function Direction({
    from,
    fromDetail,
    into,
    intoDetail,
}: {
    from: CopySource | null;
    fromDetail: string | null;
    into: string;
    intoDetail: string | null;
}) {
    const tile = (
        label: string,
        name: string | null,
        detail: string | null,
        production: boolean,
    ) => (
        <div className="flex min-w-0 flex-1 basis-0 flex-col gap-0.5 rounded-xl border bg-card px-3 py-2.5 sm:px-4 sm:py-3">
            <span className="type-small text-muted-foreground">{label}</span>
            <span className="flex items-center gap-1.5 truncate type-heading">
                {name ?? 'Pick an environment'}
                {production &&
                    name !== null &&
                    name.toLowerCase() !== 'production' && (
                        <span className="rounded-md bg-hover px-1.5 py-0.5 type-small text-muted-foreground">
                            Production
                        </span>
                    )}
            </span>
            {detail !== null && (
                <span className="truncate type-small text-muted-foreground">
                    {detail}
                </span>
            )}
        </div>
    );

    return (
        <div className="flex items-center gap-2">
            {tile(
                'From',
                from?.environmentName ?? null,
                fromDetail,
                from?.environmentKind === 'production',
            )}
            <ArrowRight
                aria-hidden="true"
                className="size-4 shrink-0 text-muted-foreground"
            />
            {tile('Into', into, intoDetail, false)}
        </div>
    );
}

/**
 * Copy data from another environment (D110, docs/14 §14): pick the
 * environment (the same service is matched there), what to read (its
 * latest backup, a backup, or a fresh copy now), check what the target
 * holds and what stops, then confirm. Production as the target asks
 * twice.
 */
export function CopySheet({
    data,
    target,
    onClose,
    onStarted,
}: {
    data: ServiceTransfers;
    target: CopyTarget | null;
    onClose: () => void;
    onStarted: () => void;
}) {
    const open = target !== null;
    const mountPath = target?.volume?.mountPath ?? null;
    const [options, setOptions] = useState<CopyOptions | null>(null);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [sourceId, setSourceId] = useState<string | null>(null);
    const [point, setPoint] = useState<Point>('latest_backup');
    const [snapshot, setSnapshot] = useState<string | null>(null);
    const [checked, setChecked] = useState<Checked>({ state: 'idle' });
    const [typed, setTyped] = useState('');
    const [replaceProduction, setReplaceProduction] = useState(false);
    const [busy, setBusy] = useState(false);

    const close = () => {
        setOptions(null);
        setLoadError(null);
        setSourceId(null);
        setChecked({ state: 'idle' });
        setTyped('');
        setReplaceProduction(false);
        setBusy(false);
        onClose();
    };

    /** A new source starts from its newest backup, or live data without one. */
    const pickSource = (picked: CopySource | null) => {
        const newest = backupsFor(picked, mountPath)[0] ?? null;
        setSourceId(picked?.environmentId ?? null);
        setPoint(newest !== null ? 'latest_backup' : 'live');
        setSnapshot(newest?.snapshot ?? null);
        setTyped('');
    };

    // Load the environments once the sheet opens.
    useEffect(() => {
        if (!open) {
            return;
        }

        const controller = new AbortController();

        requestJson<CopyOptions>(
            'get',
            optionsRoute(data.serviceInstanceId).url,
            undefined,
            controller.signal,
        )
            .then((loaded) => {
                setOptions(loaded);
                const usable = loaded.sources.filter(
                    (source) => refusalOf(source, mountPath) === null,
                );
                // Production first when it may be used: the usual request.
                const first =
                    usable.find(
                        (source) => source.environmentKind === 'production',
                    ) ??
                    usable[0] ??
                    null;
                const newest = backupsFor(first, mountPath)[0] ?? null;
                setSourceId(first?.environmentId ?? null);
                setPoint(newest !== null ? 'latest_backup' : 'live');
                setSnapshot(newest?.snapshot ?? null);
            })
            .catch((error: unknown) => {
                if (!controller.signal.aborted) {
                    setLoadError(
                        messageOf(
                            error,
                            'The environments couldn’t be loaded.',
                        ),
                    );
                }
            });

        return () => controller.abort();
    }, [open, data.serviceInstanceId, mountPath]);

    const source =
        options?.sources.find((item) => item.environmentId === sourceId) ??
        null;
    const backups = backupsFor(source, mountPath);
    const isVolume = options?.subject === 'volume';

    // Look at the target, and at a live database source for its size.
    const inspectSource = point === 'live' && !isVolume ? sourceId : null;

    useEffect(() => {
        if (
            !open ||
            options === null ||
            options.refusal !== null ||
            sourceId === null
        ) {
            return;
        }

        const controller = new AbortController();
        setChecked({ state: 'pending' });

        void (async () => {
            try {
                const started = await requestJson<CopyInspection>(
                    'post',
                    options.urls.inspect,
                    {
                        source: inspectSource,
                        volume: target?.volume?.id ?? null,
                    },
                    controller.signal,
                );
                const [into, from] = await Promise.all([
                    waitFor(
                        data.serviceInstanceId,
                        started.target.id,
                        null,
                        controller.signal,
                    ),
                    started.source === null
                        ? Promise.resolve(null)
                        : waitFor(
                              data.serviceInstanceId,
                              started.source.id,
                              inspectSource,
                              controller.signal,
                          ),
                ]);
                setChecked({
                    state: into.status === 'done' ? 'done' : 'failed',
                    target: into,
                    source: from,
                    message: into.error ?? undefined,
                });
            } catch (error) {
                if (!controller.signal.aborted) {
                    setChecked({
                        state: 'failed',
                        message: messageOf(
                            error,
                            'launchroom couldn’t look at the target.',
                        ),
                    });
                }
            }
        })();

        return () => controller.abort();
    }, [
        open,
        options,
        sourceId,
        inspectSource,
        target?.volume?.id,
        data.serviceInstanceId,
    ]);

    const chosenBackup =
        point === 'latest_backup'
            ? (backups[0] ?? null)
            : point === 'backup'
              ? (backups.find((backup) => backup.snapshot === snapshot) ?? null)
              : null;
    const sizeBytes =
        point === 'live'
            ? (checked.source?.sizeBytes ?? null)
            : (chosenBackup?.sizeBytes ?? null);
    const targetInspection = checked.target ?? null;
    // Fail closed: unknown counts as holding data.
    const holdsData = !(
        checked.state === 'done' && targetInspection?.empty === true
    );
    const production = options?.environmentKind === 'production';
    const environmentName = options?.environmentName ?? '';
    const needsTyped = holdsData || production;
    const dependents = isVolume
        ? [data.serviceName]
        : (options?.dependents.map((dependent) => dependent.name) ?? []);
    const stopSeconds =
        options === null || sizeBytes === null
            ? null
            : options.stopEstimate.baseSeconds +
              Math.ceil(sizeBytes / options.stopEstimate.bytesPerMinute) * 60;
    const what = mountPath ?? data.serviceName;

    const ready =
        options !== null &&
        options.refusal === null &&
        source !== null &&
        refusalOf(source, mountPath) === null &&
        (point === 'live' || chosenBackup !== null) &&
        checked.state !== 'pending' &&
        (!production || replaceProduction) &&
        (!needsTyped || typed === environmentName) &&
        !busy;

    const steps = useMemo(() => {
        if (source === null || options === null) {
            return [];
        }

        const list: { text: string; warn?: boolean }[] = [
            {
                text:
                    point === 'live'
                        ? `Copy the data of ${source.environmentName} as it is now, without stopping it.`
                        : `Read the backup of ${source.environmentName}${chosenBackup ? ` from ${relativeTime(chosenBackup.takenAt)}` : ''}, without any load on ${source.environmentName}.`,
            },
            options.hasDestination
                ? {
                      text: `Take a safety snapshot of ${what} in ${environmentName}, so you can go back.`,
                  }
                : {
                      text: 'No safety snapshot: this team has no backup storage.',
                      warn: true,
                  },
        ];

        if (dependents.length > 0) {
            list.push({
                text: `${listNames(dependents)} ${dependents.length === 1 ? 'stops' : 'stop'} while the data is replaced${stopSeconds !== null ? `, ${formatEstimate(stopSeconds)}` : ''}.`,
                warn: true,
            });
        }

        list.push({
            text: `Replace everything in ${what} in ${environmentName}.`,
        });

        if (dependents.length > 0) {
            list.push({
                text: `Start ${listNames(dependents)} again, also if the copy fails.`,
            });
        }

        return list;
    }, [
        source,
        options,
        point,
        chosenBackup,
        what,
        environmentName,
        dependents,
        stopSeconds,
    ]);

    const submit = async () => {
        if (options === null || source === null) {
            return;
        }

        setBusy(true);

        try {
            await requestJson('post', options.urls.copies, {
                source: source.environmentId,
                point,
                snapshot: point === 'backup' ? snapshot : null,
                volume: target?.volume?.id ?? null,
                confirmation: needsTyped ? typed : null,
                replaceProduction,
            });
            toast.success(
                `Copying from ${source.environmentName}. ${environmentName} keeps running until the data is ready.`,
            );
            close();
            onStarted();
        } catch (error) {
            toast.error(messageOf(error, 'The copy couldn’t start.'));
            setBusy(false);
        }
    };

    const fromDetail =
        source === null
            ? null
            : [
                  source.versionLabel,
                  point === 'live'
                      ? 'live data'
                      : chosenBackup
                        ? `backup from ${relativeTime(chosenBackup.takenAt)}`
                        : null,
              ]
                  .filter((part) => part !== null)
                  .join(', ') || null;

    let body: ReactNode;

    if (loadError !== null) {
        body = (
            <SheetSection>
                <p role="alert" className="type-body text-status-error">
                    {loadError}
                </p>
            </SheetSection>
        );
    } else if (options === null) {
        body = (
            <SheetSection>
                <div className="flex items-center gap-2 type-body text-muted-foreground">
                    <Spinner /> Loading environments
                </div>
            </SheetSection>
        );
    } else if (options.refusal !== null) {
        body = (
            <SheetSection>
                <Status state="warning" label={options.refusal} />
            </SheetSection>
        );
    } else {
        body = (
            <>
                <SheetSection>
                    <Direction
                        from={source}
                        fromDetail={fromDetail}
                        into={environmentName}
                        intoDetail={
                            [options.versionLabel, mountPath]
                                .filter((part) => part !== null)
                                .join(', ') || null
                        }
                    />
                </SheetSection>

                <SheetSection
                    title="Copy from"
                    description={`The same service in another environment of this project.`}
                >
                    <div
                        role="radiogroup"
                        aria-label="Environment to copy from"
                        className="flex flex-col divide-y overflow-hidden rounded-xl border bg-card"
                    >
                        {options.sources.map((item) => {
                            const refusal = refusalOf(item, mountPath);
                            const selected = item.environmentId === sourceId;
                            const newest =
                                backupsFor(item, mountPath)[0] ?? null;

                            return (
                                <label
                                    key={item.environmentId}
                                    className={cn(
                                        'flex items-start gap-3 px-4 py-3 transition-colors duration-fast has-[:focus-visible]:bg-hover',
                                        refusal === null
                                            ? 'cursor-pointer hover:bg-hover'
                                            : 'cursor-not-allowed',
                                    )}
                                >
                                    <input
                                        type="radio"
                                        name="copy-source"
                                        className="sr-only"
                                        checked={selected}
                                        disabled={refusal !== null}
                                        onChange={() => pickSource(item)}
                                    />
                                    <span
                                        aria-hidden="true"
                                        className={cn(
                                            'mt-0.5 flex size-4 shrink-0 items-center justify-center rounded-full border transition-colors duration-fast',
                                            selected
                                                ? 'border-foreground bg-foreground text-background'
                                                : 'border-border-strong',
                                            refusal !== null && 'opacity-50',
                                        )}
                                    >
                                        {selected && (
                                            <Check
                                                className="size-2.5"
                                                strokeWidth={3}
                                            />
                                        )}
                                    </span>
                                    <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                                        <span
                                            className={cn(
                                                'flex flex-wrap items-center gap-x-2 type-label',
                                                refusal !== null &&
                                                    'text-muted-foreground',
                                            )}
                                        >
                                            {item.environmentName}
                                            {item.environmentKind ===
                                                'production' &&
                                                item.environmentName.toLowerCase() !==
                                                    'production' && (
                                                    <span className="rounded-md bg-hover px-1.5 py-0.5 type-small font-normal text-muted-foreground">
                                                        Production
                                                    </span>
                                                )}
                                        </span>
                                        <span className="type-small text-muted-foreground">
                                            {refusal ??
                                                [
                                                    item.versionLabel,
                                                    newest
                                                        ? `backed up ${relativeTime(newest.takenAt)}`
                                                        : 'no backup yet',
                                                    newest?.sizeBytes
                                                        ? formatBytes(
                                                              newest.sizeBytes,
                                                          )
                                                        : null,
                                                ]
                                                    .filter(
                                                        (part) => part !== null,
                                                    )
                                                    .join(', ')}
                                        </span>
                                    </span>
                                </label>
                            );
                        })}
                    </div>
                </SheetSection>

                {source !== null && refusalOf(source, mountPath) === null && (
                    <SheetSection title="Which data">
                        {backups.length === 0 ? (
                            <div className="flex items-start gap-3 rounded-xl border bg-card px-4 py-3">
                                <Zap
                                    aria-hidden="true"
                                    className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                                />
                                <div className="flex flex-col gap-0.5">
                                    <span className="type-label">
                                        Fresh copy now
                                    </span>
                                    <span className="type-small text-muted-foreground">
                                        {source.backupsReadable
                                            ? `${source.environmentName} has no backup yet, so its data is copied as it is now, without stopping it.`
                                            : `${source.environmentName} has no backup storage, so its data is copied as it is now, without stopping it.`}
                                    </span>
                                </div>
                            </div>
                        ) : (
                            <OptionCards
                                label="Which data to copy"
                                value={point}
                                onChange={(value) => {
                                    setPoint(value);

                                    if (
                                        value === 'backup' &&
                                        snapshot === null
                                    ) {
                                        setSnapshot(
                                            backups[0]?.snapshot ?? null,
                                        );
                                    }
                                }}
                                options={[
                                    {
                                        value: 'latest_backup' as const,
                                        title: 'Latest backup',
                                        description: `From ${relativeTime(backups[0].takenAt)}. No load on ${source.environmentName}.`,
                                        media: (
                                            <History
                                                aria-hidden="true"
                                                className="size-5 text-muted-foreground"
                                            />
                                        ),
                                    },
                                    ...(backups.length > 1
                                        ? [
                                              {
                                                  value: 'backup' as const,
                                                  title: 'A specific backup',
                                                  description: `Pick one of ${backups.length} backups.`,
                                                  media: (
                                                      <Archive
                                                          aria-hidden="true"
                                                          className="size-5 text-muted-foreground"
                                                      />
                                                  ),
                                              },
                                          ]
                                        : []),
                                    {
                                        value: 'live' as const,
                                        title: 'Fresh copy now',
                                        description: `${isVolume ? 'Reads the files' : 'Dumps the data'} as they are now, without stopping ${source.environmentName}.`,
                                        media: (
                                            <Zap
                                                aria-hidden="true"
                                                className="size-5 text-muted-foreground"
                                            />
                                        ),
                                    },
                                ]}
                            />
                        )}
                        {point === 'backup' && backups.length > 0 && (
                            <div className="flex flex-col gap-2">
                                <Label htmlFor="copy-backup">Backup</Label>
                                <Select
                                    value={snapshot ?? undefined}
                                    onValueChange={setSnapshot}
                                >
                                    <SelectTrigger
                                        id="copy-backup"
                                        className="w-full"
                                    >
                                        <SelectValue placeholder="Pick a backup" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {backups.map((backup) => (
                                            <SelectItem
                                                key={backup.snapshot}
                                                value={backup.snapshot}
                                            >
                                                {backupLabel(backup)}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        )}
                    </SheetSection>
                )}

                {source !== null && refusalOf(source, mountPath) === null && (
                    <SheetSection title={`Into ${environmentName}`}>
                        {checked.state === 'pending' && (
                            <div className="flex items-center gap-2 type-body text-muted-foreground">
                                <Spinner /> Looking at {what} in{' '}
                                {environmentName}
                            </div>
                        )}
                        {checked.state === 'failed' && (
                            <p
                                role="alert"
                                className="type-body text-muted-foreground"
                            >
                                {checked.message ??
                                    'launchroom couldn’t look at the target.'}{' '}
                                Its data is replaced either way.
                            </p>
                        )}
                        {checked.state === 'done' &&
                            targetInspection !== null && (
                                <p className="type-body">
                                    {targetInspection.empty
                                        ? `${what} is empty in ${environmentName}.`
                                        : isVolume
                                          ? `${what} already has files in ${environmentName}.`
                                          : `${data.serviceName} has ${targetInspection.tables ?? 0} ${data.importFormat?.objects ?? 'tables'}${targetInspection.sizeBytes ? ` (${formatBytes(targetInspection.sizeBytes)})` : ''} in ${environmentName}.`}
                                </p>
                            )}

                        <dl className="grid grid-cols-[auto_minmax(0,1fr)] gap-x-6 gap-y-2 rounded-lg border bg-recessed px-4 py-3">
                            <dt className="type-small text-muted-foreground">
                                Size
                            </dt>
                            <dd className="type-mono-small">
                                {sizeBytes !== null
                                    ? formatBytes(sizeBytes)
                                    : point === 'live' &&
                                        checked.state === 'pending'
                                      ? 'Measuring'
                                      : 'Unknown until it is copied'}
                            </dd>
                            {!isVolume && (
                                <>
                                    <dt className="type-small text-muted-foreground">
                                        Versions
                                    </dt>
                                    <dd className="type-mono-small">
                                        {source.versionLabel ?? '?'} into{' '}
                                        {options.versionLabel ?? '?'}
                                    </dd>
                                </>
                            )}
                            <dt className="type-small text-muted-foreground">
                                Stops
                            </dt>
                            <dd className="type-mono-small">
                                {dependents.length === 0
                                    ? 'Nothing else uses it'
                                    : `${listNames(dependents)}, ${stopSeconds !== null ? formatEstimate(stopSeconds) : 'while the data loads'}`}
                            </dd>
                        </dl>
                    </SheetSection>
                )}

                {source !== null && refusalOf(source, mountPath) === null && (
                    <SheetSection title="What will happen">
                        <ul className="flex flex-col gap-1.5">
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

                        {source.environmentKind === 'production' && (
                            <div className="flex items-start gap-2 rounded-lg border bg-recessed px-3 py-2.5 type-small text-muted-foreground">
                                <ShieldAlert
                                    aria-hidden="true"
                                    className="mt-0.5 size-4 shrink-0"
                                />
                                <span>
                                    The copy holds production’s data, including
                                    personal data. Everyone with access to{' '}
                                    {environmentName} can read it there.
                                </span>
                            </div>
                        )}

                        {production && (
                            <div className="flex items-start gap-2">
                                <Checkbox
                                    id="copy-replace-production"
                                    checked={replaceProduction}
                                    onCheckedChange={(value) =>
                                        setReplaceProduction(value === true)
                                    }
                                />
                                <Label
                                    htmlFor="copy-replace-production"
                                    className="type-body"
                                >
                                    Replace production’s data with{' '}
                                    {source.environmentName}’s
                                </Label>
                            </div>
                        )}

                        {needsTyped && (
                            <div className="flex flex-col gap-2">
                                <Label htmlFor="copy-confirmation">
                                    Type{' '}
                                    <span className="type-mono">
                                        {environmentName}
                                    </span>{' '}
                                    to confirm
                                </Label>
                                <Input
                                    id="copy-confirmation"
                                    className="font-mono"
                                    autoComplete="off"
                                    spellCheck={false}
                                    value={typed}
                                    onChange={(event) =>
                                        setTyped(event.target.value)
                                    }
                                />
                            </div>
                        )}
                    </SheetSection>
                )}
            </>
        );
    }

    return (
        <SidePanel
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    close();
                }
            }}
            size="lg"
            media={<DatabaseBackup aria-hidden="true" className="size-5" />}
            title={
                mountPath !== null
                    ? `Copy files into ${mountPath}`
                    : `Copy data into ${data.serviceName}`
            }
            description="From the same service in another environment."
            footer={
                <div className="flex flex-wrap items-center justify-end gap-2">
                    <Button variant="ghost" onClick={close}>
                        Cancel
                    </Button>
                    <Button
                        variant={needsTyped ? 'destructive' : 'default'}
                        disabled={!ready}
                        onClick={() => void submit()}
                    >
                        {busy && <Spinner />}
                        {needsTyped ? 'Replace and copy' : 'Copy data'}
                    </Button>
                </div>
            }
        >
            {body}
        </SidePanel>
    );
}
