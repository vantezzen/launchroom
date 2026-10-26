import { expect, test } from 'vitest';
import {
    deploymentStatusState,
    deploymentWord,
    isServing,
    stoppedLine,
} from './format';

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
        isActive: true,
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

const now = Date.parse('2026-10-25T12:00:00Z');

test('the live deployment of a running service is live', () => {
    const live = summary();

    expect(deploymentWord(live)).toBe('Live');
    expect(deploymentStatusState(live)).toBe('active');
    expect(isServing(live)).toBe(true);
});

test('the live deployment of a stopped service says stopped, quietly', () => {
    const stopped = summary({
        stopped: { at: '2026-10-25T11:55:00Z', by: 'Ada' },
    });

    expect(deploymentWord(stopped)).toBe('Stopped');
    expect(deploymentStatusState(stopped)).toBe('idle');
    expect(isServing(stopped)).toBe(false);
    expect(stoppedLine(stopped.stopped!, now)).toBe(
        'Stopped by Ada 5 minutes ago',
    );
});

test('a stop under a minute ago, or slightly in the future, has no seconds count', () => {
    expect(stoppedLine({ at: '2026-10-25T12:00:12Z', by: 'Ada' }, now)).toBe(
        'Stopped by Ada a moment ago',
    );
    expect(stoppedLine({ at: '2026-10-25T11:59:30Z', by: 'Ada' }, now)).toBe(
        'Stopped by Ada a moment ago',
    );
});

test('a stop by a removed member names no one', () => {
    expect(stoppedLine({ at: '2026-10-25T09:00:00Z', by: null }, now)).toBe(
        'Stopped 3 hours ago',
    );
});

test('older deployments keep their own status', () => {
    const superseded = summary({ status: 'superseded', isActive: false });

    expect(deploymentWord(superseded)).toBe('Superseded');
    expect(deploymentStatusState(superseded)).toBe('idle');
});
