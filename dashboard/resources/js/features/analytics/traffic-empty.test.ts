import { describe, expect, it } from 'vitest';
import { trafficEmptyCopy } from './traffic-empty';

type Readiness = App.Data.Analytics.TrafficReadinessData;
type Blocker = App.Data.Analytics.TrafficBlockerData;

function blocker(
    name: string,
    remedy: App.Enums.ServiceRemedy | null = 'start',
): Blocker {
    return {
        serviceId: `svc_${name}`,
        name,
        logo: 'node',
        projectId: 'prj_shop',
        projectName: 'shop',
        environmentId: 'env_prod',
        environmentName: 'production',
        status: {
            state: 'idle',
            reason: 'stopped',
            label: 'Stopped',
            since: null,
            deploymentId: 'dpl_1',
            detail: null,
            cause: null,
            remedy,
        },
    };
}

function readiness(over: Partial<Readiness>): Readiness {
    return {
        reason: 'waiting',
        address: null,
        publicServices: [],
        notServing: [],
        moreNotServing: 0,
        ...over,
    };
}

const base = { service: null, where: 'production', range: '24h' };

describe('trafficEmptyCopy', () => {
    it('names the one fix when one service blocks and the user may fix it', () => {
        const copy = trafficEmptyCopy({
            ...base,
            canFix: true,
            readiness: readiness({
                reason: 'not_serving',
                publicServices: ['svc_web'],
                notServing: [blocker('web')],
            }),
        });

        expect(copy.title).toBe('Nothing is running in production');
        expect(copy.line).toBe('Start web to see traffic.');
        expect(copy.blockers).toHaveLength(1);
    });

    it('never offers a fix to someone who may not deploy', () => {
        const copy = trafficEmptyCopy({
            ...base,
            canFix: false,
            readiness: readiness({
                reason: 'not_serving',
                notServing: [blocker('web', 'restart')],
            }),
        });

        expect(copy.line).toBe(
            'Requests show up here once a service with an address serves again.',
        );
    });

    it('says the same for several services without picking one', () => {
        const copy = trafficEmptyCopy({
            ...base,
            canFix: true,
            readiness: readiness({
                reason: 'not_serving',
                notServing: [blocker('api'), blocker('web')],
            }),
        });

        expect(copy.line).not.toContain('Start');
        expect(copy.blockers).toHaveLength(2);
    });

    it('explains a missing address', () => {
        const copy = trafficEmptyCopy({
            ...base,
            canFix: true,
            readiness: readiness({ reason: 'no_address' }),
        });

        expect(copy.title).toBe('No service in production has an address yet');
    });

    it('points at the address while waiting for the first request', () => {
        const copy = trafficEmptyCopy({
            ...base,
            range: '7d',
            canFix: true,
            readiness: readiness({ address: 'web.example.com' }),
        });

        expect(copy.title).toBe('No requests in this range yet');
        expect(copy.line).toContain('web.example.com');
        expect(copy.address).toBe('web.example.com');
    });

    it('words a selected service by its own state', () => {
        const r = readiness({
            reason: 'waiting',
            address: 'api.example.com',
            publicServices: ['svc_api', 'svc_web'],
            notServing: [blocker('web', 'retry_deploy')],
        });

        expect(
            trafficEmptyCopy({
                ...base,
                canFix: true,
                readiness: r,
                service: { id: 'svc_web', name: 'web' },
            }),
        ).toMatchObject({
            title: 'web isn’t serving in production',
            line: 'Retry the deploy of web to see traffic.',
        });
        expect(
            trafficEmptyCopy({
                ...base,
                canFix: true,
                readiness: r,
                service: { id: 'svc_worker', name: 'worker' },
            }).title,
        ).toBe('worker has no address');
        expect(
            trafficEmptyCopy({
                ...base,
                canFix: true,
                readiness: r,
                service: { id: 'svc_api', name: 'api' },
            }).address,
        ).toBeNull();
    });
});
