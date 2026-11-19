import { describe, expect, it } from 'vitest';
import { updateSteps } from './update-steps';

const update: App.Data.Updates.ControlPlaneUpdateData = {
    id: 'cpu_01JB7Z6X9Q3T8M5N2P4R6S8V0W',
    fromVersion: '0.1.0',
    toVersion: '0.2.0',
    status: 'rolled_back',
    skipBackup: true,
    updateServers: false,
    automatic: false,
    backupStatus: null,
    backupFinishedAt: null,
    existingBackup: false,
    fleetUpdateId: null,
    progress: null,
    error: 'the dashboard did not answer',
    createdAt: null,
    startedAt: null,
    finishedAt: null,
    lastActivityAt: null,
    stalled: false,
    backupBytes: null,
    backupAttempts: 0,
    canCancel: false,
};

describe('updateSteps', () => {
    it('shows the rollback reason the server sent on the install step', () => {
        const steps = updateSteps(update, null, 0);

        expect(steps[1]).toMatchObject({
            id: 'install',
            status: 'failed',
            summary: 'the dashboard did not answer',
        });
        expect(steps[2]).toMatchObject({
            name: 'Go back to 0.1.0',
            status: 'succeeded',
        });
    });
});

describe('updateSteps with an existing backup', () => {
    const now = Date.parse('2026-11-18T12:00:00Z');
    const base = {
        ...update,
        status: 'updating' as const,
        skipBackup: false,
        existingBackup: true,
        error: null,
    };

    it('names the backup it uses', () => {
        const steps = updateSteps(
            {
                ...base,
                backupStatus: 'succeeded',
                backupFinishedAt: '2026-11-18T11:48:00Z',
            },
            null,
            0,
            now,
        );

        expect(steps[0]).toMatchObject({
            id: 'backup',
            status: 'succeeded',
            summary: 'Uses the backup from 12 minutes ago',
        });
    });

    it('says it waits for a backup that is still running', () => {
        const steps = updateSteps(
            { ...base, status: 'backing_up', backupStatus: 'running' },
            null,
            0,
            now,
        );

        expect(steps[0]).toMatchObject({
            status: 'running',
            summary: 'Waiting for the running backup to finish',
        });
    });
});

describe('updateSteps that never wait silently', () => {
    const now = Date.parse('2026-11-18T12:00:00Z');
    const running = {
        ...update,
        status: 'backing_up' as const,
        skipBackup: false,
        backupStatus: 'running' as const,
        error: null,
        canCancel: true,
    };

    it('shows how much of the backup is stored', () => {
        const steps = updateSteps(
            { ...running, backupBytes: 52_428_800 },
            null,
            0,
            now,
        );

        expect(steps[0]).toMatchObject({ status: 'running' });
        expect(steps[0].summary).toContain('stored');
    });

    it('says when the backup made no progress', () => {
        const steps = updateSteps(
            {
                ...running,
                stalled: true,
                lastActivityAt: '2026-11-18T11:52:00Z',
            },
            null,
            0,
            now,
        );

        expect(steps[0]).toMatchObject({
            status: 'running',
            summary: 'No progress since 8 minutes ago',
        });
    });

    it('marks the remaining steps skipped, not failed, after a cancel', () => {
        const steps = updateSteps(
            {
                ...running,
                status: 'cancelled',
                error: 'Cancelled before anything changed.',
            },
            null,
            0,
            now,
        );

        expect(steps.map((step) => step.status)).toEqual([
            'skipped',
            'skipped',
            'skipped',
        ]);
    });

    it('keeps a finished backup when the update was cancelled after it', () => {
        const steps = updateSteps(
            { ...running, status: 'cancelled', backupStatus: 'succeeded' },
            null,
            0,
            now,
        );

        expect(steps.map((step) => step.status)).toEqual([
            'succeeded',
            'skipped',
            'skipped',
        ]);
    });
});
