import { expect, test } from 'vitest';
import { effectSummary, stagedCountLabel } from './use-staged-changes';
import type { StagedChanges } from './use-staged-changes';

const group = (name: string, effect: App.Enums.ChangeEffect) =>
    ({
        serviceId: `svc_${name}`,
        name,
        slug: name,
        effect,
        items: [],
    }) as unknown as StagedChanges['groups'][number];

const changes = (...groups: StagedChanges['groups']) =>
    ({ groups }) as StagedChanges;

test('names the services applying redeploys, most disruptive first', () => {
    expect(
        effectSummary(
            changes(
                group('api', 'restart'),
                group('web', 'rebuild'),
                group('worker', 'restart'),
                group('db', 'none'),
            ),
        ),
    ).toBe('Applying rebuilds web, and restarts api and worker.');
    expect(effectSummary(changes(group('cache', 'new')))).toBe(
        'Applying starts cache.',
    );
});

test('says nothing when no service restarts', () => {
    expect(effectSummary(changes(group('db', 'none')))).toBe('');
});

test('the bar counts changes and names the services when there are several', () => {
    const counted = (count: number, ...groups: StagedChanges['groups']) =>
        ({ count, groups }) as StagedChanges;

    expect(stagedCountLabel(counted(1, group('web', 'restart')))).toBe(
        '1 change',
    );
    expect(stagedCountLabel(counted(2, group('web', 'restart')))).toBe(
        '2 changes',
    );
    expect(
        stagedCountLabel(
            counted(4, group('web', 'restart'), group('api', 'rebuild')),
        ),
    ).toBe('4 changes in 2 services');
});
