import type { Step } from '@/components/steps/step-list';
import type { StepStatus } from '@/components/steps/step-icon';
import { formatBytes, relativeTime } from '@/lib/format';
import { vocabulary } from '@/lib/vocabulary';

type Update = App.Data.Updates.ControlPlaneUpdateData;
type Fleet = App.Data.Updates.FleetUpdateData;

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

/** What the backup step says while it waits for, or uses, an existing backup. */
function existingBackupSummary(
    update: Update,
    now: number,
): string | undefined {
    if (!update.existingBackup) {
        return undefined;
    }

    if (
        update.backupStatus === 'pending' ||
        update.backupStatus === 'running'
    ) {
        return 'Waiting for the running backup to finish';
    }

    return update.backupStatus === 'succeeded' &&
        update.backupFinishedAt !== null
        ? `Uses the backup from ${relativeTime(update.backupFinishedAt, now)}`
        : undefined;
}

/** Where a control-plane update stopped: 1 backup, 2 install, 3 check. */
function failedAt(update: Update): number {
    return update.backupStatus === 'failed' ? 1 : 2;
}

/** Where a cancelled update stopped: before the install, or before the backup was done. */
function cancelledAt(update: Update): number {
    return update.skipBackup || update.backupStatus === 'succeeded' ? 2 : 1;
}

/**
 * The backup step while it runs (docs/11 §4.3): how much is stored, that
 * it was started again after its worker stopped, or that it shows no sign
 * of progress (then the page offers "Cancel update").
 */
function backupProgress(update: Update, now: number): string | undefined {
    if (update.status !== 'backing_up') {
        return undefined;
    }

    if (update.stalled && update.lastActivityAt !== null) {
        return `No progress since ${relativeTime(update.lastActivityAt, now)}`;
    }

    const stored =
        update.backupBytes !== null && update.backupBytes > 0
            ? `${formatBytes(update.backupBytes)} stored`
            : undefined;

    if (update.backupAttempts > 0) {
        return stored === undefined
            ? 'Started again after the previous attempt stopped'
            : `Started again, ${stored}`;
    }

    return stored;
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
    now: number = Date.now(),
): Step[] {
    const failed =
        vocabulary.Updates.ControlPlaneUpdateStatus[update.status].isFailed;
    const cancelled = update.status === 'cancelled';
    const stop = failed
        ? failedAt(update)
        : cancelled
          ? cancelledAt(update)
          : null;
    const pick = (order: number): StepStatus => {
        if (stop !== null) {
            return order < stop
                ? 'succeeded'
                : order === stop && failed
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
                : update.backupStatus === 'failed' && failed
                  ? (update.error ?? undefined)
                  : (backupProgress(update, now) ??
                    existingBackupSummary(update, now)),
        },
        {
            id: 'install',
            name: `Install ${update.toVersion} and restart`,
            status: pick(2),
            summary:
                update.status === 'updating'
                    ? (update.progress ??
                      (update.stalled
                          ? 'Waiting for the main server to start the update. It may be offline.'
                          : 'The dashboard is unavailable for a few minutes'))
                    : stop === 2 && failed
                      ? (update.error ?? undefined)
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
            status: failed || cancelled ? 'skipped' : 'pending',
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
