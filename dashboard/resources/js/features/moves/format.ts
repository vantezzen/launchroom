import type { StatusState } from '@/components/status';

/** "about 2 min" for an estimate in seconds. */
export function formatDuration(seconds: number | null): string {
    if (seconds === null) {
        return 'unknown';
    }

    if (seconds < 60) {
        return 'under a minute';
    }

    const minutes = Math.ceil(seconds / 60);

    if (minutes < 60) {
        return `about ${minutes} min`;
    }

    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    return rest === 0 ? `about ${hours} h` : `about ${hours} h ${rest} min`;
}

/** Status word and state of one service of a move. */
export const itemStates: Record<
    App.Enums.Moves.MoveItemStatus,
    [StatusState, string]
> = {
    pending: ['idle', 'Waiting'],
    building: ['progress', 'Building'],
    backing_up: ['progress', 'Backing up'],
    stopping: ['progress', 'Stopping'],
    exporting: ['progress', 'Copying'],
    preparing: ['progress', 'Preparing'],
    importing: ['progress', 'Loading'],
    starting: ['progress', 'Starting'],
    done: ['active', 'Moved'],
    failed: ['failed', 'Failed'],
    rolling_back: ['progress', 'Going back'],
    rolled_back: ['idle', 'Back on the old server'],
    cancelled: ['idle', 'Stays'],
    skipped: ['idle', 'Skipped'],
};

export const moveStates: Record<
    App.Enums.Moves.ServerMoveStatus,
    [StatusState, string]
> = {
    running: ['progress', 'Moving'],
    ready: ['active', 'Everything moved'],
    cancelling: ['progress', 'Cancelling'],
    completed: ['active', 'Done'],
    cancelled: ['idle', 'Cancelled'],
};

export const kindLabels: Record<App.Enums.Moves.MoveItemKind, string> = {
    stateless: 'No downtime',
    dump: 'Database',
    files: 'Files',
};
