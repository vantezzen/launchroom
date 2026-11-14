import { Head, router } from '@inertiajs/react';
import { useQueryClient } from '@tanstack/react-query';
import { ReactFlowProvider } from '@xyflow/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Page } from '@/components/patterns/page';
import { AddServiceSheet } from '@/features/add-service/add-service-sheet';
import { CanvasList } from '@/features/canvas/canvas-list';
import { CanvasMetricsProvider } from '@/features/canvas/canvas-metrics';
import { EmptyCanvas } from '@/features/canvas/empty-canvas';
import {
    optimisticQueued,
    rollbackToast,
} from '@/features/deployments/optimistic';
import {
    prefetchServiceDeployments,
    seedServiceDeployments,
} from '@/features/deployments/service-deployments';
import { ServiceCanvas } from '@/features/canvas/service-canvas';
import { StagedToolbarButton } from '@/features/staged-changes/staged-changes-pill';
import { PreviewCanvasBanner } from '@/features/previews/preview-canvas-banner';
import { DegradedServicesBanner } from '@/features/scheduling/degraded-services-banner';
import { openAddService } from '@/features/projects/add-service-store';
import { EnvironmentLiveUpdates } from '@/features/projects/environment-live-updates';
import type { ProjectShellProps } from '@/features/projects/types';
import type { Pending, Target } from '@/features/service-panel/panel-target';
import {
    placeholderPanel,
    targetFor,
} from '@/features/service-panel/panel-target';
import { ServicePanel } from '@/features/service-panel/service-panel';
import { useIsMobile } from '@/hooks/use-mobile';
import { withViewTransition } from '@/lib/motion';
import { redeploy } from '@/routes/deployments/service';
import { canvas } from '@/routes/projects';
import services from '@/routes/projects/services';

type Props = ProjectShellProps & {
    environment: App.Data.Canvas.EnvironmentData;
    nodes: App.Data.Canvas.CanvasNodeData[];
    edges: App.Data.Canvas.CanvasEdgeData[];
    volumes: App.Data.Canvas.CanvasVolumeData[];
    panel: App.Data.Canvas.ServicePanelData | null;
    parentCandidates: { id: string; name: string }[];
    engines: App.Data.Canvas.DatabaseEngineOptionData[];
    isPreview: boolean;
    preview: App.Data.Previews.PreviewCanvasData | null;
    /** A full visit of the Deployments tab: its list (deep links draw complete). */
    deployments?: App.Data.Deployments.ServiceDeploymentsData | null;
};

/** How long the pointer rests on a node before its panel starts loading. */
const HOVER_INTENT_MS = 75;

export default function ProjectCanvas({
    project,
    environment,
    nodes,
    edges,
    volumes,
    panel,
    abilities,
    parentCandidates,
    engines,
    preview,
    deployments,
}: Props) {
    const queryClient = useQueryClient();
    const [pending, setPending] = useState<Pending | null>(null);
    const visits = useRef(0);
    /** The newest panel visit that has finished (a cached answer can beat the transition). */
    const settled = useRef(0);
    const cancelOpen = useRef<VoidFunction | null>(null);
    const hoverTimer = useRef<number | null>(null);

    // A deep link to the Deployments tab brought its list along (B2).
    useState(() => {
        if (deployments && panel) {
            seedServiceDeployments(
                queryClient,
                environment.id,
                panel.serviceId,
                deployments,
            );
        }

        return null;
    });

    const panelUrl = useCallback(
        (serviceId: string, target: Target) => {
            const args = {
                project: project.id,
                environment: environment.id,
                service: serviceId,
            };

            return target.tab === 'settings' && target.section
                ? services.section({ ...args, section: target.section }).url
                : services.show({ ...args, tab: target.tab }).url;
        },
        [project.id, environment.id],
    );

    /**
     * Opens a service (docs/08 §2): the panel draws at once from the
     * canvas node and slides in on the click; its Deployments list loads
     * in parallel with the panel's own request, which fills in the rest.
     */
    const openService = useCallback(
        (serviceId: string, tab?: string) => {
            const node = nodes.find((item) => item.id === serviceId);

            if (!node) {
                return;
            }

            const target = targetFor(tab);

            if (target.tab === 'deployments') {
                prefetchServiceDeployments(
                    queryClient,
                    project.id,
                    environment.id,
                    serviceId,
                );
            }

            const visit = ++visits.current;
            const next = { ...target, visit, serviceId };
            const opening =
                (pending?.serviceId ?? panel?.serviceId) !== serviceId;

            const draw = () => {
                if (settled.current < visit) {
                    setPending(next);
                }
            };

            // The logo morphs from the node into the header on the click.
            if (opening) {
                withViewTransition(draw);
            } else {
                draw();
            }

            router.visit(panelUrl(serviceId, target), {
                only: ['panel'],
                preserveState: true,
                preserveScroll: true,
                viewTransition: false,
                onCancelToken: (token) => {
                    cancelOpen.current = token.cancel;
                },
                onFinish: () => {
                    settled.current = Math.max(settled.current, visit);
                    // The answer replaces the drawn panel; a failed request
                    // drops it again.
                    setPending((current) =>
                        current && current.visit <= visit ? null : current,
                    );
                },
            });
        },
        [
            nodes,
            panel,
            pending,
            panelUrl,
            queryClient,
            project.id,
            environment.id,
        ],
    );

    /** Hovering a node starts loading its panel and list (hover intent). */
    const hoverService = useCallback(
        (serviceId: string | null) => {
            if (hoverTimer.current !== null) {
                window.clearTimeout(hoverTimer.current);
                hoverTimer.current = null;
            }

            const node = serviceId
                ? nodes.find((item) => item.id === serviceId)
                : undefined;

            if (!node || panel?.serviceId === node.id) {
                return;
            }

            hoverTimer.current = window.setTimeout(() => {
                hoverTimer.current = null;
                const target = targetFor();

                if (target.tab === 'deployments') {
                    prefetchServiceDeployments(
                        queryClient,
                        project.id,
                        environment.id,
                        node.id,
                    );
                }

                router.prefetch(
                    panelUrl(node.id, target),
                    { only: ['panel'], preserveState: true },
                    { cacheFor: '3s', cacheTags: ['service-panel'] },
                );
            }, HOVER_INTENT_MS);
        },
        [nodes, panel, panelUrl, queryClient, project.id, environment.id],
    );

    useEffect(
        () => () => {
            if (hoverTimer.current !== null) {
                window.clearTimeout(hoverTimer.current);
            }
        },
        [],
    );

    // Closing needs nothing from the server: the URL and the prop change here.
    const closePanel = useCallback(() => {
        if (pending) {
            cancelOpen.current?.();
            setPending(null);
        }

        router.push({
            url: canvas([project.id, environment.id]).url,
            props: (current) => ({ ...current, panel: null }),
            preserveState: true,
            preserveScroll: true,
        });
    }, [pending, project.id, environment.id]);

    /**
     * Panel tabs: Deployments, Logs, Console and Data load their
     * own data, so switching to them changes only the URL and the prop.
     * Settings needs its page data from the server.
     */
    const selectTab = useCallback(
        (tab: string, href?: string) => {
            const serviceId = pending?.serviceId ?? panel?.serviceId;

            if (!serviceId) {
                return;
            }

            if (pending || !panel || tab === 'settings') {
                openService(serviceId, tab);

                return;
            }

            router.push<Props>({
                // The tab's own link: Logs carries the time range.
                url: href ?? panelUrl(serviceId, { tab, section: null }),
                props: (current) => ({
                    ...current,
                    panel: current.panel
                        ? { ...current.panel, tab, section: null }
                        : current.panel,
                }),
                preserveState: true,
                preserveScroll: true,
            });
        },
        [pending, panel, panelUrl, openService],
    );

    // Optimistic like the panel's Redeploy: the node says "Queued" at once.
    const redeployService = useCallback(
        (serviceId: string) =>
            router.post(
                redeploy([project.id, environment.id, serviceId]).url,
                {},
                {
                    optimistic: optimisticQueued(serviceId),
                    ...rollbackToast('Couldn’t redeploy.'),
                },
            ),
        [project.id, environment.id],
    );

    const isMobile = useIsMobile();
    const pendingNode = pending
        ? nodes.find((node) => node.id === pending.serviceId)
        : undefined;
    const shownPanel =
        pending && pendingNode
            ? placeholderPanel(pending, panel, pendingNode)
            : panel;
    const activeNode = shownPanel
        ? nodes.find((node) => node.id === shownPanel.serviceId)
        : undefined;

    return (
        <>
            <Head
                title={
                    activeNode
                        ? `${activeNode.name} - ${project.name}`
                        : `${project.name} - ${environment.name}`
                }
            />
            <EnvironmentLiveUpdates
                environments={[environment.id]}
                only={['nodes', 'edges', 'volumes', 'panel']}
            />

            <Page size="full">
                <ReactFlowProvider>
                    {preview && (
                        <PreviewCanvasBanner
                            project={project.id}
                            preview={preview}
                        />
                    )}
                    <DegradedServicesBanner
                        nodes={nodes}
                        backupsHref={(serviceId) =>
                            services.section({
                                project: project.id,
                                environment: environment.id,
                                service: serviceId,
                                section: 'backups',
                            }).url
                        }
                    />
                    <CanvasMetricsProvider
                        project={project.id}
                        environment={environment.id}
                    >
                        <div className="relative flex min-h-0 flex-1 flex-col overflow-hidden">
                            {isMobile && nodes.length > 0 ? (
                                <>
                                    <CanvasList
                                        nodes={nodes}
                                        onOpen={(id) => openService(id)}
                                        onAdd={
                                            abilities.manage
                                                ? () => openAddService()
                                                : undefined
                                        }
                                    />
                                    {/* Phones: the same bottom-centre
                                        "Review & apply" as the toolbar. */}
                                    {!shownPanel && (
                                        <div className="pointer-events-none absolute inset-x-0 bottom-4 z-10 flex justify-center px-4">
                                            <div className="pointer-events-auto flex rounded-xl border bg-card p-1 shadow-float [&:not(:has(button))]:hidden">
                                                <StagedToolbarButton
                                                    project={project.id}
                                                    environment={environment.id}
                                                />
                                            </div>
                                        </div>
                                    )}
                                </>
                            ) : (
                                <ServiceCanvas
                                    project={project.id}
                                    environment={environment.id}
                                    nodes={nodes}
                                    edges={edges}
                                    volumes={volumes}
                                    abilities={abilities}
                                    activeServiceId={
                                        shownPanel?.serviceId ?? null
                                    }
                                    onOpenService={openService}
                                    onHoverService={hoverService}
                                    onClosePanel={closePanel}
                                    onRedeploy={redeployService}
                                    onAdd={
                                        abilities.manage
                                            ? () => openAddService()
                                            : undefined
                                    }
                                    edgeBadges={preview?.edgeBadges}
                                />
                            )}
                            {nodes.length === 0 && (
                                <EmptyCanvas canAdd={abilities.manage} />
                            )}
                            {shownPanel && activeNode && (
                                <ServicePanel
                                    key={activeNode.id}
                                    project={project.id}
                                    environment={environment.id}
                                    node={activeNode}
                                    panel={shownPanel}
                                    onClose={closePanel}
                                    onSelectTab={selectTab}
                                    pending={pending !== null}
                                    reserveControls={
                                        abilities.deploy && !activeNode.removed
                                    }
                                />
                            )}
                        </div>
                    </CanvasMetricsProvider>
                    {abilities.manage && (
                        <AddServiceSheet
                            project={project.id}
                            environment={environment.id}
                            engines={engines}
                            parents={parentCandidates}
                            consumers={
                                abilities.connect
                                    ? nodes
                                          .filter(
                                              (node) =>
                                                  node.kind !== 'database' &&
                                                  !node.removed,
                                          )
                                          .map((node) => ({
                                              id: node.id,
                                              name: node.name,
                                              logo: node.logo,
                                          }))
                                    : []
                            }
                        />
                    )}
                </ReactFlowProvider>
            </Page>
        </>
    );
}
