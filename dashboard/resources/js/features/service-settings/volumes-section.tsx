import { Link, useForm } from '@inertiajs/react';
import {
    ArrowRight,
    FolderInput,
    HardDrive,
    MoreHorizontal,
    Plus,
    ShieldAlert,
    ShieldCheck,
    ShieldQuestion,
    Trash2,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import InputError from '@/components/input-error';
import { EmptyState } from '@/components/patterns/empty-state';
import { StatusPill } from '@/components/status';
import { TimeAgo } from '@/components/time-ago';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { RemoteAccessButton } from '@/features/remote-access/remote-access';
import { deviceStatus } from '@/features/servers/format';
import { cn } from '@/lib/utils';
import services from '@/routes/projects/services';
import { destroy, store, update } from '@/routes/projects/services/volumes';
import { CopyButton } from './general-section';
import { DeviceStage } from './scaling-visuals';
import { SettingsPage } from './section';
import type { SettingsContext } from './settings-context';

type Volume = App.Data.Canvas.VolumeData;

function formatBytes(bytes: number): string {
    const gb = bytes / 1024 ** 3;

    if (gb >= 1) {
        return `${gb < 10 ? gb.toFixed(1) : Math.round(gb)} GB`;
    }

    return `${Math.max(1, Math.round(bytes / 1024 ** 2))} MB`;
}

/** Backup protection as one word + icon + one line (docs/14 §3). */
function protection(backup: App.Data.Backups.ServiceBackupStatusData | null): {
    icon: ReactNode;
    title: string;
    detail: ReactNode;
    tone: string;
} {
    if (!backup || backup.status === 'unconfigured') {
        return {
            icon: <ShieldQuestion className="size-4" />,
            title: 'Not backed up yet',
            detail: 'Add a backup destination in Settings → Backups.',
            tone: 'text-muted-foreground',
        };
    }

    switch (backup.status) {
        case 'ok':
        case 'running':
            return {
                icon: <ShieldCheck className="size-4" />,
                title:
                    backup.status === 'running'
                        ? 'Backing up now'
                        : 'Protected',
                detail: backup.lastSuccessfulAt ? (
                    <>
                        Last snapshot{' '}
                        <TimeAgo value={backup.lastSuccessfulAt} />
                    </>
                ) : (
                    'First snapshot on the way'
                ),
                tone: 'text-status-ok',
            };
        case 'stale':
        case 'failing':
            return {
                icon: <ShieldAlert className="size-4" />,
                title:
                    backup.status === 'failing'
                        ? 'Backups failing'
                        : 'Backup is overdue',
                detail: backup.lastSuccessfulAt ? (
                    <>
                        Last good one{' '}
                        <TimeAgo value={backup.lastSuccessfulAt} />
                    </>
                ) : (
                    (backup.lastError ?? 'No snapshot yet')
                ),
                tone: 'text-status-progress',
            };
        default:
            return {
                icon: <ShieldQuestion className="size-4" />,
                title: 'No snapshot yet',
                detail: backup.nextRunAt ? (
                    <>
                        First one <TimeAgo value={backup.nextRunAt} />
                    </>
                ) : (
                    'Scheduled with your backup plan'
                ),
                tone: 'text-muted-foreground',
            };
    }
}

/**
 * A volume as an object: where it is mounted, the server that holds it
 * (device with its LED), how big it is, and whether backups protect it,
 * with the way into its Backups settings.
 */
function VolumeCard({
    volume,
    context,
    canChange,
    onChange,
    onRemove,
}: {
    volume: Volume;
    context: SettingsContext;
    canChange: boolean;
    onChange: () => void;
    onRemove: () => void;
}) {
    const placed = volume.server !== null;
    const guard = protection(context.node.backup);
    const backupsHref = services.section({
        project: context.route[0],
        environment: context.route[1],
        service: context.route[2],
        section: 'backups',
    }).url;

    return (
        <li className="overflow-hidden rounded-xl border bg-card shadow-card">
            <div className="grid @lg/page:grid-cols-[200px_minmax(0,1fr)]">
                {placed ? (
                    <DeviceStage
                        device={volume.serverDevice?.kind ?? 'unknown'}
                        status={
                            volume.serverStatus
                                ? deviceStatus(volume.serverStatus)
                                : 'online'
                        }
                        className="h-full min-h-[150px]"
                    >
                        <span className="flex size-10 items-center justify-center rounded-[10px] border bg-card text-foreground shadow-card">
                            <HardDrive aria-hidden="true" className="size-5" />
                        </span>
                    </DeviceStage>
                ) : (
                    <div className="flex min-h-[150px] items-center justify-center bg-recessed">
                        <span className="flex size-12 items-center justify-center rounded-xl border border-dashed border-signal bg-signal-soft text-signal">
                            <HardDrive aria-hidden="true" className="size-6" />
                        </span>
                    </div>
                )}
                <div className="flex min-w-0 flex-col">
                    <div className="flex items-start justify-between gap-3 px-5 pt-4 pb-3">
                        <div className="flex min-w-0 flex-col gap-1">
                            <span className="type-small text-muted-foreground">
                                Mounted at
                            </span>
                            <span className="flex min-w-0 items-center">
                                <code className="truncate type-mono text-[15px] font-medium">
                                    {volume.mountPath}
                                </code>
                                <CopyButton
                                    value={volume.mountPath}
                                    label="mount path"
                                />
                            </span>
                        </div>
                        <div className="flex shrink-0 items-center gap-1">
                            <VolumeState volume={volume} />
                            {canChange && !volume.removing && (
                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <Button
                                            variant="ghost"
                                            size="icon-sm"
                                            aria-label={`Options for ${volume.mountPath}`}
                                        >
                                            <MoreHorizontal />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end">
                                        <DropdownMenuItem onSelect={onChange}>
                                            <FolderInput /> Change mount path
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            variant="destructive"
                                            onSelect={onRemove}
                                        >
                                            <Trash2 /> Remove volume
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            )}
                        </div>
                    </div>
                    {volume.deployedMountPath && !volume.removing && (
                        <p className="-mt-1 px-5 pb-3 type-small text-muted-foreground">
                            Running containers still use{' '}
                            <code className="type-mono-small">
                                {volume.deployedMountPath}
                            </code>{' '}
                            until you deploy.
                        </p>
                    )}
                    <dl className="grid flex-1 divide-y border-t @md/page:grid-cols-2 @md/page:divide-x @md/page:divide-y-0">
                        <div className="flex flex-col gap-1 px-5 py-3.5">
                            <dt className="type-small text-muted-foreground">
                                Size
                            </dt>
                            <dd className="type-heading tabular-nums">
                                {volume.sizeBytes !== null
                                    ? formatBytes(volume.sizeBytes)
                                    : '—'}
                            </dd>
                            <dd className="type-small text-muted-foreground">
                                {volume.sizeBytes !== null
                                    ? 'At the last snapshot'
                                    : 'Measured with the first snapshot'}
                            </dd>
                        </div>
                        <div className="flex flex-col gap-1 px-5 py-3.5">
                            <dt className="type-small text-muted-foreground">
                                Backups
                            </dt>
                            <dd
                                className={cn(
                                    'flex items-center gap-1.5 type-body font-medium',
                                    guard.tone,
                                )}
                            >
                                <span aria-hidden="true">{guard.icon}</span>
                                <span className="text-foreground">
                                    {guard.title}
                                </span>
                            </dd>
                            <dd className="truncate type-small text-muted-foreground">
                                {guard.detail}
                            </dd>
                        </div>
                    </dl>
                    <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1.5 border-t px-5 py-2.5">
                        <span className="type-small text-muted-foreground">
                            {volume.removing
                                ? `The next deploy stops mounting it. The files stay on ${volume.server} until the volume is deleted.`
                                : placed
                                  ? `Kept on ${volume.server} across deploys.`
                                  : 'Created on the server the next deploy picks.'}
                        </span>
                        <span className="flex shrink-0 items-center gap-3">
                            {placed && !volume.removing && (
                                <RemoteAccessButton
                                    serviceRef={{
                                        project: context.route[0],
                                        environment: context.route[1],
                                        service: context.route[2],
                                    }}
                                    kind="volume"
                                    volume={volume.id}
                                    variant="ghost"
                                    size="sm"
                                    className="-my-1 h-7 px-2 type-small"
                                />
                            )}
                            <Link
                                href={backupsHref}
                                only={['panel']}
                                preserveState
                                preserveScroll
                                className="inline-flex shrink-0 items-center gap-1 type-small font-medium text-signal underline-offset-4 hover:underline"
                            >
                                Backups
                                <ArrowRight
                                    aria-hidden="true"
                                    className="size-3.5"
                                />
                            </Link>
                        </span>
                    </div>
                </div>
            </div>
        </li>
    );
}

/** In use, staged (new, moved, removed) as a pill with a word. */
function VolumeState({ volume }: { volume: Volume }) {
    if (volume.removing) {
        return (
            <StatusPill state="staged" label="Removed with the next deploy" />
        );
    }

    if (volume.server === null) {
        return <StatusPill state="staged" label="With the next deploy" />;
    }

    if (volume.deployedMountPath) {
        return <StatusPill state="staged" label="Moves with the next deploy" />;
    }

    return <StatusPill state="active" label="In use" />;
}

/**
 * A new mount path for a volume. The files stay in the volume; the next
 * deploy mounts it at the new path (staged, docs/14 §9.12).
 */
function ChangeVolumeDialog({
    context,
    volume,
    onOpenChange,
}: {
    context: SettingsContext;
    volume: Volume | null;
    onOpenChange: (open: boolean) => void;
}) {
    // Keyed by the volume: the form starts from its current path.
    const form = useForm({ mountPath: volume?.mountPath ?? '' });
    const path = form.data.mountPath.trim();
    const valid = PATH.test(path);

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (!volume) {
            return;
        }

        form.transform((data) => ({ mountPath: data.mountPath.trim() }));
        form.patch(update([...context.route, volume.id]).url, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <Dialog open={volume !== null} onOpenChange={onOpenChange}>
            <DialogContent className="gap-6 sm:max-w-lg">
                <form onSubmit={submit} className="flex flex-col gap-6">
                    <div className="flex flex-col gap-1.5">
                        <DialogTitle>Change mount path</DialogTitle>
                        <DialogDescription>
                            The files stay in the volume. After the next deploy,{' '}
                            {context.node.name} finds them at the new path.
                        </DialogDescription>
                    </div>
                    <div className="flex flex-col gap-2">
                        <label
                            htmlFor="volume-new-mount-path"
                            className="type-label"
                        >
                            Mount path
                        </label>
                        <Input
                            id="volume-new-mount-path"
                            autoFocus
                            value={form.data.mountPath}
                            onChange={(event) =>
                                form.setData('mountPath', event.target.value)
                            }
                            autoComplete="off"
                            spellCheck={false}
                            className="h-11 font-mono text-[15px]"
                            aria-invalid={
                                path !== '' && !valid ? true : undefined
                            }
                        />
                        <InputError message={form.errors.mountPath} />
                        {path !== '' && !valid && !form.errors.mountPath && (
                            <p className="type-small text-status-progress">
                                An absolute path inside the container, like
                                /app/data.
                            </p>
                        )}
                    </div>
                    <div className="flex items-center justify-between gap-3">
                        <span className="type-small text-muted-foreground">
                            Applies with the next deploy.
                        </span>
                        <div className="flex gap-2">
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => onOpenChange(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                disabled={
                                    form.processing ||
                                    !valid ||
                                    path === volume?.mountPath
                                }
                            >
                                Save for next deploy
                            </Button>
                        </div>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * Removes a volume. One that was never created goes right away; one that
 * holds files is removed with the next deploy, after typing its path, and
 * then kept on its server until it is deleted (docs/05 §1.1).
 */
function RemoveVolumeDialog({
    context,
    volume,
    onOpenChange,
}: {
    context: SettingsContext;
    volume: Volume | null;
    onOpenChange: (open: boolean) => void;
}) {
    const form = useForm({ confirm: '' });
    const placed = volume?.server != null;
    const ready = !placed || form.data.confirm.trim() === volume?.mountPath;

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (!volume) {
            return;
        }

        form.transform((data) => ({ confirm: data.confirm.trim() }));
        form.delete(destroy([...context.route, volume.id]).url, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    const error =
        form.errors.confirm ??
        (form.errors as Record<string, string | undefined>).volume;

    return (
        <Dialog open={volume !== null} onOpenChange={onOpenChange}>
            <DialogContent className="gap-6 sm:max-w-lg">
                <form onSubmit={submit} className="flex flex-col gap-6">
                    <div className="flex flex-col gap-1.5">
                        <DialogTitle>
                            Remove{' '}
                            <code className="type-mono">
                                {volume?.mountPath}
                            </code>
                            ?
                        </DialogTitle>
                        <DialogDescription>
                            {placed
                                ? `${context.node.name} keeps it until you deploy. You can discard the change before that.`
                                : 'It was never created, so no files are lost.'}
                        </DialogDescription>
                    </div>
                    {placed && (
                        <ul className="flex flex-col gap-2 rounded-xl bg-recessed p-4 type-small text-muted-foreground">
                            <li>
                                After the deploy, files written to{' '}
                                <code className="type-mono-small">
                                    {volume?.mountPath}
                                </code>{' '}
                                are reset with each deploy.
                            </li>
                            <li>
                                The volume stays on {volume?.server} for the
                                retention period (7 days by default), then it is
                                deleted. If it was backed up, an owner or admin
                                allows the deletion on the server page.
                            </li>
                            <li>Snapshots stay in your backup destination.</li>
                        </ul>
                    )}
                    {placed && (
                        <div className="flex flex-col gap-2">
                            <label
                                htmlFor="volume-remove-confirm"
                                className="type-label"
                            >
                                Type{' '}
                                <span className="type-mono-small">
                                    {volume?.mountPath}
                                </span>{' '}
                                to confirm
                            </label>
                            <Input
                                id="volume-remove-confirm"
                                value={form.data.confirm}
                                onChange={(event) =>
                                    form.setData('confirm', event.target.value)
                                }
                                autoComplete="off"
                                spellCheck={false}
                                className="font-mono"
                            />
                        </div>
                    )}
                    <InputError message={error} />
                    <div className="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => onOpenChange(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={form.processing || !ready}
                        >
                            {placed
                                ? 'Remove with next deploy'
                                : 'Remove volume'}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** Where apps of a framework usually keep data. */
function suggestions(
    context: SettingsContext,
): { path: string; label: string }[] {
    const framework = (
        context.settings.detected?.framework ?? context.node.logo
    ).toLowerCase();
    const list: { path: string; label: string }[] = [];

    if (framework.includes('laravel') || framework.includes('php')) {
        list.push({ path: '/app/storage', label: 'Laravel storage' });
    }

    if (framework.includes('rails') || framework.includes('ruby')) {
        list.push({ path: '/rails/storage', label: 'Active Storage' });
    }

    if (framework.includes('django') || framework.includes('python')) {
        list.push({ path: '/app/media', label: 'Uploaded media' });
    }

    list.push(
        { path: '/app/data', label: 'SQLite or app data' },
        { path: '/app/uploads', label: 'Uploads' },
    );

    return list.filter(
        (item) =>
            !context.settings.volumes.some(
                (volume) => volume.mountPath === item.path,
            ),
    );
}

const PATH = /^\/[A-Za-z0-9._@+=,-]+(?:\/[A-Za-z0-9._@+=,-]+)*$/;

/**
 * The guided create flow: pick where your app writes (suggestions from
 * the framework) or type a path, see what changes (one replica, pinned to
 * a server, backed up), then add it. Staged until the next deploy.
 */
function AddVolumeDialog({
    context,
    open,
    onOpenChange,
}: {
    context: SettingsContext;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const form = useForm({ mountPath: '' });
    const path = form.data.mountPath.trim();
    const valid = PATH.test(path);
    const replicas = context.settings.scaling.replicas;
    const picks = suggestions(context);

    useEffect(() => {
        if (!open) {
            form.reset();
            form.clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(store(context.route).url, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    const consequences = [
        {
            title: 'One replica, next to its data',
            detail:
                replicas > 1
                    ? `${context.node.name} runs ${replicas} replicas now. With a volume it runs as one.`
                    : `${context.node.name} keeps running as one replica.`,
            warn: replicas > 1,
        },
        {
            title: 'Stays on one server',
            detail: 'The next deploy picks a server, and the service stays there.',
            warn: false,
        },
        {
            title: 'Backed up as snapshots',
            detail: 'Sent to your backup destination on the same schedule as databases.',
            warn: false,
        },
    ];

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="gap-6 sm:max-w-lg">
                <form onSubmit={submit} className="flex flex-col gap-6">
                    <div className="flex flex-col gap-1.5">
                        <DialogTitle>Add a volume</DialogTitle>
                        <DialogDescription>
                            For files that need to survive deploys, such as
                            uploads, SQLite databases or caches you can’t
                            rebuild.
                        </DialogDescription>
                    </div>
                    <div className="flex flex-col gap-2">
                        <label
                            htmlFor="volume-mount-path"
                            className="type-label"
                        >
                            Where your app writes
                        </label>
                        <Input
                            id="volume-mount-path"
                            autoFocus
                            value={form.data.mountPath}
                            onChange={(event) =>
                                form.setData('mountPath', event.target.value)
                            }
                            placeholder="/app/data"
                            autoComplete="off"
                            spellCheck={false}
                            className="h-11 font-mono text-[15px]"
                            aria-invalid={
                                path !== '' && !valid ? true : undefined
                            }
                        />
                        <InputError message={form.errors.mountPath} />
                        {path !== '' && !valid && !form.errors.mountPath && (
                            <p className="type-small text-status-progress">
                                An absolute path inside the container, like
                                /app/data.
                            </p>
                        )}
                        {picks.length > 0 && (
                            <div className="flex flex-wrap gap-1.5 pt-1">
                                {picks.map((pick) => (
                                    <button
                                        key={pick.path}
                                        type="button"
                                        onClick={() =>
                                            form.setData('mountPath', pick.path)
                                        }
                                        className={cn(
                                            'inline-flex h-8 items-center gap-2 rounded-lg border px-2.5 type-small transition-colors duration-fast hover:border-border-strong hover:bg-hover',
                                            path === pick.path &&
                                                'border-foreground bg-hover',
                                        )}
                                    >
                                        <code className="type-mono-small">
                                            {pick.path}
                                        </code>
                                        <span className="text-muted-foreground">
                                            {pick.label}
                                        </span>
                                    </button>
                                ))}
                            </div>
                        )}
                    </div>
                    <ol className="flex flex-col gap-3 rounded-xl bg-recessed p-4">
                        {consequences.map((item, index) => (
                            <li key={item.title} className="flex gap-3">
                                <span
                                    aria-hidden="true"
                                    className={cn(
                                        'flex size-5 shrink-0 items-center justify-center rounded-full border type-small tabular-nums',
                                        item.warn
                                            ? 'border-status-progress text-status-progress'
                                            : 'text-muted-foreground',
                                    )}
                                >
                                    {index + 1}
                                </span>
                                <span className="flex flex-col gap-0.5">
                                    <span className="type-body font-medium">
                                        {item.title}
                                    </span>
                                    <span
                                        className={cn(
                                            'type-small',
                                            item.warn
                                                ? 'text-status-progress'
                                                : 'text-muted-foreground',
                                        )}
                                    >
                                        {item.detail}
                                    </span>
                                </span>
                            </li>
                        ))}
                    </ol>
                    <div className="flex items-center justify-between gap-3">
                        <span className="type-small text-muted-foreground">
                            Created with the next deploy.
                        </span>
                        <div className="flex gap-2">
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => onOpenChange(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                disabled={form.processing || !valid}
                            >
                                Add volume
                            </Button>
                        </div>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * Volumes (docs/07 `volumes`, docs/14 §2): data that survives deploys, as
 * cards. App and image services add them through a guided dialog
 * (staged; created on the next deploy, then the service stays on that
 * server and its volumes are backed up as snapshots).
 */
export function VolumesSection({ context }: { context: SettingsContext }) {
    const { node, settings } = context;
    const [adding, setAdding] = useState(false);
    const [changing, setChanging] = useState<Volume | null>(null);
    const [removing, setRemoving] = useState<Volume | null>(null);
    const canAdd =
        context.canUpdate && (node.kind === 'app' || node.kind === 'image');

    return (
        <SettingsPage
            title="Volumes"
            description={
                node.kind === 'database'
                    ? 'Where the database keeps its data, managed by launchroom.'
                    : 'Storage that keeps files across deploys. A service with a volume runs as one replica on the server that holds it.'
            }
            actions={
                canAdd &&
                settings.volumes.length > 0 && (
                    <Button
                        variant="outline"
                        data-setting="add-volume"
                        onClick={() => setAdding(true)}
                    >
                        <Plus /> Add volume
                    </Button>
                )
            }
        >
            {settings.volumes.length === 0 ? (
                <EmptyState
                    icon={HardDrive}
                    title="No volumes"
                    action={
                        canAdd && (
                            <Button
                                data-setting="add-volume"
                                onClick={() => setAdding(true)}
                            >
                                <Plus /> Add volume
                            </Button>
                        )
                    }
                >
                    Files outside a volume are reset with each deploy. Add a
                    volume for files that need to stay.
                </EmptyState>
            ) : (
                <ul data-setting="volumes-list" className="flex flex-col gap-4">
                    {settings.volumes.map((volume) => (
                        <VolumeCard
                            key={volume.id}
                            volume={volume}
                            context={context}
                            canChange={canAdd}
                            onChange={() => setChanging(volume)}
                            onRemove={() => setRemoving(volume)}
                        />
                    ))}
                </ul>
            )}
            {canAdd && (
                <>
                    <AddVolumeDialog
                        context={context}
                        open={adding}
                        onOpenChange={setAdding}
                    />
                    <ChangeVolumeDialog
                        key={`change-${changing?.id ?? ''}`}
                        context={context}
                        volume={changing}
                        onOpenChange={(open) => !open && setChanging(null)}
                    />
                    <RemoveVolumeDialog
                        key={`remove-${removing?.id ?? ''}`}
                        context={context}
                        volume={removing}
                        onOpenChange={(open) => !open && setRemoving(null)}
                    />
                </>
            )}
            {node.kind === 'database' && settings.volumes.length > 0 && (
                <p className="type-small text-muted-foreground">
                    launchroom manages where the database keeps its data, so
                    this volume can’t be moved or removed here. Removing the
                    database keeps its volume on the server.
                </p>
            )}
        </SettingsPage>
    );
}
