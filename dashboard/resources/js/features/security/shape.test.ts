import { describe, expect, test } from 'vite-plus/test';
import { groupActionNeeded, verdictOf } from './shape';

type Finding = App.Data.Security.FindingCardData;

const finding = (over: Partial<Finding> = {}): Finding => ({
    id: 'fnd_1',
    href: '/security/findings/fnd_1',
    advisory: 'GHSA-aaaa',
    title: 'Command injection in lodash template',
    severity: 'high',
    cvss: 7.2,
    knownExploited: false,
    knownExploitedSince: null,
    public: true,
    ecosystem: 'npm',
    package: 'lodash',
    version: '4.17.20',
    fixedVersion: '4.17.21',
    level: 'action_needed',
    reasons: [],
    status: 'open',
    firstSeenAt: '2026-10-25T10:00:00Z',
    service: {
        id: 'svc_1',
        name: 'web',
        logo: null,
        kind: 'web',
        projectId: 'prj_1',
        projectName: 'shop',
        environmentId: 'env_1',
        environmentName: 'production',
        href: '/p/prj_1/env_1/s/svc_1',
    },
    investigation: null,
    ...over,
});

describe('groupActionNeeded', () => {
    test('one group per advisory with every affected service', () => {
        const groups = groupActionNeeded([
            finding({ id: 'a' }),
            finding({ id: 'b', version: '4.17.19' }),
            finding({ id: 'c', advisory: 'GHSA-bbbb', title: null }),
        ]);

        expect(groups.map((g) => g.advisory)).toEqual([
            'GHSA-aaaa',
            'GHSA-bbbb',
        ]);
        expect(groups[0].findings.map((f) => f.id)).toEqual(['a', 'b']);
        expect(groups[0].versions).toEqual(['4.17.20', '4.17.19']);
        expect(groups[1].title).toBe('GHSA-bbbb');
    });

    test('known exploited first, then severity, then reach', () => {
        const groups = groupActionNeeded([
            finding({ id: 'a', advisory: 'A', severity: 'high' }),
            finding({ id: 'b', advisory: 'B', severity: 'critical' }),
            finding({ id: 'c', advisory: 'C', severity: 'high' }),
            finding({ id: 'd', advisory: 'C', severity: 'high' }),
            finding({
                id: 'e',
                advisory: 'D',
                severity: 'high',
                knownExploited: true,
                knownExploitedSince: '2026-10-03',
            }),
        ]);

        expect(groups.map((g) => g.advisory)).toEqual(['D', 'B', 'C', 'A']);
    });

    test('takes the worst severity and the highest score of a group', () => {
        const [group] = groupActionNeeded([
            finding({ id: 'a', severity: 'high', cvss: 7.2 }),
            finding({ id: 'b', severity: 'critical', cvss: 9.1 }),
        ]);

        expect(group.severity).toBe('critical');
        expect(group.cvss).toBe(9.1);
    });
});

describe('verdictOf', () => {
    const done = (summary: string[]) => ({
        id: 'inv_1',
        status: 'done',
        startedAt: '2026-10-25T10:00:00Z',
        finishedAt: '2026-10-25T10:01:00Z',
        summary,
    });

    test('no investigation and a running one', () => {
        expect(verdictOf(null).title).toBe('Not investigated yet');
        expect(
            verdictOf({ ...done([]), status: 'running', finishedAt: null })
                .state,
        ).toBe('progress');
    });

    test('reads the container sentence and keeps the rest as detail', () => {
        const verdict = verdictOf(
            done([
                'Checked the container: 2 concerning findings.',
                '37 requests matched attack rules in the last 7 days.',
            ]),
        );

        expect(verdict).toMatchObject({
            state: 'failed',
            title: '2 concerning findings in the container',
            detail: '37 requests matched attack rules in the last 7 days.',
            at: '2026-10-25T10:01:00Z',
        });
        expect(
            verdictOf(done(['Checked the container: 1 concerning finding.']))
                .title,
        ).toBe('1 concerning finding in the container');
    });

    test('calm, notable and unchecked containers', () => {
        expect(
            verdictOf(done(['Checked the container: nothing unusual.'])),
        ).toMatchObject({ state: 'active', title: 'Nothing unusual found' });
        expect(
            verdictOf(done(['Checked the container: 1 thing worth a look.'])),
        ).toMatchObject({ state: 'warning', title: '1 thing worth a look' });
        expect(
            verdictOf(done(['The container couldn’t be checked.'])).state,
        ).toBe('warning');
    });
});
