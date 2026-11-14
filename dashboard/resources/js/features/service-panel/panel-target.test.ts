import { expect, test } from 'vitest';
import { placeholderPanel, targetFor } from './panel-target';

type Node = App.Data.Canvas.CanvasNodeData;
type Panel = App.Data.Canvas.ServicePanelData;

function status(state: App.Enums.CanvasNodeState): Node['status'] {
    return { state, label: state, since: null, deploymentId: null };
}

function node(overrides: Partial<Node> = {}): Node {
    return {
        id: 'svc_web',
        kind: 'app',
        status: status('active'),
        ...overrides,
    } as Node;
}

function panel(overrides: Partial<Panel> = {}): Panel {
    return {
        serviceId: 'svc_api',
        tab: 'logs',
        section: null,
        settings: null,
        canUpdate: true,
        canDelete: true,
        canConsole: true,
        canData: false,
        actionNeeded: 2,
        actionNeededFinding: null,
        controls: null,
        origin: null,
        ...overrides,
    };
}

test('opens on Deployments by default and on the tab asked for', () => {
    expect(targetFor()).toEqual({ tab: 'deployments', section: null });
    expect(targetFor('logs')).toEqual({ tab: 'logs', section: null });
    expect(targetFor('variables')).toEqual({
        tab: 'settings',
        section: 'variables',
    });
});

test('the drawn panel borrows only what is true for the service', () => {
    const controls = {
        canControl: true,
    } as App.Data.Canvas.ServiceControlsData;
    const open = panel({ serviceId: 'svc_web', controls, actionNeeded: 1 });

    const sameService = placeholderPanel(
        { visit: 1, serviceId: 'svc_web', tab: 'logs', section: null },
        open,
        node(),
    );
    expect(sameService.controls).toBe(controls);
    expect(sameService.actionNeeded).toBe(1);
    expect(sameService.tab).toBe('logs');

    const other = placeholderPanel(
        { visit: 2, serviceId: 'svc_db', tab: 'deployments', section: null },
        open,
        node({ id: 'svc_db', kind: 'database' }),
    );
    expect(other.controls).toBeNull();
    expect(other.actionNeeded).toBe(0);
    expect(other.canUpdate).toBe(false);
    // Team permissions carry over.
    expect(other.canConsole).toBe(true);
});
