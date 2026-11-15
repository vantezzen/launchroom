import { router } from '@inertiajs/react';
import { useQueryClient } from '@tanstack/react-query';
import { useCallback, useEffect, useRef, useState } from 'react';
import { prefetchServiceDeployments } from '@/features/deployments/service-deployments';
import { SERVICE_PANEL_CACHE_TAG } from '@/lib/cache-tags';
import { prefersReducedMotion, withViewTransition } from '@/lib/motion';
import { canvas } from '@/routes/projects';
import services from '@/routes/projects/services';
import { placeholderPanel, targetFor } from './panel-target';
import type { Pending, Target } from './panel-target';

type Node = App.Data.Canvas.CanvasNodeData;
type Panel = App.Data.Canvas.ServicePanelData;

/** How long the pointer rests on a node before its panel starts loading. */
const HOVER_INTENT_MS = 75;

/** Visit options for opening/closing the panel: the logo morphs (design-system §8). */
export function panelVisitOptions() {
    return {
        only: ['panel'],
        preserveState: true,
        preserveScroll: true,
        viewTransition: !prefersReducedMotion(),
    };
}

/**
 * Opening, hovering, closing and switching tabs of the service panel on the
 * canvas (docs/08 §2): the panel draws at once from the canvas node and
 * slides in on the click while its own request fills in the rest.
 */
export function usePanelNavigation({
    project,
    environment,
    nodes,
    panel,
}: {
    project: string;
    environment: string;
    nodes: Node[];
    panel: Panel | null;
}) {
    const queryClient = useQueryClient();
    const [pending, setPending] = useState<Pending | null>(null);
    const visits = useRef(0);
    /** The newest panel visit that has finished (a cached answer can beat the transition). */
    const settled = useRef(0);
    const cancelOpen = useRef<VoidFunction | null>(null);
    const hoverTimer = useRef<number | null>(null);

    const panelUrl = useCallback(
        (serviceId: string, target: Target) => {
            const args = { project, environment, service: serviceId };

            return target.tab === 'settings' && target.section
                ? services.section({ ...args, section: target.section }).url
                : services.show({ ...args, tab: target.tab }).url;
        },
        [project, environment],
    );

    // The Deployments list loads in parallel with the panel's own request.
    const prefetchList = useCallback(
        (serviceId: string, target: Target) => {
            if (target.tab === 'deployments') {
                prefetchServiceDeployments(
                    queryClient,
                    project,
                    environment,
                    serviceId,
                );
            }
        },
        [queryClient, project, environment],
    );

    const openService = useCallback(
        (serviceId: string, tab?: string) => {
            if (!nodes.some((item) => item.id === serviceId)) {
                return;
            }

            const target = targetFor(tab);
            prefetchList(serviceId, target);

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
                ...panelVisitOptions(),
                viewTransition: false,
                onCancelToken: (token) => {
                    cancelOpen.current = token.cancel;
                },
                onFinish: () => {
                    settled.current = Math.max(settled.current, visit);
                    // A failed request drops the drawn panel again.
                    setPending((current) =>
                        current && current.visit <= visit ? null : current,
                    );
                },
            });
        },
        [nodes, panel, pending, panelUrl, prefetchList],
    );

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
                prefetchList(node.id, target);
                router.prefetch(
                    panelUrl(node.id, target),
                    { only: ['panel'], preserveState: true },
                    { cacheFor: '3s', cacheTags: [SERVICE_PANEL_CACHE_TAG] },
                );
            }, HOVER_INTENT_MS);
        },
        [nodes, panel, panelUrl, prefetchList],
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
            url: canvas([project, environment]).url,
            props: (current) => ({ ...current, panel: null }),
            preserveState: true,
            preserveScroll: true,
        });
    }, [pending, project, environment]);

    /**
     * Deployments, Logs, Console and Data load their own data, so switching
     * to them changes only the URL and the prop. Settings needs its page
     * data from the server.
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

            router.push<{ panel: Panel | null }>({
                // The tab's own link: Logs carries the time range.
                url: href ?? panelUrl(serviceId, targetFor(tab)),
                props: (current) => ({
                    ...current,
                    panel: current.panel
                        ? { ...current.panel, ...targetFor(tab) }
                        : current.panel,
                }),
                preserveState: true,
                preserveScroll: true,
            });
        },
        [pending, panel, panelUrl, openService],
    );

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

    return {
        pending: pending !== null,
        shownPanel,
        activeNode,
        openService,
        hoverService,
        closePanel,
        selectTab,
    };
}
