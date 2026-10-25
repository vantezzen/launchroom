import { Archive, FileUp, Link2, RotateCcw, TriangleAlert } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import { MetaLine } from '@/components/patterns/meta-line';
import { OptionCards } from '@/components/patterns/option-cards';
import { SheetSection, SidePanel } from '@/components/patterns/sheet';
import { Status } from '@/components/status';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Progress } from '@/components/ui/progress';
import { Spinner } from '@/components/ui/spinner';
import { show as showTransfer } from '@/routes/transfers';
import { inspection as inspectionRoute } from '@/routes/transfers/services';
import { TransferRequestFailed, transfersApi } from './api';
import type { Inspection, ServiceTransfers, Transfer } from './api';
import {
    engineFormats,
    formatBytes,
    rate,
    timeLeft,
    urlExamples,
} from './format';
import { uploadFile } from './upload';
import type { UploadProgress } from './upload';

export type ImportTarget = {
    /** Public id of the volume (volume imports); null for the database. */
    volume: App.Data.Transfers.TransferVolumeData | null;
    /** An earlier upload to use again ("Try again"). */
    transferId?: string | null;
};

type Upload =
    | { state: 'idle' }
    | { state: 'uploading'; fileName: string; progress: UploadProgress }
    | { state: 'done'; transfer: Transfer }
    | { state: 'failed'; message: string };

type Checked = {
    state: 'idle' | 'pending' | 'done' | 'failed';
    inspection?: Inspection;
    message?: string;
};

function messageOf(error: unknown, fallback: string): string {
    return error instanceof TransferRequestFailed || error instanceof Error
        ? error.message
        : fallback;
}

/**
 * Polls an inspection (a check of the target, or of a connection URL) until
 * launchroomd answered.
 */
async function waitForInspection(
    data: ServiceTransfers,
    id: string,
    signal: AbortSignal,
): Promise<Inspection> {
    for (let attempt = 0; attempt < 120; attempt++) {
        const result = await transfersApi<Inspection>(
            'get',
            inspectionRoute({
                serviceInstance: data.serviceInstanceId,
                task: id,
            }).url,
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

/** The sheet keeps a started database waiting while it is open. */
const PREPARE_KEEPALIVE_MS = 120_000;

/**
 * Starts a database that was never deployed on its own (docs/14 §12.9) and
 * waits until it runs. `onServer` gets the server once it is placed.
 */
async function prepareDatabase(
    data: ServiceTransfers,
    signal: AbortSignal,
    onServer: (server: string | null) => void,
): Promise<void> {
    let state = await transfersApi<ServiceTransfers>(
        'post',
        data.urls.prepare,
        {},
        signal,
    );

    for (let attempt = 0; attempt < 200; attempt++) {
        const preparation = state.preparation;

        if (preparation === null || preparation.state === 'ready') {
            return;
        }

        if (preparation.state === 'failed' || preparation.state === 'needed') {
            throw new Error(
                preparation.message ??
                    `${data.serviceName} didn’t start. Try again.`,
            );
        }

        onServer(preparation.serverName);
        await new Promise((resolve) => window.setTimeout(resolve, 2000));
        state = await transfersApi<ServiceTransfers>(
            'get',
            data.urls.self,
            undefined,
            signal,
        );
    }

    throw new Error(`${data.serviceName} didn’t start in time. Try again.`);
}

/**
 * The import sheet (docs/14 §12.6): 1 Source (a file, uploaded right
 * away and resumable, or a connection URL with a check), 2 Target (what is
 * there now, replace or not, which services stop), 3 What will happen,
 * with typed confirmation when data is replaced.
 */
export function ImportSheet({
    data,
    target,
    onClose,
    onStarted,
}: {
    data: ServiceTransfers;
    target: ImportTarget | null;
    onClose: () => void;
    onStarted: () => void;
}) {
    const open = target !== null;
    const volume = target?.volume ?? null;
    const isVolume = data.subject === 'volume';
    const engine = data.engine ?? 'postgres';
    const [sourceKind, setSourceKind] = useState<'file' | 'url'>('file');
    const [upload, setUpload] = useState<Upload>({ state: 'idle' });
    const [url, setUrl] = useState('');
    const [urlCheck, setUrlCheck] = useState<Checked>({ state: 'idle' });
    const [targetCheck, setTargetCheck] = useState<Checked>({ state: 'idle' });
    const [replace, setReplace] = useState<'replace' | 'merge' | null>(null);
    const [stopDependents, setStopDependents] = useState(true);
    const [stripTop, setStripTop] = useState(true);
    const [ownerMode, setOwnerMode] = useState<'keep' | 'set'>('keep');
    const [uid, setUid] = useState('');
    const [gid, setGid] = useState('');
    const [typed, setTyped] = useState('');
    const [busy, setBusy] = useState(false);
    const abort = useRef<AbortController | null>(null);
    const fileInput = useRef<HTMLInputElement | null>(null);
    // Never deployed: started alone for the import, stopped again after it (docs/14 §12.9).
    const preparing = !isVolume && data.preparation !== null;
    const [startingOn, setStartingOn] = useState<string | null>(null);
    const [starting, setStarting] = useState(false);

    const reset = useCallback(() => {
        abort.current?.abort();
        abort.current = null;
        setSourceKind('file');
        setUpload({ state: 'idle' });
        setUrl('');
        setUrlCheck({ state: 'idle' });
        setTargetCheck({ state: 'idle' });
        setReplace(null);
        setStopDependents(true);
        setStripTop(true);
        setOwnerMode('keep');
        setUid('');
        setGid('');
        setTyped('');
        setBusy(false);
        setStarting(false);
        setStartingOn(null);
    }, []);

    /** Closing without an import stops a database started for it. */
    const close = useCallback(() => {
        if (preparing) {
            void transfersApi('delete', data.urls.prepare, {}).catch(
                () => undefined,
            );
        }

        reset();
        onClose();
    }, [preparing, data.urls.prepare, reset, onClose]);

    // Look at the target as soon as the sheet opens.
    useEffect(() => {
        if (!open) {
            return;
        }

        const controller = new AbortController();
        abort.current = controller;
        setTargetCheck({ state: 'pending' });

        void (async () => {
            try {
                if (preparing) {
                    setStarting(true);
                    await prepareDatabase(
                        data,
                        controller.signal,
                        setStartingOn,
                    );
                    setStarting(false);
                }

                const started = await transfersApi<Inspection>(
                    'post',
                    data.urls.inspect,
                    { volume: volume?.id ?? null },
                    controller.signal,
                );
                const result = await waitForInspection(
                    data,
                    started.id,
                    controller.signal,
                );

                setTargetCheck({
                    state: result.status === 'done' ? 'done' : 'failed',
                    inspection: result,
                    message: result.error ?? undefined,
                });

                if (result.rootUid !== null && result.rootGid !== null) {
                    setUid(String(result.rootUid));
                    setGid(String(result.rootGid));
                }
            } catch (error) {
                if (!controller.signal.aborted) {
                    setStarting(false);
                    setTargetCheck({
                        state: 'failed',
                        message: messageOf(
                            error,
                            'launchroom couldn’t look at the target.',
                        ),
                    });
                }
            }
        })();

        // While the sheet is open, the started database keeps waiting.
        const keepalive = preparing
            ? window.setInterval(() => {
                  void transfersApi('post', data.urls.prepare, {}).catch(
                      () => undefined,
                  );
              }, PREPARE_KEEPALIVE_MS)
            : null;

        if (target?.transferId) {
            void transfersApi<Transfer>(
                'get',
                showTransfer(target.transferId).url,
                undefined,
                controller.signal,
            )
                .then((transfer) => {
                    if (transfer.status === 'ready') {
                        setUpload({ state: 'done', transfer });
                    }
                })
                .catch(() => undefined);
        }

        return () => {
            controller.abort();

            if (keepalive !== null) {
                window.clearInterval(keepalive);
            }
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const startUpload = (file: File) => {
        const controller = abort.current ?? new AbortController();
        abort.current = controller;
        setUpload({
            state: 'uploading',
            fileName: file.name,
            progress: {
                sentBytes: 0,
                totalBytes: file.size,
                bytesPerSecond: null,
                secondsLeft: null,
            },
        });

        void uploadFile({
            serviceInstanceId: data.serviceInstanceId,
            createUrl: data.urls.transfers,
            purpose: isVolume ? 'volume_import' : 'database_import',
            volume: volume?.id ?? null,
            file,
            signal: controller.signal,
            onProgress: (progress) =>
                setUpload({
                    state: 'uploading',
                    fileName: file.name,
                    progress,
                }),
        })
            .then((transfer) => setUpload({ state: 'done', transfer }))
            .catch((error: unknown) => {
                if (!controller.signal.aborted) {
                    setUpload({
                        state: 'failed',
                        message: messageOf(error, 'The upload failed.'),
                    });
                }
            });
    };

    const checkUrl = async () => {
        const controller = abort.current ?? new AbortController();
        abort.current = controller;
        setUrlCheck({ state: 'pending' });

        try {
            const started = await transfersApi<Inspection>(
                'post',
                data.urls.inspect,
                { sourceUrl: url },
                controller.signal,
            );
            const result = await waitForInspection(
                data,
                started.id,
                controller.signal,
            );
            setUrlCheck({
                state: result.status === 'done' ? 'done' : 'failed',
                inspection: result,
                message: result.error ?? undefined,
            });
        } catch (error) {
            setUrlCheck({
                state: 'failed',
                message: messageOf(error, 'The check failed.'),
            });
        }
    };

    const inspection = targetCheck.inspection;
    const empty = inspection?.empty ?? null;
    const sourceReady =
        sourceKind === 'file' ? upload.state === 'done' : url.trim() !== '';
    const mustChoose = empty === false;
    const replacing = replace === 'replace';
    // Apps of a database that was never deployed aren't running.
    const dependents = isVolume || preparing ? [] : data.dependents;
    const transfer = upload.state === 'done' ? upload.transfer : null;
    const topFolder = transfer?.topFolder ?? null;
    const name = isVolume
        ? (volume?.mountPath ?? 'the volume')
        : data.serviceName;
    const ownerValid =
        ownerMode === 'keep' ||
        (/^\d{1,10}$/.test(uid) && /^\d{1,10}$/.test(gid));
    const targetReady =
        targetCheck.state === 'done' &&
        (isVolume
            ? replace !== null || empty === true
            : !mustChoose || replace === 'replace');
    const confirmed = !replacing || typed === data.confirmationText;
    const canImport =
        sourceReady && targetReady && ownerValid && confirmed && !busy;

    const steps = useMemo(() => {
        const list: { text: string; warn?: boolean }[] = [];
        const names = dependents.map((service) => service.name).join(', ');

        if (isVolume || replacing) {
            if (data.hasDestination) {
                list.push({ text: `Take a safety snapshot of ${name}` });
            } else if (replacing) {
                list.push({
                    text: 'No safety snapshot: this team has no backup destination',
                    warn: true,
                });
            }
        }

        if (isVolume) {
            list.push({ text: `Stop ${data.serviceName}` });
            list.push({
                text:
                    replace === 'replace'
                        ? `Empty ${name}`
                        : `Keep what is in ${name} and replace files with the same name`,
            });
            list.push({
                text: `Extract ${transfer?.fileName ?? 'the archive'}${
                    topFolder !== null && stripTop
                        ? ` without its top folder ${topFolder}/`
                        : ''
                }${
                    ownerMode === 'set' && ownerValid
                        ? `, owned by ${uid}:${gid}`
                        : ', keeping its owners and permissions'
                }`,
            });
            list.push({ text: `Start ${data.serviceName} again` });

            return list;
        }

        if (preparing) {
            list.push({
                text: `Start ${data.serviceName} on its own${startingOn ? ` on ${startingOn}` : ''} while your apps stay off`,
            });
        }

        if (stopDependents && dependents.length > 0) {
            list.push({ text: `Stop ${names}` });
        }

        if (replacing) {
            list.push({
                text: `Drop and recreate the ${data.serviceName} database`,
            });
        }

        list.push({
            text:
                sourceKind === 'file'
                    ? `Import ${transfer?.fileName ?? 'the file'}${transfer?.detail ? ` (${transfer.detail})` : ''}`
                    : 'Copy the data from the connection URL',
        });

        if (stopDependents && dependents.length > 0) {
            list.push({ text: `Start ${names} again` });
        }

        if (preparing) {
            list.push({
                text: `Stop ${data.serviceName} again, so the first deploy starts it with this data`,
            });
        }

        return list;
    }, [
        preparing,
        startingOn,
        data,
        dependents,
        isVolume,
        name,
        ownerMode,
        ownerValid,
        replace,
        replacing,
        sourceKind,
        stopDependents,
        stripTop,
        topFolder,
        transfer,
        uid,
        gid,
    ]);

    const submit = async () => {
        setBusy(true);

        try {
            await transfersApi('post', data.urls.imports, {
                transfer: sourceKind === 'file' ? transfer?.id : null,
                sourceUrl: sourceKind === 'url' ? url.trim() : null,
                replace: replacing,
                stopDependents: isVolume ? true : stopDependents,
                confirmation: replacing ? typed : null,
                volume: volume?.id ?? null,
                stripComponents:
                    isVolume && topFolder !== null && stripTop ? 1 : 0,
                owner:
                    isVolume && ownerMode === 'set'
                        ? { uid: Number(uid), gid: Number(gid) }
                        : null,
            });
            toast.success(
                replacing || isVolume
                    ? 'Import started: taking a safety snapshot first.'
                    : 'Import started.',
            );
            reset();
            onStarted();
        } catch (error) {
            toast.error(messageOf(error, 'The import couldn’t start.'));
            setBusy(false);
        }
    };

    const progress = upload.state === 'uploading' ? upload.progress : null;
    const percent =
        progress !== null && progress.totalBytes > 0
            ? Math.round((progress.sentBytes / progress.totalBytes) * 100)
            : 0;

    return (
        <SidePanel
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    close();
                }
            }}
            title={
                isVolume
                    ? `Import files into ${name}`
                    : `Import data into ${data.serviceName}`
            }
            description={
                isVolume
                    ? 'From a .tar, .tar.gz or .zip archive.'
                    : `From a dump file or another ${data.engineLabel ?? 'database'}.`
            }
            footer={
                <div className="flex flex-wrap items-center justify-end gap-2">
                    <Button variant="ghost" onClick={close}>
                        Cancel
                    </Button>
                    <Button
                        variant={replacing ? 'destructive' : 'default'}
                        disabled={!canImport}
                        onClick={() => void submit()}
                    >
                        {busy && <Spinner />}
                        {replacing ? 'Replace and import' : 'Import'}
                    </Button>
                </div>
            }
        >
            <SheetSection
                title="Source"
                description={
                    isVolume
                        ? 'The upload starts right away and continues where it stopped after a reload.'
                        : undefined
                }
            >
                {!isVolume && (
                    <OptionCards
                        label="Where the data comes from"
                        value={sourceKind}
                        onChange={setSourceKind}
                        options={[
                            {
                                value: 'file' as const,
                                title: 'From a dump file',
                                description:
                                    'For databases that aren’t reachable from the internet.',
                                media: (
                                    <FileUp
                                        aria-hidden="true"
                                        className="size-5 text-muted-foreground"
                                    />
                                ),
                            },
                            {
                                value: 'url' as const,
                                title: 'From a connection URL',
                                description:
                                    'Copy from a database this server can reach.',
                                media: (
                                    <Link2
                                        aria-hidden="true"
                                        className="size-5 text-muted-foreground"
                                    />
                                ),
                            },
                        ]}
                    />
                )}

                {sourceKind === 'file' ? (
                    <div className="flex flex-col gap-3">
                        <input
                            ref={fileInput}
                            type="file"
                            className="sr-only"
                            aria-label="File to import"
                            accept={
                                isVolume
                                    ? '.tar,.tgz,.gz,.zip,application/zip,application/gzip,application/x-tar'
                                    : undefined
                            }
                            onChange={(event) => {
                                const file = event.target.files?.[0];
                                event.target.value = '';

                                if (file) {
                                    startUpload(file);
                                }
                            }}
                        />
                        {upload.state === 'idle' && (
                            <button
                                type="button"
                                className="flex flex-col items-center gap-2 rounded-xl border border-dashed bg-recessed px-4 py-8 text-center transition-colors duration-fast hover:bg-hover focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                onClick={() => fileInput.current?.click()}
                                onDragOver={(event) => event.preventDefault()}
                                onDrop={(event) => {
                                    event.preventDefault();
                                    const file = event.dataTransfer.files[0];

                                    if (file) {
                                        startUpload(file);
                                    }
                                }}
                            >
                                <Archive
                                    aria-hidden="true"
                                    className="size-6 text-muted-foreground"
                                />
                                <span className="type-label">
                                    Choose a file or drop it here
                                </span>
                                <span className="type-small text-muted-foreground">
                                    {isVolume
                                        ? '.tar, .tar.gz or .zip'
                                        : (engineFormats[engine] ?? '')}
                                </span>
                            </button>
                        )}
                        {upload.state === 'uploading' && progress !== null && (
                            <div
                                className="flex flex-col gap-2 rounded-xl border bg-card px-4 py-3"
                                aria-live="polite"
                            >
                                <div className="flex items-center justify-between gap-3">
                                    <span className="truncate type-mono">
                                        {upload.fileName}
                                    </span>
                                    <span className="type-small text-muted-foreground tabular-nums">
                                        {percent}%
                                    </span>
                                </div>
                                <Progress value={percent} tone="progress" />
                                <MetaLine className="type-small text-muted-foreground tabular-nums">
                                    {`${formatBytes(progress.sentBytes)} of ${formatBytes(progress.totalBytes)}`}
                                    {rate(progress.bytesPerSecond)}
                                    {timeLeft(progress.secondsLeft)}
                                </MetaLine>
                            </div>
                        )}
                        {upload.state === 'done' && (
                            <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-card px-4 py-3">
                                <div className="flex min-w-0 flex-col gap-0.5">
                                    <span className="truncate type-mono">
                                        {upload.transfer.fileName}
                                    </span>
                                    <MetaLine className="type-small text-muted-foreground">
                                        {upload.transfer.detail}
                                        {formatBytes(upload.transfer.sizeBytes)}
                                    </MetaLine>
                                </div>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={() => setUpload({ state: 'idle' })}
                                >
                                    Choose another
                                </Button>
                            </div>
                        )}
                        {upload.state === 'failed' && (
                            <div className="flex flex-col gap-2">
                                <Status state="failed" label={upload.message} />
                                <div>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            fileInput.current?.click()
                                        }
                                    >
                                        <RotateCcw aria-hidden="true" />
                                        Choose the file again
                                    </Button>
                                </div>
                            </div>
                        )}
                    </div>
                ) : (
                    <div className="flex flex-col gap-2">
                        <Label htmlFor="import-url">Connection URL</Label>
                        <div className="flex flex-col gap-2 sm:flex-row">
                            <Input
                                id="import-url"
                                type="password"
                                autoComplete="off"
                                spellCheck={false}
                                className="font-mono"
                                placeholder={urlExamples[engine] ?? ''}
                                value={url}
                                onChange={(event) => {
                                    setUrl(event.target.value);
                                    setUrlCheck({ state: 'idle' });
                                }}
                            />
                            <Button
                                variant="outline"
                                disabled={
                                    url.trim() === '' ||
                                    urlCheck.state === 'pending'
                                }
                                onClick={() => void checkUrl()}
                            >
                                {urlCheck.state === 'pending' && <Spinner />}
                                Check connection
                            </Button>
                        </div>
                        <p className="type-small text-muted-foreground">
                            {data.serviceName}’s server uses the URL once and
                            doesn’t store it. It can’t reach private or mesh
                            addresses, so use a dump file for those.
                        </p>
                        {urlCheck.state === 'done' && (
                            <Status
                                state="active"
                                label={`Connected${urlCheck.inspection?.sourceVersion ? `: ${urlCheck.inspection.sourceVersion}` : ''}`}
                            />
                        )}
                        {urlCheck.state === 'failed' && (
                            <p
                                role="alert"
                                className="type-mono-small break-words text-status-error"
                            >
                                {urlCheck.message ?? 'The check failed.'}
                            </p>
                        )}
                    </div>
                )}
            </SheetSection>

            <SheetSection title="Target">
                {targetCheck.state === 'pending' && (
                    <div className="flex items-center gap-2 type-body text-muted-foreground">
                        <Spinner />
                        {starting
                            ? `Starting ${data.serviceName} on its own${startingOn ? ` on ${startingOn}` : ''}. This takes up to a minute.`
                            : `Looking at ${name}`}
                    </div>
                )}
                {preparing && (
                    <p className="type-small text-muted-foreground">
                        {data.serviceName} hasn’t been deployed yet. launchroom
                        starts it alone for the import, with the credentials
                        your apps will get, and stops it again afterwards.
                    </p>
                )}
                {targetCheck.state === 'failed' && (
                    <p role="alert" className="type-body text-status-error">
                        {targetCheck.message ??
                            'launchroom couldn’t look at the target.'}
                    </p>
                )}
                {targetCheck.state === 'done' && inspection && (
                    <div className="flex flex-col gap-4">
                        <p className="type-body">
                            {empty
                                ? `${name} is empty.`
                                : isVolume
                                  ? `${name} already has files.`
                                  : `${data.serviceName} has ${inspection.tables ?? 0} ${data.engine === 'mongodb' ? 'collections' : 'tables'}${
                                        inspection.sizeBytes
                                            ? ` (${formatBytes(inspection.sizeBytes)})`
                                            : ''
                                    }.`}
                            {inspection.serverVersion && !isVolume && (
                                <span className="text-muted-foreground">
                                    {' '}
                                    {data.engineLabel}{' '}
                                    {inspection.serverVersion}.
                                </span>
                            )}
                        </p>

                        {isVolume && !empty && (
                            <OptionCards
                                label="What happens to the files there"
                                value={replace}
                                onChange={setReplace}
                                options={[
                                    {
                                        value: 'merge' as const,
                                        title: 'Merge',
                                        description:
                                            'Keeps what is there and replaces files with the same name.',
                                    },
                                    {
                                        value: 'replace' as const,
                                        title: 'Replace contents',
                                        description:
                                            'Takes a safety snapshot, then empties the volume.',
                                    },
                                ]}
                            />
                        )}

                        {!isVolume && mustChoose && (
                            <OptionCards
                                label="It isn’t empty"
                                value={replace}
                                onChange={setReplace}
                                options={[
                                    {
                                        value: 'replace' as const,
                                        title: 'Replace everything',
                                        description: `Drops and recreates ${data.serviceName} before importing. A safety snapshot is taken first.`,
                                    },
                                ]}
                            />
                        )}
                        {!isVolume && mustChoose && replace === null && (
                            <p className="type-small text-muted-foreground">
                                Choose Replace everything to continue, or
                                cancel.
                            </p>
                        )}

                        {!isVolume && dependents.length > 0 && (
                            <div className="flex items-start gap-2">
                                <Checkbox
                                    id="import-stop-dependents"
                                    checked={stopDependents}
                                    onCheckedChange={(value) =>
                                        setStopDependents(value === true)
                                    }
                                />
                                <Label
                                    htmlFor="import-stop-dependents"
                                    className="type-body"
                                >
                                    Stop{' '}
                                    {dependents
                                        .map((service) => service.name)
                                        .join(', ')}{' '}
                                    while importing
                                </Label>
                            </div>
                        )}

                        {isVolume &&
                            transfer !== null &&
                            topFolder !== null && (
                                <div className="flex items-start gap-2">
                                    <Checkbox
                                        id="import-strip"
                                        checked={stripTop}
                                        onCheckedChange={(value) =>
                                            setStripTop(value === true)
                                        }
                                    />
                                    <Label
                                        htmlFor="import-strip"
                                        className="type-body"
                                    >
                                        Leave out the top folder{' '}
                                        <span className="type-mono">
                                            {topFolder}/
                                        </span>
                                    </Label>
                                </div>
                            )}

                        {isVolume && (
                            <div className="flex flex-col gap-3">
                                <OptionCards
                                    label="Owner of the files"
                                    value={ownerMode}
                                    onChange={setOwnerMode}
                                    options={[
                                        {
                                            value: 'keep' as const,
                                            title: 'Keep from the archive',
                                            description:
                                                'Owners and permissions as archived (zip files get the folder’s owner).',
                                        },
                                        {
                                            value: 'set' as const,
                                            title: 'Set an owner',
                                            description:
                                                'For images that run as another user.',
                                        },
                                    ]}
                                />
                                {ownerMode === 'set' && (
                                    <div className="flex items-end gap-2">
                                        <div className="flex flex-col gap-1.5">
                                            <Label htmlFor="import-uid">
                                                UID
                                            </Label>
                                            <Input
                                                id="import-uid"
                                                inputMode="numeric"
                                                className="w-28 font-mono"
                                                value={uid}
                                                onChange={(event) =>
                                                    setUid(event.target.value)
                                                }
                                            />
                                        </div>
                                        <div className="flex flex-col gap-1.5">
                                            <Label htmlFor="import-gid">
                                                GID
                                            </Label>
                                            <Input
                                                id="import-gid"
                                                inputMode="numeric"
                                                className="w-28 font-mono"
                                                value={gid}
                                                onChange={(event) =>
                                                    setGid(event.target.value)
                                                }
                                            />
                                        </div>
                                    </div>
                                )}
                            </div>
                        )}
                    </div>
                )}
            </SheetSection>

            <SheetSection title="What will happen">
                <ol className="flex flex-col gap-2">
                    {steps.map((step, index) => (
                        <li
                            key={step.text}
                            className="flex items-start gap-3 type-body"
                        >
                            <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-recessed type-small text-muted-foreground tabular-nums">
                                {index + 1}
                            </span>
                            <span
                                className={
                                    step.warn
                                        ? 'text-status-progress'
                                        : undefined
                                }
                            >
                                {step.warn && (
                                    <TriangleAlert
                                        aria-hidden="true"
                                        className="mr-1 inline size-4 align-[-3px]"
                                    />
                                )}
                                {step.text}
                            </span>
                        </li>
                    ))}
                </ol>
                {replacing && (
                    <div className="flex flex-col gap-2 pt-2">
                        <Label htmlFor="import-confirmation">
                            Type{' '}
                            <span className="type-mono">
                                {data.confirmationText}
                            </span>{' '}
                            to replace its data
                        </Label>
                        <Input
                            id="import-confirmation"
                            className="font-mono sm:max-w-72"
                            autoComplete="off"
                            value={typed}
                            onChange={(event) => setTyped(event.target.value)}
                        />
                    </div>
                )}
            </SheetSection>
        </SidePanel>
    );
}
