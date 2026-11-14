import {
    Background,
    BackgroundVariant,
    MarkerType,
    MiniMap,
    ReactFlow,
    useNodesState,
    useReactFlow,
} from '@xyflow/react';
import type {
    Connection,
    IsValidConnection,
    Node,
    NodeMouseHandler,
    OnConnectEnd,
    OnNodeDrag,
} from '@xyflow/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import type { KeyboardEvent as ReactKeyboardEvent } from 'react';
import { router, usePage } from '@inertiajs/react';
import { toast } from 'sonner';
import { useClipboard } from '@/hooks/use-clipboard';
import { serviceAnalyticsHref } from '@/lib/links';
import { requestJson } from '@/features/projects/http';
import { RemoveServiceDialog } from '@/features/service-settings/remove-service-dialog';
import {
    optimisticService,
    rollbackToast,
} from '@/features/deployments/optimistic';
import { layout as layoutRoute } from '@/routes/projects';
import lifecycle from '@/routes/services/lifecycle';
import { autoLayout, NODE_HEIGHT, NODE_WIDTH } from './auto-layout';
import { StagedToolbarButton } from '@/features/staged-changes/staged-changes-pill';
import { CanvasToolbar } from './canvas-toolbar';
import { ConnectPopover } from './connect-popover';
import { ConnectionEdge } from './connection-edge';
import { EdgePopover } from './edge-popover';
import { GroupFrame } from './group-frame';
import type { GroupFlowNode } from './group-frame';
import { framesFor, groupIndex, groupOf } from './groups';
import { NodeActionConfirm, NodeContextMenu } from './node-context-menu';
import type { NodeMenuAction } from './node-context-menu';
import { NodeActionsContext, ServiceNode } from './service-node';
import type { NodeActions } from './service-node';
import type {
    ConnectionFlowEdge,
    ServiceFlowNode,
    ServiceNodeData,
} from './types';
import {
    AttachVolumePopover,
    canMountVolume,
    isVolumeId,
    MountEdge,
    VOLUME_NODE_WIDTH,
    VolumeNode,
    VolumePopover,
} from './volume-layer';
import type { MountFlowEdge, VolumeFlowNode } from './volume-layer';
import './canvas.css';

const nodeTypes = {
    service: ServiceNode,
    volume: VolumeNode,
    'group-frame': GroupFrame,
};

type CanvasNode = ServiceFlowNode | VolumeFlowNode | GroupFlowNode;
type CanvasEdge = ConnectionFlowEdge | MountFlowEdge;

function isService(node: Node): node is ServiceFlowNode {
    return node.type === 'service';
}

function isVolume(node: Node): node is VolumeFlowNode {
    return node.type === 'volume';
}
const edgeTypes = { connection: ConnectionEdge, mount: MountEdge };

type XY = { x: number; y: number };

const NO_VOLUMES: App.Data.Canvas.CanvasVolumeData[] = [];

/** Volume → service mounts, as layout edges. */
function mountLinks(volumes: App.Data.Canvas.CanvasVolumeData[]) {
    return volumes.flatMap((volume) =>
        volume.mounts.map((mount) => ({
            id: `mount-${volume.id}-${mount.serviceId}`,
            source: mount.serviceId,
            target: volume.id,
        })),
    );
}

/** The floating service panel's width on desktop (service-panel.tsx). */
export const PANEL_WIDTH = 680;

function prefersReducedMotion(): boolean {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

/**
 * Next free spot right of everything placed (services added elsewhere).
 */
function nextFreeSpot(placed: XY[], index: number): XY {
    if (placed.length === 0) {
        return { x: 0, y: index * (NODE_HEIGHT + 48) };
    }

    const maxX = Math.max(...placed.map((point) => point.x));
    const minY = Math.min(...placed.map((point) => point.y));

    return { x: maxX + NODE_WIDTH + 120, y: minY + index * (NODE_HEIGHT + 48) };
}

export type ServiceCanvasProps = {
    project: string;
    environment: string;
    nodes: App.Data.Canvas.CanvasNodeData[];
    edges: App.Data.Canvas.CanvasEdgeData[];
    abilities: App.Data.Canvas.ProjectAbilitiesData;
    activeServiceId: string | null;
    onOpenService: (serviceId: string, tab?: string) => void;
    /** The pointer rests on a service (null: it left): its panel starts loading. */
    onHoverService?: (serviceId: string | null) => void;
    onClosePanel: () => void;
    onRedeploy: (serviceId: string) => void;
    /** Opens the add sheet (toolbar "Add"); absent without the permission. */
    onAdd?: () => void;
    /** Previews: badges on edges into shared/cloned databases. */
    edgeBadges?: App.Data.Previews.PreviewEdgeBadgeData[];
    /** App volumes as nodes, shared between services (D73). */
    volumes?: App.Data.Canvas.CanvasVolumeData[];
};

/**
 * The project canvas (docs/08 §3.3): services as nodes, connections as
 * edges, drag to connect, positions saved per environment instantly,
 * elkjs auto-layout, keyboard navigation (arrows move focus between
 * nodes, Enter opens the panel, Shift+F10 opens the menu).
 */
export function ServiceCanvas({
    project,
    environment,
    nodes,
    edges,
    abilities,
    activeServiceId,
    onOpenService,
    onHoverService,
    onClosePanel,
    onRedeploy,
    onAdd,
    edgeBadges,
    volumes = NO_VOLUMES,
}: ServiceCanvasProps) {
    const flow = useReactFlow<CanvasNode, CanvasEdge>();
    const [flowNodes, setFlowNodes, onNodesChange] =
        useNodesState<ServiceFlowNode>([]);
    const [volumeNodes, setVolumeNodes, onVolumeNodesChange] =
        useNodesState<VolumeFlowNode>([]);
    const [openVolume, setOpenVolume] = useState<{
        id: string;
        anchor: XY;
    } | null>(null);
    const [pendingAttach, setPendingAttach] = useState<{
        service: string;
        volume: string;
        anchor: XY;
    } | null>(null);
    const [pendingConnection, setPendingConnection] = useState<{
        from: string;
        to: string;
        anchor: XY;
    } | null>(null);
    const [openEdge, setOpenEdge] = useState<{
        id: string;
        anchor: XY;
    } | null>(null);
    const [menu, setMenu] = useState<{ id: string; position: XY } | null>(null);
    // Redeploy and Restart from the node menu ask first (design-system §6).
    const [confirming, setConfirming] = useState<{
        id: string;
        kind: 'redeploy' | 'restart';
        position: XY;
    } | null>(null);
    const [removing, setRemoving] = useState<string | null>(null);
    const [connecting, setConnecting] = useState(false);
    const [, copy] = useClipboard();
    const initialFit = useRef(false);
    // The panel's Console tab rule (InteractiveSessionPolicy::viewAny).
    const canConsole =
        usePage().props.teams?.permissions.includes('containers.exec') ?? false;

    const byId = useMemo(
        () => new Map(nodes.map((node) => [node.id, node])),
        [nodes],
    );
    const volumesById = useMemo(
        () => new Map(volumes.map((volume) => [volume.id, volume])),
        [volumes],
    );

    const savePositions = useCallback(
        (positions: Record<string, XY>) => {
            if (!abilities.manage || Object.keys(positions).length === 0) {
                return;
            }

            requestJson('put', layoutRoute([project, environment]).url, {
                positions,
            }).catch(() =>
                toast.error(
                    'The layout couldn’t be saved. Try moving the service again.',
                ),
            );
        },
        [abilities.manage, project, environment],
    );

    // Props → flow nodes. Local positions win once a node is on the canvas
    // (they're saved instantly); new nodes take the server position.
    useEffect(() => {
        setFlowNodes((current) => {
            const existing = new Map(current.map((node) => [node.id, node]));
            const placed = current.map((node) => node.position);
            let unplaced = 0;

            return nodes.map((data): ServiceFlowNode => {
                const previous = existing.get(data.id);
                const connectable = abilities.connect && !data.removed;
                const nodeData: ServiceNodeData = { ...data, connectable };

                return {
                    ...previous,
                    id: data.id,
                    type: 'service',
                    position:
                        previous?.position ??
                        data.position ??
                        nextFreeSpot(placed, unplaced++),
                    data: nodeData,
                    selected: data.id === activeServiceId,
                    draggable: abilities.manage,
                    connectable,
                    deletable: false,
                    ariaLabel: `${data.name}: ${data.status.label}${data.staged ? ', staged change' : ''}`,
                };
            });
        });
    }, [
        nodes,
        activeServiceId,
        abilities.manage,
        abilities.connect,
        setFlowNodes,
    ]);

    // Volumes → volume nodes. A volume without a saved spot sits right of
    // its own service.
    useEffect(() => {
        setVolumeNodes((current) => {
            const existing = new Map(current.map((node) => [node.id, node]));
            const perOwner = new Map<string, number>();

            return volumes.map((data): VolumeFlowNode => {
                const previous = existing.get(data.id);
                const owner =
                    flow.getNode(data.ownerId)?.position ??
                    byId.get(data.ownerId)?.position ??
                    null;
                const index = perOwner.get(data.ownerId) ?? 0;
                perOwner.set(data.ownerId, index + 1);
                const connectable =
                    abilities.manage && abilities.connect && !data.removing;

                return {
                    ...previous,
                    id: data.id,
                    type: 'volume',
                    position: previous?.position ??
                        data.position ?? {
                            x: (owner?.x ?? 0) + NODE_WIDTH + 96,
                            y: (owner?.y ?? 0) + index * 84,
                        },
                    data: {
                        ...data,
                        connectable,
                        sharedBy: data.mounts.filter(
                            (mount) => !mount.owner && !mount.removing,
                        ).length,
                    },
                    selected: openVolume?.id === data.id,
                    draggable: abilities.manage,
                    connectable,
                    deletable: false,
                    ariaLabel: `Volume ${data.mountPath}${data.server ? ` on ${data.server}` : ''}`,
                };
            });
        });
        // flow and byId only seed a new volume's first spot.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [
        volumes,
        openVolume?.id,
        abilities.manage,
        abilities.connect,
        setVolumeNodes,
    ]);

    const tidyUp = useCallback(
        async (save: boolean) => {
            const positions = await autoLayout(
                [
                    ...nodes.map((node) => ({
                        id: node.id,
                        partition: groupIndex(groupOf(node)),
                    })),
                    // Volumes sit with the data (D73).
                    ...volumes.map((volume) => ({
                        id: volume.id,
                        partition: groupIndex('data'),
                    })),
                ],
                [...edges, ...mountLinks(volumes)],
            );

            setFlowNodes((current) =>
                current.map((node) => ({
                    ...node,
                    position: positions[node.id] ?? node.position,
                })),
            );
            setVolumeNodes((current) =>
                current.map((node) => ({
                    ...node,
                    position: positions[node.id] ?? node.position,
                })),
            );

            if (save) {
                savePositions(positions);
            }

            window.requestAnimationFrame(() =>
                flow.fitView({
                    padding: 0.25,
                    maxZoom: 1,
                    duration: prefersReducedMotion() ? 0 : 200,
                }),
            );
        },
        [
            nodes,
            edges,
            volumes,
            flow,
            savePositions,
            setFlowNodes,
            setVolumeNodes,
        ],
    );

    // New projects (nothing placed yet) get an elkjs layout, saved once.
    const unplacedKey = nodes
        .filter((node) => node.position === null)
        .map((node) => node.id)
        .join(',');

    useEffect(() => {
        if (unplacedKey === '' || nodes.length === 0) {
            return;
        }

        if (nodes.every((node) => node.position === null)) {
            void tidyUp(true);

            return;
        }

        // Some new services without a position: keep the spot we picked.
        const ids = new Set(unplacedKey.split(','));
        const positions = Object.fromEntries(
            flow
                .getNodes()
                .filter((node) => isService(node) && ids.has(node.id))
                .map((node) => [node.id, node.position]),
        );
        savePositions(positions);
        // Only when the set of unplaced services changes.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [unplacedKey]);

    // Keep the selected node visible beside the open panel.
    useEffect(() => {
        if (!activeServiceId) {
            return;
        }

        const timer = window.setTimeout(() => {
            const element = document.querySelector<HTMLElement>(
                `.react-flow__node[data-id="${CSS.escape(activeServiceId)}"]`,
            );
            const pane =
                document.querySelector<HTMLElement>('.launchroom-canvas');

            if (!element || !pane || window.innerWidth < 768) {
                return;
            }

            const rect = element.getBoundingClientRect();
            const box = pane.getBoundingClientRect();
            const visibleRight =
                box.right - Math.min(PANEL_WIDTH, box.width * 0.62) - 12 - 32;

            if (rect.right > visibleRight || rect.left < box.left) {
                const viewport = flow.getViewport();
                const dx =
                    Math.max(box.left + 32, visibleRight - rect.width) -
                    rect.left;
                void flow.setViewport(
                    { ...viewport, x: viewport.x + dx },
                    { duration: prefersReducedMotion() ? 0 : 200 },
                );
            }
        }, 60);

        return () => window.clearTimeout(timer);
    }, [activeServiceId, flow]);

    const onNodeDragStop: OnNodeDrag<CanvasNode> = useCallback(
        (_event, _node, dragged) => {
            savePositions(
                Object.fromEntries(
                    dragged
                        .filter((node) => isService(node) || isVolume(node))
                        .map((node) => [
                            node.id,
                            {
                                x: Math.round(node.position.x),
                                y: Math.round(node.position.y),
                            },
                        ]),
                ),
            );
        },
        [savePositions],
    );

    // Group frames (Public / App / Data) follow the nodes, also while dragging.
    const frameNodes = useMemo((): GroupFlowNode[] => {
        const frames = framesFor([
            ...flowNodes.map((node) => ({
                id: node.id,
                group: groupOf(node.data),
                x: node.position.x,
                y: node.position.y,
                width: node.measured?.width ?? NODE_WIDTH,
                height: node.measured?.height ?? NODE_HEIGHT,
            })),
            ...volumeNodes.map((node) => ({
                id: node.id,
                group: 'data' as const,
                x: node.position.x,
                y: node.position.y,
                width: node.measured?.width ?? VOLUME_NODE_WIDTH,
                height: node.measured?.height ?? 64,
            })),
        ]);

        return frames.map((frame) => ({
            id: `group-${frame.id}`,
            type: 'group-frame',
            position: { x: frame.x, y: frame.y },
            width: frame.width,
            height: frame.height,
            data: { group: frame.id, count: frame.count },
            draggable: false,
            selectable: false,
            focusable: false,
            connectable: false,
            deletable: false,
            zIndex: -1,
            ariaRole: 'presentation',
        }));
    }, [flowNodes, volumeNodes]);

    const allNodes = useMemo(
        (): CanvasNode[] => [...frameNodes, ...flowNodes, ...volumeNodes],
        [frameNodes, flowNodes, volumeNodes],
    );

    const flowEdges = useMemo(
        (): ConnectionFlowEdge[] =>
            edges.map((edge) => ({
                id: edge.id,
                source: edge.source,
                target: edge.target,
                type: 'connection',
                className: [
                    edge.type,
                    edge.staged ? 'staged' : '',
                    edgeBadges?.some(
                        (badge) =>
                            badge.serviceId === edge.target &&
                            badge.tone === 'danger',
                    ) && edge.type === 'connection'
                        ? 'shared'
                        : '',
                ]
                    .filter(Boolean)
                    .join(' '),
                focusable: false,
                selectable: false,
                deletable: false,
                markerEnd:
                    edge.type === 'connection' || edge.type === 'inherited'
                        ? {
                              type: MarkerType.ArrowClosed,
                              width: 16,
                              height: 16,
                              color:
                                  edge.type === 'inherited'
                                      ? 'var(--canvas-edge-muted)'
                                      : 'var(--canvas-edge)',
                          }
                        : undefined,
                data: {
                    ...edge,
                    onOpen: (id, anchor) => setOpenEdge({ id, anchor }),
                    badge:
                        edge.type === 'connection'
                            ? edgeBadges?.find(
                                  (badge) => badge.serviceId === edge.target,
                              )
                            : undefined,
                },
                ariaLabel:
                    edge.type === 'process'
                        ? `${byId.get(edge.target)?.name ?? ''} runs the image of ${byId.get(edge.source)?.name ?? ''}`
                        : edge.type === 'inherited'
                          ? `${byId.get(edge.source)?.name ?? ''} uses the connection of ${byId.get(edge.via ?? '')?.name ?? ''} to ${byId.get(edge.target)?.name ?? ''}`
                          : `${byId.get(edge.source)?.name ?? ''} connects to ${byId.get(edge.target)?.name ?? ''}`,
            })),
        [edges, byId, edgeBadges],
    );

    // Service → volume mounts (D73): the owner's quiet, shared ones labelled.
    const mountEdges = useMemo(
        (): MountFlowEdge[] =>
            volumes.flatMap((volume) =>
                volume.mounts.map((mount): MountFlowEdge => ({
                    id: `mount-${volume.id}-${mount.serviceId}`,
                    source: mount.serviceId,
                    target: volume.id,
                    type: 'mount',
                    className: [
                        'mount',
                        mount.staged || mount.removing ? 'staged' : '',
                    ]
                        .filter(Boolean)
                        .join(' '),
                    focusable: false,
                    selectable: false,
                    deletable: false,
                    data: {
                        volumeId: volume.id,
                        mountPath: mount.mountPath,
                        owner: mount.owner,
                        staged: mount.staged,
                        removing: mount.removing,
                        onOpen: (id, anchor) => setOpenVolume({ id, anchor }),
                    },
                    ariaLabel: `${byId.get(mount.serviceId)?.name ?? ''} mounts ${volume.mountPath} at ${mount.mountPath}`,
                })),
            ),
        [volumes, byId],
    );

    const allEdges = useMemo(
        (): CanvasEdge[] => [...flowEdges, ...mountEdges],
        [flowEdges, mountEdges],
    );

    const isValidConnection: IsValidConnection<CanvasEdge> = useCallback(
        (connection) => {
            const { source, target } = connection;

            // Onto a volume: share it with the service (D73).
            if (isVolumeId(target)) {
                return (
                    abilities.manage &&
                    canMountVolume(byId.get(source), volumesById.get(target))
                );
            }

            const to = byId.get(target);

            return (
                source !== target &&
                to !== undefined &&
                !to.removed &&
                (to.kind === 'database' ||
                    to.kind === 'app' ||
                    to.kind === 'image') &&
                !edges.some(
                    (edge) =>
                        edge.type === 'connection' &&
                        edge.source === source &&
                        edge.target === target,
                )
            );
        },
        [byId, edges, volumesById, abilities.manage],
    );

    const anchorFor = useCallback((id: string): XY => {
        const element = document.querySelector<HTMLElement>(
            `.react-flow__node[data-id="${CSS.escape(id)}"]`,
        );

        if (element) {
            const rect = element.getBoundingClientRect();

            return { x: rect.left + rect.width / 2, y: rect.bottom };
        }

        return { x: window.innerWidth / 2, y: window.innerHeight / 3 };
    }, []);

    const onConnect = useCallback(
        (connection: Connection) => {
            if (isVolumeId(connection.target)) {
                setPendingAttach({
                    service: connection.source,
                    volume: connection.target,
                    anchor: anchorFor(connection.target),
                });

                return;
            }

            setPendingConnection({
                from: connection.source,
                to: connection.target,
                anchor: anchorFor(connection.target),
            });
        },
        [anchorFor],
    );

    // Dropping anywhere on a node (not only its handle) connects too.
    const onConnectEnd: OnConnectEnd = useCallback(
        (event, state) => {
            setConnecting(false);

            if (state.isValid || !state.fromNode) {
                return;
            }

            const point =
                'changedTouches' in event ? event.changedTouches[0] : event;
            const target = document
                .elementFromPoint(point.clientX, point.clientY)
                ?.closest<HTMLElement>('.react-flow__node')?.dataset.id;
            const fromSource = state.fromHandle?.type !== 'target';
            const source = fromSource ? state.fromNode.id : target;
            const destination = fromSource ? target : state.fromNode.id;

            if (
                source &&
                destination &&
                isValidConnection({
                    source,
                    target: destination,
                    sourceHandle: null,
                    targetHandle: null,
                })
            ) {
                if (isVolumeId(destination)) {
                    setPendingAttach({
                        service: source,
                        volume: destination,
                        anchor: anchorFor(destination),
                    });

                    return;
                }

                setPendingConnection({
                    from: source,
                    to: destination,
                    anchor: anchorFor(destination),
                });
            }
        },
        [anchorFor, isValidConnection],
    );

    const onNodeClick: NodeMouseHandler<CanvasNode> = useCallback(
        (_event, node) => {
            if (isVolume(node)) {
                setOpenVolume({ id: node.id, anchor: anchorFor(node.id) });

                return;
            }

            if (isService(node)) {
                onOpenService(node.id);
            }
        },
        [onOpenService, anchorFor],
    );

    const onNodeContextMenu: NodeMouseHandler<CanvasNode> = useCallback(
        (event, node) => {
            event.preventDefault();

            if (!isService(node)) {
                return;
            }

            setMenu({
                id: node.id,
                position: { x: event.clientX, y: event.clientY },
            });
        },
        [],
    );

    const focusNode = useCallback(
        (id: string) => {
            const element = document.querySelector<HTMLElement>(
                `.react-flow__node[data-id="${CSS.escape(id)}"]`,
            );
            const node = flow.getNode(id);

            if (node) {
                void flow.setCenter(
                    node.position.x + NODE_WIDTH / 2,
                    node.position.y + NODE_HEIGHT / 2,
                    {
                        zoom: flow.getZoom(),
                        duration: prefersReducedMotion() ? 0 : 150,
                    },
                );
            }

            element?.focus({ preventScroll: true });
        },
        [flow],
    );

    const onKeyDown = useCallback(
        (event: ReactKeyboardEvent<HTMLDivElement>) => {
            const element = (event.target as HTMLElement).closest<HTMLElement>(
                '.react-flow__node',
            );
            const id = element?.dataset.id;

            if (!element || !id || element !== event.target) {
                return;
            }

            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();

                if (isVolumeId(id)) {
                    setOpenVolume({ id, anchor: anchorFor(id) });

                    return;
                }

                onOpenService(id);

                return;
            }

            if (
                event.key === 'ContextMenu' ||
                (event.shiftKey && event.key === 'F10')
            ) {
                event.preventDefault();
                const rect = element.getBoundingClientRect();
                setMenu({
                    id,
                    position: { x: rect.left + 16, y: rect.bottom },
                });

                return;
            }

            const directions: Record<string, XY> = {
                ArrowRight: { x: 1, y: 0 },
                ArrowLeft: { x: -1, y: 0 },
                ArrowDown: { x: 0, y: 1 },
                ArrowUp: { x: 0, y: -1 },
            };
            const direction = directions[event.key];
            const from = flow.getNode(id);

            if (!direction || !from) {
                return;
            }

            event.preventDefault();

            let best: { id: string; score: number } | null = null;

            for (const candidate of flow.getNodes()) {
                if (candidate.id === id || !isService(candidate)) {
                    continue;
                }

                const dx = candidate.position.x - from.position.x;
                const dy = candidate.position.y - from.position.y;
                const along = dx * direction.x + dy * direction.y;
                const across = Math.abs(dx * direction.y + dy * direction.x);

                if (along <= 0) {
                    continue;
                }

                const score = along + across * 2;

                if (best === null || score < best.score) {
                    best = { id: candidate.id, score };
                }
            }

            if (best) {
                focusNode(best.id);
            }
        },
        [flow, focusNode, onOpenService, anchorFor],
    );

    const onMenuAction = useCallback(
        (action: NodeMenuAction) => {
            const node = menu ? byId.get(menu.id) : undefined;
            setMenu(null);

            if (!node) {
                return;
            }

            switch (action) {
                case 'open':
                    onOpenService(node.id);
                    break;
                case 'logs':
                    onOpenService(node.id, action);
                    break;
                case 'analytics':
                    router.visit(
                        serviceAnalyticsHref({
                            project,
                            environment,
                            service: node.id,
                            hasDomain: node.domain !== null,
                        }),
                    );
                    break;
                case 'settings':
                case 'variables':
                    onOpenService(node.id, action);
                    break;
                case 'copy-private':
                    if (node.privateAddress) {
                        void copy(node.privateAddress).then((ok) =>
                            ok
                                ? toast.success(`Copied ${node.privateAddress}`)
                                : toast.error('Copying failed.'),
                        );
                    }
                    break;
                case 'redeploy':
                case 'restart':
                    if (menu) {
                        setConfirming({
                            id: node.id,
                            kind: action,
                            position: menu.position,
                        });
                    }
                    break;
                case 'delete':
                    setRemoving(node.id);
                    break;
            }
        },
        [menu, byId, onOpenService, onRedeploy, copy, project, environment],
    );

    const pendingFrom = pendingConnection
        ? byId.get(pendingConnection.from)
        : undefined;
    const pendingTo = pendingConnection
        ? byId.get(pendingConnection.to)
        : undefined;
    const edgeDetails = openEdge
        ? edges.find((edge) => edge.id === openEdge.id)
        : undefined;
    const menuNode = menu ? byId.get(menu.id) : undefined;
    const confirmingNode = confirming ? byId.get(confirming.id) : undefined;
    const nodeActions = useMemo<NodeActions>(
        () => ({
            openService: onOpenService,
            openMenu: (id, position) => setMenu({ id, position }),
            canConsole,
        }),
        [onOpenService, canConsole],
    );
    const restart = (node: App.Data.Canvas.CanvasNodeData) =>
        router.post(
            lifecycle.restart({ project, environment, service: node.id }).url,
            { processes: true },
            {
                preserveScroll: true,
                preserveState: true,
                optimistic: optimisticService(
                    node.id,
                    { state: 'progress', label: 'Restarting' },
                    { deploying: true },
                ),
                onSuccess: () => toast.success(`Restarting ${node.name}`),
                ...rollbackToast(`Couldn’t restart ${node.name}.`),
            },
        );
    const openVolumeData = openVolume
        ? volumesById.get(openVolume.id)
        : undefined;
    const attachService = pendingAttach
        ? byId.get(pendingAttach.service)
        : undefined;
    const attachVolume = pendingAttach
        ? volumesById.get(pendingAttach.volume)
        : undefined;
    const removingNode = removing ? byId.get(removing) : undefined;

    return (
        <NodeActionsContext.Provider value={nodeActions}>
            <div className="absolute inset-0" onKeyDown={onKeyDown}>
                <ReactFlow<CanvasNode, CanvasEdge>
                    className={
                        connecting
                            ? 'launchroom-canvas connecting'
                            : 'launchroom-canvas'
                    }
                    nodes={allNodes}
                    edges={allEdges}
                    nodeTypes={nodeTypes}
                    edgeTypes={edgeTypes}
                    onNodesChange={(changes) => {
                        onNodesChange(
                            changes.filter(
                                (change) =>
                                    !('id' in change) ||
                                    (!change.id.startsWith('group-') &&
                                        !isVolumeId(change.id)),
                            ) as Parameters<typeof onNodesChange>[0],
                        );
                        onVolumeNodesChange(
                            changes.filter(
                                (change) =>
                                    'id' in change && isVolumeId(change.id),
                            ) as Parameters<typeof onVolumeNodesChange>[0],
                        );
                    }}
                    onNodeDragStop={onNodeDragStop}
                    onNodeClick={onNodeClick}
                    onNodeMouseEnter={(_event, node) =>
                        isService(node) && onHoverService?.(node.id)
                    }
                    onNodeMouseLeave={() => onHoverService?.(null)}
                    onNodeContextMenu={onNodeContextMenu}
                    onPaneClick={() => activeServiceId && onClosePanel()}
                    onConnect={onConnect}
                    onConnectStart={() => setConnecting(true)}
                    onConnectEnd={onConnectEnd}
                    isValidConnection={isValidConnection}
                    nodesDraggable={abilities.manage}
                    nodesConnectable={abilities.connect}
                    elementsSelectable
                    selectNodesOnDrag={false}
                    deleteKeyCode={null}
                    selectionKeyCode={null}
                    multiSelectionKeyCode={null}
                    disableKeyboardA11y
                    autoPanOnNodeFocus
                    minZoom={0.25}
                    maxZoom={1.75}
                    fitView={!initialFit.current}
                    fitViewOptions={{ padding: 0.25, maxZoom: 1 }}
                    onInit={() => {
                        initialFit.current = true;
                    }}
                    proOptions={{ hideAttribution: true }}
                    aria-label="Services in this environment"
                >
                    <Background
                        variant={BackgroundVariant.Dots}
                        gap={20}
                        size={1.5}
                        color="var(--canvas-dot)"
                    />
                    {nodes.length > 0 && (
                        <MiniMap<CanvasNode>
                            position="bottom-right"
                            pannable
                            zoomable
                            ariaLabel="Canvas overview"
                            nodeColor={(node) =>
                                isService(node) ? 'var(--card)' : 'transparent'
                            }
                            nodeStrokeColor={(node) =>
                                !isService(node)
                                    ? 'transparent'
                                    : node.data.staged
                                      ? 'var(--signal)'
                                      : 'var(--border-strong)'
                            }
                            nodeStrokeWidth={6}
                            nodeBorderRadius={14}
                            maskColor="color-mix(in oklab, var(--background) 55%, transparent)"
                            maskStrokeColor="var(--signal)"
                            maskStrokeWidth={1.5}
                            className="hidden lg:block"
                            style={{ width: 168, height: 108 }}
                        />
                    )}
                </ReactFlow>

                {nodes.length > 0 && (
                    <div
                        className="pointer-events-none absolute inset-x-0 bottom-4 z-10 flex justify-center motion-safe:transition-[padding] motion-safe:duration-slow"
                        style={{
                            paddingRight: activeServiceId
                                ? `min(${PANEL_WIDTH + 24}px, calc(62% + 24px))`
                                : 0,
                        }}
                    >
                        <CanvasToolbar
                            onAdd={onAdd}
                            onTidy={
                                abilities.manage && nodes.length > 1
                                    ? () => void tidyUp(true)
                                    : undefined
                            }
                            staged={
                                <StagedToolbarButton
                                    project={project}
                                    environment={environment}
                                    divider
                                />
                            }
                        />
                    </div>
                )}

                {pendingConnection && pendingFrom && pendingTo && (
                    <ConnectPopover
                        project={project}
                        environment={environment}
                        from={pendingFrom}
                        to={pendingTo}
                        anchor={pendingConnection.anchor}
                        onClose={() => setPendingConnection(null)}
                    />
                )}

                {openEdge && edgeDetails && (
                    <EdgePopover
                        project={project}
                        environment={environment}
                        edge={edgeDetails}
                        from={byId.get(edgeDetails.source)?.name ?? 'service'}
                        to={byId.get(edgeDetails.target)?.name ?? 'service'}
                        via={
                            edgeDetails.via
                                ? (byId.get(edgeDetails.via)?.name ?? 'its app')
                                : undefined
                        }
                        anchor={openEdge.anchor}
                        canDelete={abilities.connect}
                        onClose={() => setOpenEdge(null)}
                    />
                )}

                {openVolume && openVolumeData && (
                    <VolumePopover
                        project={project}
                        environment={environment}
                        volume={openVolumeData}
                        services={byId}
                        anchor={openVolume.anchor}
                        canChange={abilities.manage}
                        onClose={() => setOpenVolume(null)}
                    />
                )}

                {pendingAttach && attachService && attachVolume && (
                    <AttachVolumePopover
                        project={project}
                        environment={environment}
                        service={attachService}
                        volume={attachVolume}
                        owner={byId.get(attachVolume.ownerId)}
                        anchor={pendingAttach.anchor}
                        onClose={() => setPendingAttach(null)}
                    />
                )}

                {menu && menuNode && (
                    <NodeContextMenu
                        node={menuNode}
                        position={menu.position}
                        canDeploy={abilities.deploy}
                        canManage={abilities.manage}
                        onAction={onMenuAction}
                        onClose={() => setMenu(null)}
                    />
                )}

                {confirming && confirmingNode && (
                    <NodeActionConfirm
                        node={confirmingNode}
                        kind={confirming.kind}
                        position={confirming.position}
                        onConfirm={() =>
                            confirming.kind === 'redeploy'
                                ? onRedeploy(confirmingNode.id)
                                : restart(confirmingNode)
                        }
                        onClose={() => setConfirming(null)}
                    />
                )}

                {removingNode && (
                    <RemoveServiceDialog
                        project={project}
                        environment={environment}
                        service={removingNode}
                        open
                        onOpenChange={(open) => !open && setRemoving(null)}
                    />
                )}
            </div>
        </NodeActionsContext.Provider>
    );
}
