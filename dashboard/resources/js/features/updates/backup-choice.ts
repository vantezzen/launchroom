import { formatBytes, relativeTime } from '@/lib/format';

type Backup = App.Data.Updates.PreUpgradeBackupData;

export type BackupChoiceValue = 'latest' | 'running' | 'new' | 'skip';

export type BackupChoice = {
    value: BackupChoiceValue;
    title: string;
    description: string;
};

/** Older than this, "Take a new backup first" is preselected. */
const FRESH_MS = 24 * 60 * 60 * 1000;

/**
 * Which backup comes before an update (docs/11 §4.1), when there is a
 * control-plane backup destination: the latest backup (any age, the owner
 * judges it), waiting for the backup that runs now (it replaces "new":
 * a new one would wait for it anyway), a new backup, or, when launchroom
 * was never backed up, updating without one. Every choice stays
 * selectable; the latest backup is preselected while it is less than a
 * day old.
 */
export function backupChoices(
    latest: Backup | null,
    running: Backup | null,
    destinationName: string | null,
    now: number = Date.now(),
): { choices: BackupChoice[]; preselected: BackupChoiceValue } {
    const choices: BackupChoice[] = [];

    if (latest !== null && latest.finishedAt !== null) {
        const size =
            latest.sizeBytes === null
                ? ''
                : `, ${formatBytes(latest.sizeBytes)}`;

        choices.push({
            value: 'latest',
            title: 'Use the latest backup',
            description: `Finished ${relativeTime(latest.finishedAt, now)}${size}.`,
        });
    }

    if (running !== null) {
        choices.push({
            value: 'running',
            title: 'Wait for the running backup',
            description:
                running.startedAt === null
                    ? 'The update starts when it has finished and uses it.'
                    : `Started ${relativeTime(running.startedAt, now)}. The update starts when it has finished and uses it.`,
        });
    } else {
        choices.push({
            value: 'new',
            title: 'Take a new backup first',
            description: `${destinationName === null ? 'To your backup destination' : `To ${destinationName}`}. If the backup fails, nothing changes.`,
        });
    }

    if (latest === null) {
        choices.push({
            value: 'skip',
            title: 'Update without a new backup',
            description:
                'There is no backup of launchroom yet. Only the local database copy is kept for the rollback.',
        });
    }

    const fresh =
        latest?.finishedAt != null &&
        now - new Date(latest.finishedAt).getTime() < FRESH_MS;

    return {
        choices,
        preselected: fresh ? 'latest' : running !== null ? 'running' : 'new',
    };
}

/** The form fields a choice sends to "Update". */
export function backupFields(
    choice: BackupChoiceValue,
    latest: Backup | null,
    running: Backup | null,
): { backupRunId?: string; skipBackup?: '1' } {
    switch (choice) {
        case 'latest':
            return latest === null ? {} : { backupRunId: latest.id };
        case 'running':
            return running === null ? {} : { backupRunId: running.id };
        case 'skip':
            return { skipBackup: '1' };
        default:
            return {};
    }
}
