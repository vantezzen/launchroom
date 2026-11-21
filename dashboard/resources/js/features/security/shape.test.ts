import { describe, expect, test } from 'vite-plus/test';
import { fixTitle, splitByProduction, verdictOf, worstVerdict } from './shape';

type FixGroup = App.Data.Security.FixGroupData;
type FixService = App.Data.Security.FixGroupServiceData;
type Investigation = App.Data.Security.FindingInvestigationData;

const done = (summary: string[]): Investigation => ({
    id: 'ivg_1',
    status: 'completed',
    startedAt: '2026-10-25T10:00:00Z',
    finishedAt: '2026-10-25T10:01:00Z',
    summary,
});

const service = (over: Partial<FixService> = {}): FixService => ({
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
    production: true,
    version: '4.17.20',
    fixedVersion: '4.17.21',
    severity: 'high',
    knownExploited: false,
    findings: ['fnd_1'],
    lead: 'fnd_1',
    investigation: null,
    ...over,
});

const group = (over: Partial<FixGroup> = {}): FixGroup => ({
    key: 'fix_0123456789abcdef',
    ecosystem: 'npm',
    package: 'lodash',
    versions: ['4.17.20'],
    fixedVersion: '4.17.21',
    severity: 'high',
    cvss: 7.2,
    knownExploited: false,
    knownExploitedSince: null,
    impact: 'Attackers could run code on the server.',
    headline: 'Command injection in lodash',
    production: true,
    services: [service()],
    advisories: [],
    lead: 'fnd_1',
    ...over,
});

describe('fixTitle', () => {
    test('says the update, or that there is none yet', () => {
        expect(fixTitle(group())).toBe('Update lodash to 4.17.21');
        expect(fixTitle(group({ fixedVersion: null }))).toBe(
            'No fixed version of lodash yet',
        );
    });
});

describe('splitByProduction', () => {
    test('keeps the server order inside each part', () => {
        const groups = [
            group({ key: 'a' }),
            group({ key: 'b', production: false }),
            group({ key: 'c' }),
        ];
        const { production, other } = splitByProduction(groups);

        expect(production.map((g) => g.key)).toEqual(['a', 'c']);
        expect(other.map((g) => g.key)).toEqual(['b']);
    });
});

describe('worstVerdict', () => {
    test('names the service with concerning findings first', () => {
        const verdict = worstVerdict([
            service({
                investigation: done([
                    'Checked the container: 1 thing worth a look.',
                ]),
            }),
            service({
                service: { ...service().service, name: 'api' },
                investigation: done([
                    'Checked the container: 2 concerning findings.',
                ]),
            }),
        ]);

        expect(verdict).toEqual({
            state: 'failed',
            text: '2 concerning findings in api',
            more: 0,
        });
    });

    test('stays quiet when checks were clean, failed to run or never ran', () => {
        expect(
            worstVerdict([
                service(),
                service({
                    investigation: done(['The container couldn’t be checked.']),
                }),
                service({
                    investigation: done([
                        'Checked the container: nothing unusual.',
                    ]),
                }),
            ]),
        ).toBeNull();
    });
});

describe('verdictOf', () => {
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
