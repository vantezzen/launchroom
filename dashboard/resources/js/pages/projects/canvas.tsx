import { Head } from '@inertiajs/react';
import { useQueryClient } from '@tanstack/react-query';
import { ReactFlowProvider } from '@xyflow/react';
import { useCallback, useEffect, useState } from 'react';
import { Page } from '@/components/patterns/page';
import { AddServiceSheet } from '@/features/add-service/add-service-sheet';
import { CanvasList } from '@/features/canvas/canvas-list';
import { CanvasMetricsProvider } from '@/features/canvas/canvas-metrics';
import { EmptyCanvas } from '@/features/canvas/empty-canvas';
import { seedServiceDeployments } from '@/features/deployments/service-deployments';
import { ServiceCanvas } from '@/features/canvas/service-canvas';
import { StagedToolbarButton } from '@/features/staged-changes/staged-changes-pill';
import { PreviewCanvasBanner } from '@/features/previews/preview-canvas-banner';
import { DegradedServicesBanner } from '@/features/scheduling/degraded-services-banner';
import { openAddService } from '@/features/add-service/store';
import { EnvironmentLiveUpdates } from '@/features/projects/environment-live-updates';
import { FirstLiveCard } from '@/features/projects/first-live-card';
import { usePanelNavigation } from '@/features/service-panel/use-panel-navigation';
import { ServicePanel } from '@/features/service-panel/service-panel';
import { useIsMobile } from '@/hooks/use-mobile';
import services from '@/routes/projects/services';
import ProjectLayout from '@/features/app-shell/project-layout';
import { redeployService } from '@/features/service-lifecycle/actions';

type Props = App.Data.Canvas.ProjectShellPropsData & {
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

    const {
        pending,
        shownPanel,
        activeNode,
        openService,
        hoverService,
        closePanel,
        selectTab,
    } = usePanelNavigation({
        project: project.id,
        environment: environment.id,
        nodes,
        panel,
    });

    // Optimistic like the panel's Redeploy: the node says "Queued" at once.
    const redeploy = useCallback(
        (serviceId: string) =>
            redeployService(
                {
                    project: project.id,
                    environment: environment.id,
                    service: serviceId,
                },
                null,
            ),
        [project.id, environment.id],
    );

    const isMobile = useIsMobile();

    // `?add=1` (the projects page's "Add a service") opens the Add sheet
    // once. Other `add` values belong to the panel (Variables' `?add=NAME`).
    useEffect(() => {
        const url = new URL(window.location.href);

        if (url.searchParams.get('add') !== '1') {
            return;
        }

        if (abilities.manage) {
            openAddService();
        }

        url.searchParams.delete('add');
        window.history.replaceState(window.history.state, '', url);
    }, [abilities.manage]);

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
                                    onRedeploy={redeploy}
                                    onAdd={
                                        abilities.manage
                                            ? () => openAddService()
                                            : undefined
                                    }
                                    edgeBadges={preview?.edgeBadges}
                                />
                            )}
                            {nodes.length === 0 && (
                                <EmptyCanvas
                                    onAdd={
                                        abilities.manage
                                            ? (choice) =>
                                                  openAddService({ choice })
                                            : undefined
                                    }
                                />
                            )}
                            <FirstLiveCard
                                project={project.id}
                                environment={environment.id}
                            />
                            {shownPanel && activeNode && (
                                <ServicePanel
                                    key={activeNode.id}
                                    project={project.id}
                                    environment={environment.id}
                                    node={activeNode}
                                    panel={shownPanel}
                                    onClose={closePanel}
                                    onSelectTab={selectTab}
                                    pending={pending}
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

ProjectCanvas.layout = ProjectLayout;
