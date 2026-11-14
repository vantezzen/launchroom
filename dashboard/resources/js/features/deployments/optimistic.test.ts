import { expect, test } from 'vitest';
import { optimisticQueued, optimisticService } from './optimistic';

const nodes = [
    { id: 'svc_web', status: { state: 'active', label: 'Active' } },
    { id: 'svc_db', status: { state: 'active', label: 'Active' } },
];

test('changes the service node and its panel actions', () => {
    const panel = {
        serviceId: 'svc_web',
        controls: { stopped: false, deploying: false },
    };

    const next = optimisticService(
        'svc_web',
        { state: 'idle', label: 'Stopped' },
        { stopped: true },
    )({ nodes, panel } as never) as {
        nodes: typeof nodes;
        panel: typeof panel;
    };

    expect(next.nodes[0].status).toMatchObject({
        state: 'idle',
        label: 'Stopped',
    });
    expect(next.nodes[1]).toBe(nodes[1]);
    expect(next.panel.controls).toEqual({ stopped: true, deploying: false });
});

test('leaves pages without a canvas alone', () => {
    expect(optimisticQueued('svc_web')({ detail: {} } as never)).toEqual({});
});
