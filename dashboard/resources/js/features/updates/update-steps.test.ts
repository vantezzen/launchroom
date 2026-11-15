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
    fleetUpdateId: null,
    progress: null,
    error: 'the dashboard did not answer',
    createdAt: null,
    startedAt: null,
    finishedAt: null,
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
