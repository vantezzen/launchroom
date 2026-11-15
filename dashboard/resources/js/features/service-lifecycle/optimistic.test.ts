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

    const next = optimisticService('svc_web', 'stopped', { stopped: true })({
        nodes,
        panel,
    } as never) as {
        nodes: typeof nodes;
        panel: typeof panel;
    };

    expect(next.nodes[0].status).toMatchObject({
        reason: 'stopped',
        state: 'idle',
        label: 'Stopped',
    });
    expect(next.nodes[1]).toBe(nodes[1]);
    expect(next.panel.controls).toEqual({ stopped: true, deploying: false });
});

test('sets the reason with its words, so readers of the reason agree with the label', () => {
    const failed = [
        {
            id: 'svc_web',
            status: {
                state: 'warning',
                reason: 'last_deploy_failed',
                label: 'Last deploy failed',
                detail: null,
            },
        },
    ];

    const next = optimisticService(
        'svc_web',
        'deploying',
        {},
        'Restarting',
    )({
        nodes: failed,
    } as never) as { nodes: typeof failed };

    expect(next.nodes[0].status).toMatchObject({
        reason: 'deploying',
        state: 'progress',
        label: 'Restarting',
    });
    expect(
        optimisticQueued('svc_web')({ nodes: failed } as never),
    ).toMatchObject({
        nodes: [
            { status: { reason: 'queued', state: 'idle', label: 'Queued' } },
        ],
    });
});

test('leaves pages without a canvas alone', () => {
    expect(optimisticQueued('svc_web')({ detail: {} } as never)).toEqual({});
});
