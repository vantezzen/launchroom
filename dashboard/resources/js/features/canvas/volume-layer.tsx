import { router } from '@inertiajs/react';
import {
    BaseEdge,
    EdgeLabelRenderer,
    getBezierPath,
    Handle,
    Position,
} from '@xyflow/react';
import type { Edge, EdgeProps, Node, NodeProps } from '@xyflow/react';
import { ArrowRight, HardDrive, TriangleAlert, X } from 'lucide-react';
import { memo, useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { requestJson } from '@/features/projects/http';
import { RemoteAccessButton } from '@/features/remote-access/remote-access';
import { panelVisitOptions } from '@/features/service-panel/service-panel';
import { cn } from '@/lib/utils';
import services from '@/routes/projects/services';
import volumeMounts from '@/routes/projects/services/volume-mounts';
import { FloatingPanel } from './floating-panel';
import { ServiceGlyph } from './service-glyph';
import { StagedChip } from './service-node';

/**
 * Volumes on the canvas (D73, docs/08 §3.3): each app volume is its own
 * node with an edge to every service that mounts it. Dragging from a
 * service onto a volume shares it with that service at its own path;
 * clicking a volume shows where it lives, who uses it, and the way into
 * its settings.
 */

type Volume = App.Data.Canvas.CanvasVolumeData;
type ServiceNodeInfo = App.Data.Canvas.CanvasNodeData;

export const VOLUME_NODE_WIDTH = 220;

export type VolumeNodeData = Volume & {
    connectable: boolean;
    sharedBy: number;
};

export type VolumeFlowNode = Node<VolumeNodeData, 'volume'>;

export type MountEdgeData = {
    volumeId: string;
    mountPath: string;
    owner: boolean;
    staged: boolean;
    removing: boolean;
    onOpen?: (volumeId: string, anchor: { x: number; y: number }) => void;
};

export type MountFlowEdge = Edge<MountEdgeData, 'mount'>;

export function isVolumeId(id: string | null | undefined): boolean {
    return typeof id === 'string' && id.startsWith('vol_');
}

/** "1.2 GB", "340 MB". */
export function formatVolumeSize(bytes: number): string {
    const gb = bytes / 1024 ** 3;

    if (gb >= 1) {
        return `${gb < 10 ? gb.toFixed(1) : Math.round(gb)} GB`;
    }

    return `${Math.max(1, Math.round(bytes / 1024 ** 2))} MB`;
}

function whereLine(volume: Volume): string {
    if (volume.server === null) {
        return 'Created with the next deploy';
    }

    return volume.sizeBytes !== null
        ? `${formatVolumeSize(volume.sizeBytes)} on ${volume.server}`
        : `On ${volume.server}`;
}

/**
 * A volume node: the drive glyph, where its own service mounts it, its
 * size and server. Smaller than a service: it's data, not something that
 * runs.
 */
export const VolumeNode = memo(function VolumeNode({
    data,
    selected,
}: NodeProps<VolumeFlowNode>) {
    const staged = data.removing ? 'remove' : data.staged ? 'new' : null;

    return (
        <div
            className={cn(
                'flex flex-col rounded-[12px] border bg-card text-left shadow-card',
                'transition-[box-shadow,outline-color,opacity] duration-fast ease-enter motion-reduce:transition-none',
                selected && 'ring-2 ring-foreground',
                staged &&
                    'outline-[1.5px] outline-offset-[3px] outline-signal outline-dashed',
                data.removing && 'opacity-60',
            )}
            style={{ width: VOLUME_NODE_WIDTH }}
        >
            <Handle
                type="target"
                position={Position.Left}
                isConnectable={data.connectable}
            />
            <div className="flex items-center gap-3 px-3.5 py-3">
                <span className="flex size-8 shrink-0 items-center justify-center rounded-[8px] border bg-recessed text-foreground">
                    <HardDrive aria-hidden="true" className="size-4" />
                </span>
                <div className="flex min-w-0 flex-1 flex-col">
                    <span className="truncate type-mono text-[13px] font-medium">
                        {data.mountPath}
                    </span>
                    <span className="truncate type-small text-muted-foreground">
                        {whereLine(data)}
                    </span>
                </div>
                {staged && <StagedChip effect={staged} />}
            </div>
            {data.sharedBy > 0 && (
                <div className="flex h-8 items-center border-t px-3.5 type-small text-muted-foreground">
                    Shared with {data.sharedBy}{' '}
                    {data.sharedBy === 1 ? 'service' : 'services'}
                </div>
            )}
        </div>
    );
});

/**
 * A mount: service → volume. The volume's own service draws a quiet line;
 * a service sharing it shows its own mount path on the edge.
 */
export function MountEdge({
    id,
    sourceX,
    sourceY,
    targetX,
    targetY,
    sourcePosition,
    targetPosition,
    data,
}: EdgeProps<MountFlowEdge>) {
    const [path, labelX, labelY] = getBezierPath({
        sourceX,
        sourceY,
        targetX,
        targetY,
        sourcePosition,
        targetPosition,
    });

    return (
        <>
            <BaseEdge id={id} path={path} />
            {data && !data.owner && (
                <EdgeLabelRenderer>
                    <button
                        type="button"
                        className={cn(
                            'nodrag nopan pointer-events-auto absolute rounded-full border bg-card px-2 py-0.5 type-mono-small text-muted-foreground shadow-card',
                            'transition-colors duration-fast hover:border-border-strong hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                            (data.staged || data.removing) &&
                                'border-dashed border-signal text-signal',
                            data.removing && 'line-through',
                        )}
                        style={{
                            transform: `translate(-50%, -50%) translate(${labelX}px, ${labelY}px)`,
                        }}
                        aria-label={`Mounted at ${data.mountPath}${data.removing ? ' until the next deploy' : data.staged ? ' (staged)' : ''}`}
                        onClick={(event) => {
                            const rect =
                                event.currentTarget.getBoundingClientRect();
                            data.onOpen?.(data.volumeId, {
                                x: rect.left + rect.width / 2,
                                y: rect.bottom,
                            });
                        }}
                    >
                        {data.mountPath}
                    </button>
                </EdgeLabelRenderer>
            )}
        </>
    );
}

/**
 * Who may mount a shared volume: apps, images, workers and crons
 * (VolumeSharing::BORROWERS). The server checks the rest.
 */
export function canMountVolume(
    service: ServiceNodeInfo | undefined,
    volume: Volume | undefined,
): boolean {
    return (
        service !== undefined &&
        volume !== undefined &&
        !service.removed &&
        !volume.removing &&
        service.kind !== 'database' &&
        !volume.mounts.some(
            (mount) => mount.serviceId === service.id && !mount.removing,
        )
    );
}

/**
 * The volume popover: where it lives, which services mount it and where,
 * detach for the services sharing it, SFTP and its settings through its
 * own service.
 */
export function VolumePopover({
    project,
    environment,
    volume,
    services: byId,
    anchor,
    canChange,
    onClose,
}: {
    project: string;
    environment: string;
    volume: Volume;
    services: Map<string, ServiceNodeInfo>;
    anchor: { x: number; y: number };
    canChange: boolean;
    onClose: () => void;
}) {
    const owner = byId.get(volume.ownerId);
    const [detaching, setDetaching] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);

    const detach = (serviceId: string) => {
        setDetaching(serviceId);
        setError(null);
        router.delete(
            volumeMounts.destroy([project, environment, serviceId, volume.id])
                .url,
            {
                preserveScroll: true,
                preserveState: true,
                onError: (errors) =>
                    setError(Object.values(errors)[0] ?? 'Detaching failed.'),
                onFinish: () => setDetaching(null),
            },
        );
    };

    const openSettings = () => {
        onClose();
        router.visit(
            services.section({
                project,
                environment,
                service: volume.ownerId,
                section: 'volumes',
            }),
            panelVisitOptions(),
        );
    };

    return (
        <FloatingPanel
            anchor={anchor}
            label={`Volume ${volume.mountPath}`}
            onClose={onClose}
            className="w-[min(380px,calc(100vw-24px))]"
        >
            <div className="flex flex-col gap-4">
                <div className="flex items-start gap-3">
                    <span className="flex size-9 shrink-0 items-center justify-center rounded-[9px] border bg-recessed">
                        <HardDrive aria-hidden="true" className="size-4" />
                    </span>
                    <div className="flex min-w-0 flex-col">
                        <h2 className="truncate type-mono text-[15px] font-medium">
                            {volume.mountPath}
                        </h2>
                        <p className="type-small text-muted-foreground">
                            {volume.removing
                                ? 'Removed with the next deploy'
                                : whereLine(volume)}
                        </p>
                    </div>
                </div>

                <div className="flex flex-col gap-1.5">
                    <span className="type-label text-muted-foreground">
                        Used by
                    </span>
                    <ul className="flex flex-col divide-y rounded-md border">
                        {volume.mounts.map((mount) => {
                            const service = byId.get(mount.serviceId);

                            return (
                                <li
                                    key={mount.serviceId}
                                    className="flex items-center gap-2.5 px-3 py-2"
                                >
                                    {service && (
                                        <ServiceGlyph
                                            logo={service.logo}
                                            size="sm"
                                        />
                                    )}
                                    <span className="flex min-w-0 flex-1 flex-col">
                                        <span className="truncate type-body font-medium">
                                            {service?.name ?? 'Service'}
                                        </span>
                                        <span
                                            className={cn(
                                                'truncate type-mono-small text-muted-foreground',
                                                mount.removing &&
                                                    'line-through',
                                            )}
                                        >
                                            {mount.mountPath}
                                        </span>
                                    </span>
                                    {mount.removing ? (
                                        <span className="shrink-0 type-small text-signal">
                                            Until the next deploy
                                        </span>
                                    ) : mount.staged ? (
                                        <span className="shrink-0 type-small text-signal">
                                            With the next deploy
                                        </span>
                                    ) : null}
                                    {canChange &&
                                        !mount.owner &&
                                        !mount.removing && (
                                            <Button
                                                variant="ghost"
                                                size="icon-sm"
                                                aria-label={`Detach from ${service?.name ?? 'this service'}`}
                                                disabled={detaching !== null}
                                                onClick={() =>
                                                    detach(mount.serviceId)
                                                }
                                            >
                                                {detaching ===
                                                mount.serviceId ? (
                                                    <Spinner className="size-3.5" />
                                                ) : (
                                                    <X />
                                                )}
                                            </Button>
                                        )}
                                </li>
                            );
                        })}
                    </ul>
                    {canChange && !volume.removing && (
                        <p className="type-small text-muted-foreground">
                            Drag from a service onto this volume to share it.
                            Services that share a volume run on the same server.
                        </p>
                    )}
                </div>

                {error && (
                    <p role="alert" className="flex gap-2 type-body">
                        <TriangleAlert
                            aria-hidden="true"
                            className="mt-0.5 size-4 shrink-0 text-status-error"
                        />
                        {error}
                    </p>
                )}

                <div className="flex items-center justify-between gap-2 border-t pt-3">
                    {volume.server !== null && !volume.removing ? (
                        <RemoteAccessButton
                            serviceRef={{
                                project,
                                environment,
                                service: volume.ownerId,
                            }}
                            kind="volume"
                            volume={volume.id}
                            variant="ghost"
                            size="sm"
                            className="-ml-2"
                        />
                    ) : (
                        <span />
                    )}
                    <Button
                        variant="secondary"
                        size="sm"
                        onClick={openSettings}
                    >
                        {owner ? `Settings in ${owner.name}` : 'Settings'}
                        <ArrowRight aria-hidden="true" />
                    </Button>
                </div>
            </div>
        </FloatingPanel>
    );
}

const PATH = /^\/[A-Za-z0-9._@+=,-]+(?:\/[A-Za-z0-9._@+=,-]+)*$/;

/**
 * After a drag from a service onto a volume: pick where the service sees
 * the files, with what changes for it (it runs where the volume is).
 * Refused up front when it can't run there.
 */
export function AttachVolumePopover({
    project,
    environment,
    service,
    volume,
    owner,
    anchor,
    onClose,
}: {
    project: string;
    environment: string;
    service: ServiceNodeInfo;
    volume: Volume;
    owner: ServiceNodeInfo | undefined;
    anchor: { x: number; y: number };
    onClose: () => void;
}) {
    const [preview, setPreview] =
        useState<App.Data.Canvas.VolumeAttachPreviewData | null>(null);
    const [mountPath, setMountPath] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);
    const route = [project, environment, service.id, volume.id] as const;

    useEffect(() => {
        const controller = new AbortController();

        requestJson<App.Data.Canvas.VolumeAttachPreviewData>(
            'get',
            volumeMounts.preview([...route]).url,
            undefined,
            controller.signal,
        )
            .then((data) => {
                setPreview(data);
                setMountPath((current) => current || data.suggestedPath);
            })
            .catch((failure: unknown) => {
                if (!controller.signal.aborted) {
                    setError(
                        failure instanceof Error
                            ? failure.message
                            : 'The check failed.',
                    );
                }
            });

        return () => controller.abort();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [project, environment, service.id, volume.id]);

    const path = mountPath.trim();
    const valid = PATH.test(path);
    const blocked = preview?.problem ?? null;

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (!valid || blocked) {
            return;
        }

        setSubmitting(true);
        setError(null);
        router.post(
            volumeMounts.store([...route]).url,
            { mountPath: path },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: onClose,
                onError: (errors) =>
                    setError(Object.values(errors)[0] ?? 'Attaching failed.'),
                onFinish: () => setSubmitting(false),
            },
        );
    };

    return (
        <FloatingPanel
            anchor={anchor}
            label={`Share ${volume.mountPath} with ${service.name}`}
            onClose={onClose}
            className="w-[min(400px,calc(100vw-24px))]"
        >
            <form onSubmit={submit} className="flex flex-col gap-4">
                <div className="flex flex-col gap-1">
                    <h2 className="type-heading">
                        Share this volume with {service.name}
                    </h2>
                    <p className="type-small text-muted-foreground">
                        {service.name} sees the same files as{' '}
                        {owner?.name ?? 'its service'}, at the path you pick.
                    </p>
                </div>

                {preview === null && error === null && (
                    <div className="flex items-center gap-2 type-body text-muted-foreground">
                        <Spinner className="size-4" />
                        Checking where {service.name} can run
                    </div>
                )}

                {preview && !blocked && (
                    <>
                        <div className="flex flex-col gap-2">
                            <label
                                htmlFor="attach-volume-path"
                                className="type-label"
                            >
                                Mount path in {service.name}
                            </label>
                            <Input
                                id="attach-volume-path"
                                autoFocus
                                value={mountPath}
                                onChange={(event) =>
                                    setMountPath(event.target.value)
                                }
                                placeholder="/app/data"
                                autoComplete="off"
                                spellCheck={false}
                                className="font-mono"
                                aria-invalid={
                                    path !== '' && !valid ? true : undefined
                                }
                            />
                            {path !== '' && !valid && (
                                <InputError message="An absolute path inside the container, like /app/data." />
                            )}
                        </div>
                        {preview.notices.length > 0 && (
                            <ul className="flex flex-col gap-2 rounded-md bg-recessed p-3 type-small text-muted-foreground">
                                {preview.notices.map((notice) => (
                                    <li key={notice} className="flex gap-2">
                                        <TriangleAlert
                                            aria-hidden="true"
                                            className="mt-0.5 size-3.5 shrink-0 text-status-progress"
                                        />
                                        {notice}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </>
                )}

                {(blocked ?? error) && (
                    <p role="alert" className="flex gap-2 type-body">
                        <TriangleAlert
                            aria-hidden="true"
                            className="mt-0.5 size-4 shrink-0 text-status-error"
                        />
                        {blocked ?? error}
                    </p>
                )}

                <div className="flex items-center justify-between gap-2">
                    <span className="type-small text-muted-foreground">
                        Applies with the next deploy.
                    </span>
                    <div className="flex gap-2">
                        <Button
                            type="button"
                            variant="secondary"
                            size="sm"
                            onClick={onClose}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            size="sm"
                            disabled={
                                !preview ||
                                blocked !== null ||
                                !valid ||
                                submitting
                            }
                        >
                            Share volume
                        </Button>
                    </div>
                </div>
            </form>
        </FloatingPanel>
    );
}
