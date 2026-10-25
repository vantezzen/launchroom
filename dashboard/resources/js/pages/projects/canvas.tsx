import { Head, router } from '@inertiajs/react';
import { ReactFlowProvider } from '@xyflow/react';
import { useCallback } from 'react';
import { Page } from '@/components/patterns/page';
import { AddServiceSheet } from '@/features/add-service/add-service-sheet';
import { CanvasList } from '@/features/canvas/canvas-list';
import { CanvasMetricsProvider } from '@/features/canvas/canvas-metrics';
import { EmptyCanvas } from '@/features/canvas/empty-canvas';
import { ServiceCanvas } from '@/features/canvas/service-canvas';
import { StagedToolbarButton } from '@/features/staged-changes/staged-changes-pill';
import { PreviewCanvasBanner } from '@/features/previews/preview-canvas-banner';
import { DegradedServicesBanner } from '@/features/scheduling/degraded-services-banner';
import { openAddService } from '@/features/projects/add-service-store';
import { EnvironmentLiveUpdates } from '@/features/projects/environment-live-updates';
import type { ProjectShellProps } from '@/features/projects/types';
import {
    panelVisitOptions,
    ServicePanel,
} from '@/features/service-panel/service-panel';
import { useIsMobile } from '@/hooks/use-mobile';
import { redeploy } from '@/routes/deployments/service';
import { canvas } from '@/routes/projects';
import services from '@/routes/projects/services';

type Props = ProjectShellProps & {
    environment: App.Data.Canvas.EnvironmentData;
    nodes: App.Data.Canvas.CanvasNodeData[];
    edges: App.Data.Canvas.CanvasEdgeData[];
    panel: App.Data.Canvas.ServicePanelData | null;
    parentCandidates: { id: string; name: string }[];
    engines: App.Data.Canvas.DatabaseEngineOptionData[];
    isPreview: boolean;
    preview: App.Data.Previews.PreviewCanvasData | null;
};

export default function ProjectCanvas({
    project,
    environment,
    nodes,
    edges,
    panel,
    abilities,
    parentCandidates,
    engines,
    preview,
}: Props) {
    const openService = useCallback(
        (serviceId: string, tab: string = 'deployments') =>
            router.visit(
                services.show({
                    project: project.id,
                    environment: environment.id,
                    service: serviceId,
                    tab,
                }),
                panelVisitOptions(),
            ),
        [project.id, environment.id],
    );

    const closePanel = useCallback(
        () =>
            router.visit(canvas([project.id, environment.id]), {
                ...panelVisitOptions(),
            }),
        [project.id, environment.id],
    );

    const redeployService = useCallback(
        (serviceId: string) =>
            router.post(redeploy([project.id, environment.id, serviceId]).url),
        [project.id, environment.id],
    );

    const isMobile = useIsMobile();
    const activeNode = panel
        ? nodes.find((node) => node.id === panel.serviceId)
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
                only={['nodes', 'edges', 'panel']}
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
                            services.show({
                                project: project.id,
                                environment: environment.id,
                                service: serviceId,
                                tab: 'backups',
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
                                        "Review & deploy" as the toolbar. */}
                                    {!panel && (
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
                                    abilities={abilities}
                                    activeServiceId={panel?.serviceId ?? null}
                                    onOpenService={openService}
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
                            {panel && activeNode && (
                                <ServicePanel
                                    key={activeNode.id}
                                    project={project.id}
                                    environment={environment.id}
                                    node={activeNode}
                                    panel={panel}
                                    onClose={closePanel}
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
