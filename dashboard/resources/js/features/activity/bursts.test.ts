import { expect, test } from 'vite-plus/test';
import { groupActivity } from './bursts';

type Entry = App.Data.Activity.ActivityEntryData;

const entry = (id: string, at: string, extra: Partial<Entry> = {}): Entry => ({
    id,
    kind: 'redeploy',
    at: `2026-11-16T${at}:00Z`,
    actor: 'Mara',
    title: 'redeployed site in production',
    detail: null,
    environmentId: 'env_1',
    environmentName: 'production',
    href: null,
    projectId: 'prj_1',
    projectName: 'shop',
    status: 'failed',
    sha: null,
    severity: null,
    ...extra,
});

test('identical events close together collapse into one row with a count', () => {
    const clusters = groupActivity([
        entry('a', '12:09'),
        entry('b', '12:05'),
        entry('c', '11:00'),
    ]);

    expect(clusters.map((c) => c.bursts.map((b) => b.entry.id))).toEqual([
        ['a'],
        ['c'],
    ]);
    expect(clusters[0].bursts[0].earlier.map((e) => e.id)).toEqual(['b']);
});

test('different events by one person in one project share a heading', () => {
    const clusters = groupActivity([
        entry('deploy', '12:09', { kind: 'deploy', title: 'deployed web' }),
        entry('service', '12:06', { kind: 'service', title: 'added web' }),
        entry('project', '12:05', { kind: 'project', title: 'created shop' }),
        entry('other', '12:04', { actor: 'Jo', kind: 'project' }),
    ]);

    expect(clusters.map((c) => c.bursts.length)).toEqual([3, 1]);
    expect(clusters[0].actor).toBe('Mara');
});

test('alerts never group by person', () => {
    const clusters = groupActivity([
        entry('a1', '12:09', {
            kind: 'alert',
            actor: null,
            title: 'web is down',
        }),
        entry('a2', '12:08', {
            kind: 'alert',
            actor: null,
            title: 'api is down',
        }),
    ]);

    expect(clusters).toHaveLength(2);
});
