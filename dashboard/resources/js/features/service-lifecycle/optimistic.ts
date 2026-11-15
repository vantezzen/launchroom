import type {
    Errors,
    HttpExceptionResponse,
    OptimisticCallback,
    PageProps,
} from '@inertiajs/core';
import { toast } from 'sonner';
import { vocabulary } from '@/lib/vocabulary';

type Node = App.Data.Canvas.CanvasNodeData;
type Panel = App.Data.Canvas.ServicePanelData;
type Controls = App.Data.Canvas.ServiceControlsData;

/**
 * Optimistic service state for lifecycle actions (docs/10 §3): the canvas
 * node's status and the panel's header actions change on the click; Inertia
 * restores both when the request fails. Pages without a canvas (the
 * deployment page) have nothing to change. The status is the one
 * App\Support\Canvas\NodeStatus answers for `reason`; `label` only where
 * the server words it by step too (a deploying node).
 */
export function optimisticService(
    service: string,
    reason: App.Enums.NodeStatusReason,
    controls: Partial<Controls> = {},
    label?: string,
): OptimisticCallback {
    const { tone, label: word } = vocabulary.NodeStatusReason[reason];
    const status = { reason, state: tone, label: label ?? word, detail: null };

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
    return optimisticService(service, 'queued', { deploying: true });
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
