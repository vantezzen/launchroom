import { expect, test } from 'vitest';
import { listHead, needsRetry } from './list-head';

type Summary = App.Data.Deployments.DeploymentSummaryData;

function summary(overrides: Partial<Summary> = {}): Summary {
    return {
        id: 'dpl_1',
        status: 'active',
        statusLabel: 'Live',
        trigger: 'push',
        projectId: 'prj_1',
        serviceId: 'svc_1',
        serviceName: 'web',
        serviceSlug: 'web',
        environmentId: 'env_1',
        environmentName: 'production',
        gitRef: 'refs/heads/main',
        commitSha: null,
        commitMessage: null,
        commitAuthor: null,
        actorName: null,
        failureClass: null,
        failureSummary: null,
        isActive: false,
        urls: [],
        queuedAt: '2026-10-25T10:00:00Z',
        startedAt: '2026-10-25T10:00:00Z',
        finishedAt: '2026-10-25T10:01:00Z',
        durationSeconds: 60,
        rollbackOfId: null,
        canRollback: false,
        commitAuthorAvatarUrl: null,
        stopped: null,
        ...overrides,
    };
}

test('a failed first deploy is the head, not an empty list', () => {
    const failed = summary({ id: 'dpl_f', status: 'failed' });

    const head = listHead({
        active: null,
        deployments: [failed],
        current: { id: 'dpl_f' },
    });

    expect(head?.id).toBe('dpl_f');
    expect(needsRetry(failed, null)).toBe(true);
});

test('without current, the newest deployment still heads the list', () => {
    const cancelled = summary({ id: 'dpl_c', status: 'cancelled' });
    const older = summary({ id: 'dpl_o', status: 'failed' });

    expect(
        listHead({
            active: null,
            deployments: [cancelled, older],
            current: null,
        })?.id,
    ).toBe('dpl_c');
});

test('the deployment in progress wins over the live one', () => {
    const live = summary({ id: 'dpl_live', status: 'active', isActive: true });
    const building = summary({ id: 'dpl_new', status: 'building' });

    expect(
        listHead({
            active: live,
            deployments: [building, live],
            current: null,
        })?.id,
    ).toBe('dpl_new');
});

test('a failure after a live deployment keeps the live one first, without retry', () => {
    const live = summary({ id: 'dpl_live', status: 'active', isActive: true });
    const failed = summary({ id: 'dpl_f', status: 'failed' });

    const head = listHead({
        active: live,
        deployments: [failed, live],
        current: { id: 'dpl_live' },
    });

    expect(head?.id).toBe('dpl_live');
    expect(needsRetry(failed, live)).toBe(false);
});

test('a live deployment beyond the loaded history still heads the list', () => {
    const live = summary({ id: 'dpl_live', status: 'active', isActive: true });

    expect(
        listHead({ active: live, deployments: [], current: { id: 'dpl_live' } })
            ?.id,
    ).toBe('dpl_live');
});

test('no deployments: no head', () => {
    expect(
        listHead({ active: null, deployments: [], current: null }),
    ).toBeNull();
});
