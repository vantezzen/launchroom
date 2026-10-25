import type { Step } from '@/components/steps/step-list';
import type { StepStatus } from '@/components/steps/step-icon';

type Update = App.Data.Updates.ControlPlaneUpdateData;
type Fleet = App.Data.Updates.FleetUpdateData;

export const terminalStatuses: App.Enums.Updates.ControlPlaneUpdateStatus[] = [
    'succeeded',
    'failed',
    'rolled_back',
];

export function isRunning(update: Update | null): update is Update {
    return update !== null && !terminalStatuses.includes(update.status);
}

const fleetStepStatus: Record<
    App.Enums.Updates.FleetUpdateStepStatus,
    StepStatus
> = {
    pending: 'pending',
    updating: 'running',
    succeeded: 'succeeded',
    failed: 'failed',
    rolled_back: 'failed',
    skipped: 'skipped',
};

/** Where a control-plane update stopped: 1 backup, 2 install, 3 check. */
function failedAt(update: Update): number {
    return update.backupStatus === 'failed' ? 1 : 2;
}

/** The error without the "rolled back to x: " prefix the step already says. */
function reason(update: Update): string | undefined {
    if (update.error === null) {
        return undefined;
    }

    return update.error.replace(/^rolled back to [^:]+:\s*/, '');
}

function phase(update: Update): number {
    switch (update.status) {
        case 'pending':
            return 0;
        case 'backing_up':
            return 1;
        case 'updating':
            return 2;
        default:
            return 4;
    }
}

/**
 * One update as one list (docs/08 §11): back up, install and restart,
 * check, then every server when the owner chose "and my servers". Used
 * while it runs and afterwards ("What happened"). The server rows come
 * from the rollout the update started; before it exists they are one
 * waiting row.
 */
export function updateSteps(
    update: Update,
    fleet: Fleet | null,
    serverCount: number,
): Step[] {
    const failed =
        update.status === 'failed' || update.status === 'rolled_back';
    const stop = failed ? failedAt(update) : null;
    const pick = (order: number): StepStatus => {
        if (stop !== null) {
            return order < stop
                ? 'succeeded'
                : order === stop
                  ? 'failed'
                  : 'skipped';
        }

        const current = phase(update);

        return order < current
            ? 'succeeded'
            : order === current
              ? 'running'
              : 'pending';
    };

    const steps: Step[] = [
        {
            id: 'backup',
            name: update.skipBackup
                ? 'Local snapshot for the rollback'
                : 'Back up launchroom',
            status: pick(1),
            summary: update.skipBackup
                ? 'No off-site backup'
                : update.backupStatus === 'failed'
                  ? (update.error ?? undefined)
                  : undefined,
        },
        {
            id: 'install',
            name: `Install ${update.toVersion} and restart`,
            status: pick(2),
            summary:
                update.status === 'updating'
                    ? (update.progress ??
                      'The dashboard is unavailable for a few minutes')
                    : stop === 2
                      ? reason(update)
                      : undefined,
        },
        update.status === 'rolled_back'
            ? {
                  id: 'check',
                  name: `Go back to ${update.fromVersion}`,
                  status: 'succeeded',
                  summary: 'Files and database as before the update',
              }
            : {
                  id: 'check',
                  name: 'Check that it started',
                  status: pick(3),
              },
    ];

    if (!update.updateServers && update.fleetUpdateId === null) {
        return steps;
    }

    const rollout =
        fleet !== null && fleet.id === update.fleetUpdateId ? fleet : null;

    if (rollout === null) {
        steps.push({
            id: 'servers',
            name:
                serverCount === 1
                    ? 'Update 1 server'
                    : serverCount > 1
                      ? `Update ${serverCount} servers`
                      : 'Update servers',
            status: failed ? 'skipped' : 'pending',
            summary: 'Servers update one at a time, edge servers last',
        });

        return steps;
    }

    for (const step of rollout.steps) {
        steps.push({
            id: `server-${step.position}`,
            name: `Update ${step.serverName}`,
            status: fleetStepStatus[step.status],
            summary:
                step.error ??
                step.progress ??
                (step.status === 'succeeded'
                    ? `Now on ${rollout.toVersion}`
                    : undefined),
        });
    }

    return steps;
}

export function fleetSteps(fleet: Fleet): Step[] {
    return fleet.steps.map((step) => ({
        id: String(step.position),
        name: step.serverName,
        status: fleetStepStatus[step.status],
        summary:
            step.error ??
            step.progress ??
            (step.fromVersion !== null
                ? `from ${step.fromVersion}`
                : undefined),
    }));
}
