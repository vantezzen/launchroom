/**
 * Which tab the service panel opens on, and the panel drawn on click while
 * its request runs (docs/08 §2, pages/projects/canvas.tsx).
 */

type Node = App.Data.Canvas.CanvasNodeData;
type Panel = App.Data.Canvas.ServicePanelData;
export type Target = { tab: string; section: string | null };
/** The panel drawn on click while its request runs (docs/08 §2). */
export type Pending = Target & { visit: number; serviceId: string };

/**
 * Which tab a service opens on: the one asked for (the node's hover icons,
 * ⌘K, links), else always Deployments, so a click on a node lands in the
 * same place every time (docs/08 §2).
 */
export function targetFor(explicit?: string): Target {
    // Variables is a settings page (docs/08 §16).
    if (explicit === 'variables') {
        return { tab: 'settings', section: 'variables' };
    }

    return { tab: explicit ?? 'deployments', section: null };
}

/**
 * The panel while its request runs: what the canvas already knows. The
 * open panel of the same service lends its data (switching tabs keeps the
 * header actions); another service waits for its own.
 */
export function placeholderPanel(
    pending: Pending,
    current: Panel | null,
    node: Node,
): Panel {
    const same = current?.serviceId === pending.serviceId ? current : null;
    const settings = pending.tab === 'settings';

    return {
        serviceId: pending.serviceId,
        tab: pending.tab,
        section: settings ? pending.section : null,
        settings: settings ? (same?.settings ?? null) : null,
        canUpdate: same?.canUpdate ?? false,
        canDelete: same?.canDelete ?? false,
        // Team permissions: the same for every service of the project.
        canConsole: current?.canConsole ?? pending.tab === 'console',
        canData:
            node.kind === 'database' &&
            (current?.canData ?? pending.tab === 'data'),
        actionNeeded: same?.actionNeeded ?? 0,
        actionNeededFinding: same?.actionNeededFinding ?? null,
        controls: same?.controls ?? null,
        origin: same?.origin ?? null,
    };
}
