import type {
    Errors,
    HttpExceptionResponse,
    OptimisticCallback,
    PageProps,
} from '@inertiajs/core';
import { toast } from 'sonner';

type Node = App.Data.Canvas.CanvasNodeData;
type Panel = App.Data.Canvas.ServicePanelData;
type Controls = App.Data.Canvas.ServiceControlsData;

/**
 * Optimistic service state for lifecycle actions (docs/10 §3): the canvas
 * node's status and the panel's header actions change on the click; Inertia
 * restores both when the request fails. Pages without a canvas (the
 * deployment page) have nothing to change.
 */
export function optimisticService(
    service: string,
    status: Pick<Node['status'], 'state' | 'label'>,
    controls: Partial<Controls> = {},
): OptimisticCallback {
    return (props: PageProps) => {
        const { nodes, panel } = props as {
            nodes?: Node[];
            panel?: Panel | null;
        };
        const next: Partial<PageProps> = {};

        if (Array.isArray(nodes)) {
            const since = new Date().toISOString();
            next.nodes = nodes.map((node) =>
                node.id === service
                    ? { ...node, status: { ...node.status, ...status, since } }
                    : node,
            );
        }

        if (panel?.serviceId === service && panel.controls) {
            next.panel = {
                ...panel,
                controls: { ...panel.controls, ...controls },
            };
        }

        return next;
    };
}

/** A new deployment of the service is queued: what the server will say once it is. */
export function optimisticQueued(service: string): OptimisticCallback {
    return optimisticService(
        service,
        { state: 'idle', label: 'Queued' },
        { deploying: true },
    );
}

/** Statuses whose answer is an error page rather than a redirect with errors. */
const CALM = new Set([403, 404, 422, 500, 502, 503]);

/**
 * Visit callbacks for an optimistic action: the change already shows, so a
 * failure rolls it back (Inertia) and says so in one calm toast instead of
 * an error dialog.
 */
export function rollbackToast(message: string) {
    return {
        onError: (errors: Errors) =>
            toast.error(Object.values(errors)[0] ?? message),
        onHttpException: (response: HttpExceptionResponse) => {
            if (!CALM.has(response.status)) {
                return;
            }

            toast.error(message);

            return false;
        },
    };
}
