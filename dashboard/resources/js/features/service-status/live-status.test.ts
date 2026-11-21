import { describe, expect, it } from 'vitest';
import type { LiveStatus, ServiceStatus } from './live-status';
import {
    contradictsLive,
    newer,
    notServing,
    notServingSentence,
    optimisticStatus,
    problems,
    statusDetail,
    statusOf,
    withLiveStatuses,
    withStatus,
} from './live-status';

const NOW = Date.parse('2026-11-20T12:00:00Z');

const status = (overrides: Partial<ServiceStatus> = {}): ServiceStatus => ({
    state: 'active',
    reason: 'live',
    label: 'Live',
    since: '2026-11-18T12:00:00Z',
    deploymentId: 'dpl_live',
    detail: null,
    cause: null,
    remedy: null,
    ...overrides,
});

const notRunning = status({
    state: 'warning',
    reason: 'not_running',
    label: 'Not running',
    since: '2026-11-20T10:00:00Z',
    cause: 'exited',
    remedy: 'start_containers',
});

const crashed = status({
    state: 'failed',
    reason: 'crashed',
    label: 'Crashed',
    since: '2026-11-20T11:55:00Z',
    cause: 'ran out of memory',
    remedy: 'restart',
});

const problem = (serviceId: string): App.Data.Canvas.ServiceProblemData => ({
    serviceId,
    serviceName: serviceId,
    status: notRunning,
    sentence: `${serviceId} is not running`,
    href: `/p/prj/env/s/${serviceId}/logs`,
    link: 'View logs',
});

function snapshot(
    version: number,
    services: [string, ServiceStatus][],
): LiveStatus {
    return {
        environmentId: 'env_prod',
        version,
        health: {
            status: status({ reason: 'live' }),
            deploying: false,
            deployFailed: false,
        },
        services: services.map(([serviceId, entry]) => ({
            serviceId,
            name: serviceId,
            logo: 'nextjs',
            status: entry,
            problem:
                entry.state === 'failed' || entry.state === 'warning'
                    ? problem(serviceId)
                    : null,
        })),
    };
}

describe('newer', () => {
    it('keeps the newer snapshot, however late the older one arrives', () => {
        const old = snapshot(100, [['web', status()]]);
        const fresh = snapshot(200, [['web', notRunning]]);

        expect(newer(undefined, old)).toBe(old);
        expect(newer(old, fresh)).toBe(fresh);
        expect(newer(fresh, old)).toBe(fresh);
    });

    it('takes a snapshot of the same version (a refetch of the same state)', () => {
        const a = snapshot(100, [['web', status()]]);
        const b = snapshot(100, [['web', status()]]);

        expect(newer(a, b)).toBe(b);
    });

    it('never compares snapshots of different environments', () => {
        const other = { ...snapshot(50, []), environmentId: 'env_staging' };

        expect(newer(snapshot(100, []), other)).toBe(other);
    });
});

describe('withLiveStatuses', () => {
    const nodes = [
        { id: 'web', status: status() },
        { id: 'api', status: status() },
    ];

    it('gives every node the store’s status, and keeps nodes it doesn’t know', () => {
        const next = withLiveStatuses(
            nodes,
            snapshot(1, [['web', notRunning]]),
        );

        expect(next[0].status).toBe(notRunning);
        expect(next[1]).toBe(nodes[1]);
    });

    it('returns the same array before the store has a snapshot or when nothing differs', () => {
        expect(withLiveStatuses(nodes, undefined)).toBe(nodes);

        const same = snapshot(1, []);
        same.services = [
            {
                serviceId: 'web',
                name: 'web',
                logo: 'nextjs',
                status: nodes[0].status,
                problem: null,
            },
        ];
        expect(withLiveStatuses(nodes, same)).toBe(nodes);
    });
});

describe('optimistic statuses', () => {
    it('words a reason like the server does, keeping the live deployment', () => {
        const restarting = optimisticStatus(
            'deploying',
            'Restarting',
            new Date(NOW),
        );
        const next = withStatus(
            snapshot(5, [['web', notRunning]]),
            'web',
            restarting,
        );

        expect(statusOf(next, 'web')).toMatchObject({
            reason: 'deploying',
            state: 'progress',
            label: 'Restarting',
            deploymentId: 'dpl_live',
            cause: null,
            remedy: null,
        });
        // Its version stays: the next snapshot from the server replaces it.
        expect(next.version).toBe(5);
        // A service on its way back is not a problem anymore.
        expect(problems(next)).toEqual([]);
    });

    it('takes the stopped word from the PHP enum', () => {
        expect(optimisticStatus('stopped')).toMatchObject({
            label: 'Stopped',
            state: 'idle',
        });
    });
});

describe('problems', () => {
    it('lists the services that need the user, failed first, stable otherwise', () => {
        const list = problems(
            snapshot(1, [
                ['web', notRunning],
                ['api', status()],
                ['worker', crashed],
                ['cron', notRunning],
            ]),
        );

        expect(list.map((entry) => entry.serviceId)).toEqual([
            'worker',
            'web',
            'cron',
        ]);
    });
});

describe('statusDetail', () => {
    it('says why and since when for a problem', () => {
        expect(statusDetail(notRunning, NOW)).toBe('exited 2h ago');
        expect(statusDetail(crashed, NOW)).toBe('ran out of memory 5m ago');
    });

    it('is only the age when nothing needs explaining', () => {
        expect(statusDetail(status(), NOW)).toBe('2d');
        expect(statusDetail(status({ since: null }), NOW)).toBeNull();
    });

    it('is the cause alone without a time', () => {
        expect(
            statusDetail(
                status({
                    reason: 'server_unreachable',
                    state: 'warning',
                    label: 'Server unreachable',
                    since: null,
                    cause: 'fra-1 isn’t reporting',
                }),
                NOW,
            ),
        ).toBe('fra-1 isn’t reporting');
    });
});

describe('the live deployment while the service isn’t serving', () => {
    it('never says Live for a service that should run but doesn’t', () => {
        expect(notServing(notRunning)).toBe(notRunning);
        expect(notServing(crashed)).toBe(crashed);
        expect(notServing(status())).toBeNull();
        expect(
            notServing(status({ reason: 'stopped', state: 'idle' })),
        ).toBeNull();
        expect(notServing(null)).toBeNull();
    });

    it('says what the deployment does in one sentence', () => {
        expect(notServingSentence(notRunning, NOW)).toBe(
            'Live deployment, not running: exited 2 hours ago.',
        );
        expect(notServingSentence(crashed, NOW)).toBe(
            'Live deployment, crashed: ran out of memory 5 minutes ago.',
        );
        expect(
            notServingSentence(
                status({
                    reason: 'paused',
                    state: 'warning',
                    label: 'Paused: postgres is being imported',
                    since: null,
                }),
                NOW,
            ),
        ).toBe('Live deployment, paused: postgres is being imported.');
        // A server clock ahead of this one never says "in 20 seconds".
        expect(
            notServingSentence(
                { ...crashed, since: '2026-11-20T12:00:20Z' },
                NOW,
            ),
        ).toBe('Live deployment, crashed: ran out of memory a moment ago.');
    });
});

describe('contradictsLive', () => {
    it('silences "is live" once the store says that deployment crashed or stopped', () => {
        expect(contradictsLive(crashed, 'dpl_live')).toBe(true);
        expect(
            contradictsLive(
                status({ reason: 'stopped', state: 'idle' }),
                'dpl_live',
            ),
        ).toBe(true);
    });

    it('never silences a fresh outcome with a snapshot about an older deployment', () => {
        expect(contradictsLive(crashed, 'dpl_newer')).toBe(false);
        expect(contradictsLive(null, 'dpl_live')).toBe(false);
        expect(contradictsLive(status(), 'dpl_live')).toBe(false);
        expect(
            contradictsLive(
                status({
                    reason: 'starting',
                    state: 'progress',
                    label: 'Starting',
                }),
                'dpl_live',
            ),
        ).toBe(false);
    });
});
