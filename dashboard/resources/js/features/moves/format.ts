import type { StatusState } from '@/components/status';

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
    stopped: ['idle', 'Stopped'],
};

/** The state and word of an item, also for a status this build doesn't know yet. */
export function itemState(item: {
    status: App.Enums.Moves.MoveItemStatus;
}): [StatusState, string] {
    return itemStates[item.status] ?? ['idle', item.status];
}

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
