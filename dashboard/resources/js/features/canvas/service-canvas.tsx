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
import { toast } from 'sonner';
import { useClipboard } from '@/hooks/use-clipboard';
import { requestJson } from '@/features/projects/http';
import { RemoveServiceDialog } from '@/features/service-settings/remove-service-dialog';
import { layout as layoutRoute } from '@/routes/projects';
import { autoLayout, NODE_HEIGHT, NODE_WIDTH } from './auto-layout';
import { StagedToolbarButton } from '@/features/staged-changes/staged-changes-pill';
import { CanvasToolbar } from './canvas-toolbar';
import { ConnectPopover } from './connect-popover';
import { ConnectionEdge } from './connection-edge';
import { EdgePopover } from './edge-popover';
import { GroupFrame } from './group-frame';
import type { GroupFlowNode } from './group-frame';
import { framesFor, groupIndex, groupOf } from './groups';
import { NodeContextMenu } from './node-context-menu';
import type { NodeMenuAction } from './node-context-menu';
import { ServiceNode } from './service-node';
import type {
    ConnectionFlowEdge,
    ServiceFlowNode,
    ServiceNodeData,
} from './types';
import './canvas.css';

const nodeTypes = { service: ServiceNode, 'group-frame': GroupFrame };

type CanvasNode = ServiceFlowNode | GroupFlowNode;

function isService(node: Node): node is ServiceFlowNode {
    return node.type === 'service';
}
const edgeTypes = { connection: ConnectionEdge };

type XY = { x: number; y: number };

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
    onClosePanel: () => void;
    onRedeploy: (serviceId: string) => void;
    /** Opens the add sheet (toolbar "Add"); absent without the permission. */
    onAdd?: () => void;
    /** Previews: badges on edges into shared/cloned databases. */
    edgeBadges?: App.Data.Previews.PreviewEdgeBadgeData[];
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
    onClosePanel,
    onRedeploy,
    onAdd,
    edgeBadges,
}: ServiceCanvasProps) {
    const flow = useReactFlow<CanvasNode, ConnectionFlowEdge>();
    const [flowNodes, setFlowNodes, onNodesChange] =
        useNodesState<ServiceFlowNode>([]);
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
    const [removing, setRemoving] = useState<string | null>(null);
    const [connecting, setConnecting] = useState(false);
    const [, copy] = useClipboard();
    const initialFit = useRef(false);

    const byId = useMemo(
        () => new Map(nodes.map((node) => [node.id, node])),
        [nodes],
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

    const tidyUp = useCallback(
        async (save: boolean) => {
            const positions = await autoLayout(
                nodes.map((node) => ({
                    id: node.id,
                    partition: groupIndex(groupOf(node)),
                })),
                edges,
            );

            setFlowNodes((current) =>
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
        [nodes, edges, flow, savePositions, setFlowNodes],
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
                    dragged.filter(isService).map((node) => [
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
        const frames = framesFor(
            flowNodes.map((node) => ({
                id: node.id,
                group: groupOf(node.data),
                x: node.position.x,
                y: node.position.y,
                width: node.measured?.width ?? NODE_WIDTH,
                height: node.measured?.height ?? NODE_HEIGHT,
            })),
        );

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
    }, [flowNodes]);

    const allNodes = useMemo(
        (): CanvasNode[] => [...frameNodes, ...flowNodes],
        [frameNodes, flowNodes],
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
                    edge.type === 'connection'
                        ? {
                              type: MarkerType.ArrowClosed,
                              width: 16,
                              height: 16,
                              color: 'var(--canvas-edge)',
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
                        : `${byId.get(edge.source)?.name ?? ''} connects to ${byId.get(edge.target)?.name ?? ''}`,
            })),
        [edges, byId, edgeBadges],
    );

    const isValidConnection: IsValidConnection<ConnectionFlowEdge> =
        useCallback(
            (connection) => {
                const { source, target } = connection;
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
            [byId, edges],
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
        (_event, node) => isService(node) && onOpenService(node.id),
        [onOpenService],
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
        [flow, focusNode, onOpenService],
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
                    onRedeploy(node.id);
                    break;
                case 'delete':
                    setRemoving(node.id);
                    break;
            }
        },
        [menu, byId, onOpenService, onRedeploy, copy],
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
    const removingNode = removing ? byId.get(removing) : undefined;

    return (
        <div className="absolute inset-0" onKeyDown={onKeyDown}>
            <ReactFlow<CanvasNode, ConnectionFlowEdge>
                className={
                    connecting
                        ? 'launchroom-canvas connecting'
                        : 'launchroom-canvas'
                }
                nodes={allNodes}
                edges={flowEdges}
                nodeTypes={nodeTypes}
                edgeTypes={edgeTypes}
                onNodesChange={(changes) =>
                    onNodesChange(
                        changes.filter(
                            (change) =>
                                !('id' in change) ||
                                !change.id.startsWith('group-'),
                        ) as Parameters<typeof onNodesChange>[0],
                    )
                }
                onNodeDragStop={onNodeDragStop}
                onNodeClick={onNodeClick}
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
                    anchor={openEdge.anchor}
                    canDelete={abilities.connect}
                    onClose={() => setOpenEdge(null)}
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
    );
}
