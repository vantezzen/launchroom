import { describe, expect, it } from 'vitest';
import { backupChoices, backupFields } from './backup-choice';

const now = Date.parse('2026-11-18T12:00:00Z');

function backup(
    overrides: Partial<App.Data.Updates.PreUpgradeBackupData> = {},
): App.Data.Updates.PreUpgradeBackupData {
    return {
        id: 'bkr_01JB7Z6X9Q3T8M5N2P4R6S8V0W',
        status: 'succeeded',
        startedAt: '2026-11-18T11:46:00Z',
        finishedAt: '2026-11-18T11:48:00Z',
        sizeBytes: 1288490189,
        ...overrides,
    };
}

describe('backupChoices', () => {
    it('offers a recent backup with its age and size, preselected', () => {
        const { choices, preselected } = backupChoices(
            backup(),
            null,
            'Hetzner',
            now,
        );

        expect(choices.map((choice) => choice.value)).toEqual([
            'latest',
            'new',
        ]);
        expect(choices[0].description).toContain('12 minutes ago, 1.2 GB');
        expect(preselected).toBe('latest');
    });

    it('preselects a new backup when the latest is older than a day, but keeps both', () => {
        const { choices, preselected } = backupChoices(
            backup({ finishedAt: '2026-11-15T12:00:00Z' }),
            null,
            null,
            now,
        );

        expect(choices.map((choice) => choice.value)).toEqual([
            'latest',
            'new',
        ]);
        expect(choices[0].description).toContain('3 days ago');
        expect(preselected).toBe('new');
    });

    it('offers waiting for a running backup instead of a new one', () => {
        const running = backup({
            id: 'bkr_01JB7Z6X9Q3T8M5N2P4R6S8V0X',
            status: 'running',
            finishedAt: null,
            sizeBytes: null,
        });
        const { choices, preselected } = backupChoices(
            backup({ finishedAt: '2026-11-10T12:00:00Z' }),
            running,
            null,
            now,
        );

        expect(choices.map((choice) => choice.value)).toEqual([
            'latest',
            'running',
        ]);
        expect(preselected).toBe('running');
        expect(backupFields('running', null, running)).toEqual({
            backupRunId: running.id,
        });
    });

    it('offers updating without a backup when launchroom was never backed up', () => {
        const { choices, preselected } = backupChoices(null, null, null, now);

        expect(choices.map((choice) => choice.value)).toEqual(['new', 'skip']);
        expect(preselected).toBe('new');
        expect(backupFields('skip', null, null)).toEqual({ skipBackup: '1' });
        expect(backupFields('new', null, null)).toEqual({});
    });
});
