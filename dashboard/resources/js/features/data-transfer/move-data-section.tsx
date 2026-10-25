import {
    DatabaseZap,
    Download,
    FileDown,
    FileUp,
    FolderUp,
    RotateCcw,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { MetaLine } from '@/components/patterns/meta-line';
import { Section } from '@/components/patterns/section';
import { Status } from '@/components/status';
import type { StatusState } from '@/components/status';
import { TimeAgo } from '@/components/time-ago';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { show as showSection } from '@/routes/transfers/services';
import { link as linkRoute } from '@/routes/transfers/services/exports';
import { cancel as cancelImportRoute } from '@/routes/transfers/services/imports';
import { transfersApi } from './api';
import type { ExportRun, ImportRun, ServiceTransfers } from './api';
import { formatBytes, formatDuration } from './format';
import { ImportSheet } from './import-sheet';
import type { ImportTarget } from './import-sheet';

/** A backup snapshot to download, picked on the snapshot timeline. */
export type SnapshotDownload = { id: string; label: string };

const importLabels: Record<App.Enums.Transfers.ImportRunStatus, string> = {
    waiting_for_safety_backup: 'Taking a safety snapshot',
    holding: 'Stopping the services that use it',
    importing: 'Importing',
    succeeded: 'Imported',
    failed: 'Import failed',
    cancelled: 'Import cancelled',
};

function importState(status: App.Enums.Transfers.ImportRunStatus): StatusState {
    if (status === 'succeeded') {
        return 'active';
    }

    if (status === 'failed') {
        return 'failed';
    }

    return status === 'cancelled' ? 'idle' : 'progress';
}

function exportState(status: App.Enums.Transfers.ExportRunStatus): StatusState {
    return status === 'ready'
        ? 'active'
        : status === 'failed'
          ? 'failed'
          : status === 'expired'
            ? 'idle'
            : 'progress';
}

function isBusy(data: ServiceTransfers): boolean {
    return (
        data.imports.some(
            (run) =>
                run.status !== 'succeeded' &&
                run.status !== 'failed' &&
                run.status !== 'cancelled',
        ) || data.exports.some((run) => run.status === 'preparing')
    );
}

function outcome(run: ImportRun): (string | null)[] {
    if (run.result === null) {
        return [];
    }

    return [
        run.subject === 'volume'
            ? `${run.result.files} files`
            : `${run.result.tables} ${run.result.tables === 1 ? 'table' : 'tables'}`,
        run.result.sizeBytes > 0 ? formatBytes(run.result.sizeBytes) : null,
        `in ${formatDuration(run.result.durationMs)}`,
        run.result.sourceVersion ? `from ${run.result.sourceVersion}` : null,
    ];
}

/**
 * Move data (docs/14 §12.6): import a dump, a connection URL or an archive,
 * and download the data as it is now or from a backup. Mounted by the
 * Backups tab; fetches its own data and polls while something runs.
 */
export function MoveDataSection({
    serviceInstanceId,
    snapshotDownload,
    onSnapshotDownloadHandled,
}: {
    serviceInstanceId: string;
    snapshotDownload: SnapshotDownload | null;
    onSnapshotDownloadHandled: () => void;
}) {
    const [data, setData] = useState<ServiceTransfers | null>(null);
    const [target, setTarget] = useState<ImportTarget | null>(null);
    const [busy, setBusy] = useState<string | null>(null);
    // Failed polls in a row: polling goes on (slower) instead of freezing
    // the last status on screen.
    const [failures, setFailures] = useState(0);
    const abort = useRef<AbortController | null>(null);
    const url = showSection(serviceInstanceId).url;

    const load = useCallback(async () => {
        abort.current?.abort();
        const controller = new AbortController();
        abort.current = controller;

        try {
            setData(
                await transfersApi<ServiceTransfers>(
                    'get',
                    url,
                    undefined,
                    controller.signal,
                ),
            );
            setFailures(0);
        } catch {
            // The Backups tab around it shows connection problems.
            if (!controller.signal.aborted) {
                setFailures((count) => count + 1);
            }
        }
    }, [url]);

    useEffect(() => {
        void load();

        return () => abort.current?.abort();
    }, [load]);

    useEffect(() => {
        if (failures === 0 && (data === null || !isBusy(data))) {
            return;
        }

        const timer = window.setTimeout(
            () => void load(),
            failures === 0 ? 3000 : Math.min(30000, 3000 * 2 ** failures),
        );

        return () => window.clearTimeout(timer);
    }, [data, failures, load]);

    const cancelImport = async (run: ImportRun) => {
        setBusy(`cancel:${run.id}`);
        try {
            setData(
                await transfersApi<ServiceTransfers>(
                    'post',
                    cancelImportRoute({
                        serviceInstance: serviceInstanceId,
                        import: run.id,
                    }).url,
                    {},
                ),
            );
            toast.success('Import cancelled. Nothing was imported.');
        } catch (error) {
            toast.error(
                error instanceof Error
                    ? error.message
                    : 'The import couldn’t be cancelled.',
            );
            void load();
        } finally {
            setBusy(null);
        }
    };

    const startExport = useCallback(
        async (body: Record<string, unknown>, label: string) => {
            if (data === null) return;
            setBusy(`export:${label}`);
            try {
                setData(
                    await transfersApi<ServiceTransfers>(
                        'post',
                        data.urls.exports,
                        body,
                    ),
                );
                toast.success(
                    'Preparing the download. It appears here when it is ready.',
                );
            } catch (error) {
                toast.error(
                    error instanceof Error
                        ? error.message
                        : 'The download couldn’t start.',
                );
            } finally {
                setBusy(null);
            }
        },
        [data],
    );

    const download = async (run: ExportRun) => {
        setBusy(`link:${run.id}`);
        try {
            const made =
                await transfersApi<App.Data.Transfers.DownloadLinkData>(
                    'post',
                    linkRoute({
                        serviceInstance: serviceInstanceId,
                        export: run.id,
                    }).url,
                    {},
                );
            window.location.assign(made.url);
        } catch (error) {
            toast.error(
                error instanceof Error
                    ? error.message
                    : 'The download couldn’t start.',
            );
            void load();
        } finally {
            setBusy(null);
        }
    };

    // "Download" on a backup snapshot of the timeline.
    useEffect(() => {
        if (snapshotDownload === null || data === null) {
            return;
        }

        onSnapshotDownloadHandled();
        void startExport(
            { snapshot: snapshotDownload.id },
            snapshotDownload.label,
        );
    }, [snapshotDownload, data, startExport, onSnapshotDownloadHandled]);

    if (data === null || data.subject === null) {
        return null;
    }

    const volumes = data.subject === 'volume';
    const runs = [
        ...data.imports.map((run) => ({
            kind: 'import' as const,
            at: run.createdAt ?? '',
            run,
        })),
        ...data.exports.map((run) => ({
            kind: 'export' as const,
            at: run.createdAt ?? '',
            run,
        })),
    ].sort((a, b) => b.at.localeCompare(a.at));

    return (
        <Section
            level={3}
            title="Move data"
            description={
                volumes
                    ? 'Import an archive into a volume, or download its files.'
                    : `Import a dump or another ${data.engineLabel ?? 'database'}, or download the data.`
            }
        >
            {data.unavailableReason !== null &&
                (data.canImport || data.canExport) && (
                    <Status state="warning" label={data.unavailableReason} />
                )}

            {!volumes && data.preparation !== null && data.canImport && (
                <PrepareCard
                    data={data}
                    onImport={() => setTarget({ volume: null })}
                />
            )}

            {!volumes && data.preparation === null && (
                <div className="flex flex-wrap items-center gap-2">
                    {data.canImport && (
                        <Button
                            variant="outline"
                            disabled={data.unavailableReason !== null}
                            onClick={() => setTarget({ volume: null })}
                        >
                            <FileUp aria-hidden="true" />
                            Import data…
                        </Button>
                    )}
                    {data.canExport && (
                        <Button
                            variant="ghost"
                            disabled={
                                data.unavailableReason !== null || busy !== null
                            }
                            onClick={() => void startExport({}, 'now')}
                        >
                            {busy === 'export:now' ? (
                                <Spinner />
                            ) : (
                                <FileDown aria-hidden="true" />
                            )}
                            Download now
                        </Button>
                    )}
                    {!data.importSupported && (
                        <p className="type-small text-muted-foreground">
                            Importing into {data.engineLabel} isn’t supported;
                            downloads work.
                        </p>
                    )}
                </div>
            )}

            {volumes && data.volumes.length > 0 && (
                <ul className="flex flex-col divide-y overflow-hidden rounded-xl border bg-card shadow-card">
                    {data.volumes.map((volume) => (
                        <li
                            key={volume.id}
                            className="flex flex-wrap items-center justify-between gap-3 px-4 py-3"
                        >
                            <span className="truncate type-mono">
                                {volume.mountPath}
                            </span>
                            <div className="flex flex-wrap gap-2">
                                {data.canImport && (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        disabled={
                                            data.unavailableReason !== null
                                        }
                                        onClick={() => setTarget({ volume })}
                                    >
                                        <FolderUp aria-hidden="true" />
                                        Import files…
                                    </Button>
                                )}
                                {data.canExport && (
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        disabled={
                                            data.unavailableReason !== null ||
                                            busy !== null
                                        }
                                        onClick={() =>
                                            void startExport(
                                                { volume: volume.id },
                                                volume.id,
                                            )
                                        }
                                    >
                                        {busy === `export:${volume.id}` ? (
                                            <Spinner />
                                        ) : (
                                            <FileDown aria-hidden="true" />
                                        )}
                                        Download
                                    </Button>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            )}

            {runs.length > 0 && (
                <ul className="flex flex-col divide-y overflow-hidden rounded-xl border bg-card shadow-card">
                    {runs.map((entry) =>
                        entry.kind === 'import' ? (
                            <ImportRow
                                key={entry.run.id}
                                run={entry.run}
                                data={data}
                                cancelling={busy === `cancel:${entry.run.id}`}
                                onCancel={() => void cancelImport(entry.run)}
                                onRetry={() =>
                                    setTarget({
                                        volume: entry.run.volume,
                                        transferId: entry.run.transferId,
                                    })
                                }
                            />
                        ) : (
                            <ExportRow
                                key={entry.run.id}
                                run={entry.run}
                                canDownload={data.canExport}
                                busy={busy === `link:${entry.run.id}`}
                                onDownload={() => void download(entry.run)}
                            />
                        ),
                    )}
                </ul>
            )}

            <ImportSheet
                data={data}
                target={target}
                onClose={() => setTarget(null)}
                onStarted={() => {
                    setTarget(null);
                    void load();
                }}
            />
        </Section>
    );
}

/**
 * A database that was never deployed (docs/14 §12.9): import into it before
 * the first deploy. The sheet starts it alone and it stops again after.
 */
function PrepareCard({
    data,
    onImport,
}: {
    data: ServiceTransfers;
    onImport: () => void;
}) {
    const preparation = data.preparation;

    return (
        <div className="flex flex-col gap-3 rounded-xl border bg-card p-5 shadow-card">
            <div className="flex items-start gap-3">
                <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-recessed text-muted-foreground">
                    <DatabaseZap aria-hidden="true" className="size-4" />
                </span>
                <div className="flex flex-col gap-0.5">
                    <p className="type-heading">
                        Import data before the first deploy
                    </p>
                    <p className="type-small text-muted-foreground">
                        launchroom starts {data.serviceName} on its own, imports
                        your dump and stops it again. Your apps stay off, and
                        the first deploy starts with the imported data.
                    </p>
                </div>
            </div>
            {preparation?.state === 'starting' && (
                <Status
                    state="progress"
                    label={`Starting ${data.serviceName}${preparation.serverName ? ` on ${preparation.serverName}` : ''}`}
                />
            )}
            {preparation?.state === 'ready' && (
                <Status
                    state="active"
                    label={`${data.serviceName} runs on its own for the import${preparation.serverName ? ` on ${preparation.serverName}` : ''}`}
                />
            )}
            {preparation?.state === 'failed' && preparation.message && (
                <p className="type-mono-small break-words text-muted-foreground">
                    {preparation.message}
                </p>
            )}
            <div>
                <Button variant="outline" onClick={onImport}>
                    <FileUp aria-hidden="true" />
                    Import data…
                </Button>
            </div>
        </div>
    );
}

function ImportRow({
    run,
    data,
    cancelling,
    onCancel,
    onRetry,
}: {
    run: ImportRun;
    data: ServiceTransfers;
    cancelling: boolean;
    onCancel: () => void;
    onRetry: () => void;
}) {
    const cancellable =
        data.canImport &&
        (run.status === 'waiting_for_safety_backup' ||
            run.status === 'holding');
    const into = run.volume !== null ? run.volume.mountPath : data.serviceName;
    const label =
        run.status === 'holding' && run.subject === 'volume'
            ? `Stopping ${data.serviceName}`
            : run.status === 'holding' && !run.stopDependents
              ? 'Starting the import'
              : importLabels[run.status];

    return (
        <li className="flex flex-col gap-1 px-4 py-3">
            <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                <Status
                    state={importState(run.status)}
                    label={label}
                    detail={`${run.sourceLabel} into ${into}`}
                />
                <span className="type-small text-muted-foreground">
                    {run.createdAt !== null && (
                        <TimeAgo value={run.createdAt} />
                    )}
                </span>
            </div>
            {run.status === 'holding' && run.waitingFor.length > 0 && (
                <p
                    className="type-small text-muted-foreground"
                    aria-live="polite"
                >
                    Waiting for {listNames(run.waitingFor)} to stop
                </p>
            )}
            {run.status === 'importing' && run.step !== null && (
                <p
                    className="type-small text-muted-foreground tabular-nums"
                    aria-live="polite"
                >
                    {run.step}
                </p>
            )}
            {run.status === 'succeeded' && (
                <MetaLine className="type-small text-muted-foreground tabular-nums">
                    {outcome(run)}
                </MetaLine>
            )}
            {run.result !== null && run.result.warnings.length > 0 && (
                <ul className="flex flex-col gap-0.5">
                    {run.result.warnings.map((warning) => (
                        <li
                            key={warning}
                            className="type-small text-muted-foreground"
                        >
                            {warning}
                        </li>
                    ))}
                </ul>
            )}
            {run.error !== null && (
                <p className="type-mono-small break-words text-muted-foreground">
                    {run.error}
                </p>
            )}
            {run.status === 'failed' && data.canImport && (
                <div>
                    <Button size="sm" variant="outline" onClick={onRetry}>
                        <RotateCcw aria-hidden="true" />
                        Try again
                    </Button>
                </div>
            )}
            {cancellable && (
                <div>
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={cancelling}
                        onClick={onCancel}
                    >
                        {cancelling ? <Spinner /> : <X aria-hidden="true" />}
                        Cancel import
                    </Button>
                </div>
            )}
        </li>
    );
}

function listNames(names: string[]): string {
    return names.length < 2
        ? (names[0] ?? '')
        : `${names.slice(0, -1).join(', ')} and ${names[names.length - 1]}`;
}

function ExportRow({
    run,
    canDownload,
    busy,
    onDownload,
}: {
    run: ExportRun;
    canDownload: boolean;
    busy: boolean;
    onDownload: () => void;
}) {
    const what =
        run.source === 'snapshot'
            ? `Backup ${run.snapshot ?? ''}`.trim()
            : run.volume !== null
              ? run.volume.mountPath
              : 'Data as it is now';
    const label =
        run.status === 'preparing'
            ? 'Preparing download'
            : run.status === 'ready'
              ? 'Ready to download'
              : run.status === 'failed'
                ? 'Download failed'
                : 'Download expired';

    return (
        <li className="flex flex-col gap-1 px-4 py-3">
            <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                <Status
                    state={exportState(run.status)}
                    label={label}
                    detail={what}
                />
                <span className="type-small text-muted-foreground">
                    {run.createdAt !== null && (
                        <TimeAgo value={run.createdAt} />
                    )}
                </span>
            </div>
            {run.status === 'preparing' && (
                <p
                    className="type-small text-muted-foreground tabular-nums"
                    aria-live="polite"
                >
                    {run.sizeBytes > 0
                        ? `${formatBytes(run.sizeBytes)} so far`
                        : 'Starting on the server'}
                </p>
            )}
            {run.status === 'ready' && canDownload && (
                <div className="flex flex-wrap items-center gap-3">
                    <Button size="sm" onClick={onDownload} disabled={busy}>
                        {busy ? <Spinner /> : <Download aria-hidden="true" />}
                        Download {run.fileName} ({formatBytes(run.sizeBytes)})
                    </Button>
                    {run.expiresAt !== null && (
                        <span className="type-small text-muted-foreground">
                            Kept until{' '}
                            {new Date(run.expiresAt).toLocaleTimeString([], {
                                hour: '2-digit',
                                minute: '2-digit',
                            })}
                        </span>
                    )}
                </div>
            )}
            {run.error !== null && (
                <p className="type-mono-small break-words text-muted-foreground">
                    {run.error}
                </p>
            )}
        </li>
    );
}
