type Entry = App.Data.Activity.ActivityEntryData;

/** Events this close together (newest first) can collapse or group. */
const WINDOW_MS = 10 * 60_000;

/** One row: the newest event and the identical ones right before it. */
export type Burst = {
    entry: Entry;
    /** Older identical events, newest first (empty for a single event). */
    earlier: Entry[];
};

/** Rows by the same person in the same project, close together. */
export type ActivityCluster = {
    key: string;
    actor: string | null;
    projectName: string | null;
    bursts: Burst[];
};

function close(a: Entry, b: Entry): boolean {
    return Math.abs(Date.parse(a.at) - Date.parse(b.at)) <= WINDOW_MS;
}

function same(a: Entry, b: Entry): boolean {
    return (
        a.kind === b.kind &&
        a.actor === b.actor &&
        a.title === b.title &&
        a.projectId === b.projectId &&
        a.environmentId === b.environmentId
    );
}

/** Alerts happen to you; only things people did group by person. */
function groupable(entry: Entry): boolean {
    return entry.kind !== 'alert' && entry.actor !== null;
}

/**
 * Collapses one day's feed (newest first): identical consecutive events
 * within 10 minutes become one row with a count (the newest outcome
 * shows), and consecutive rows by the same person in the same project
 * within 10 minutes share one "mara in shop" heading.
 */
export function groupActivity(entries: Entry[]): ActivityCluster[] {
    const bursts: Burst[] = [];

    for (const entry of entries) {
        const last = bursts.at(-1);
        const previous = last ? (last.earlier.at(-1) ?? last.entry) : null;

        if (
            last &&
            previous &&
            same(last.entry, entry) &&
            close(previous, entry)
        ) {
            last.earlier.push(entry);
        } else {
            bursts.push({ entry, earlier: [] });
        }
    }

    const clusters: ActivityCluster[] = [];

    for (const burst of bursts) {
        const last = clusters.at(-1);
        const previous = last?.bursts.at(-1);
        const tail = previous
            ? (previous.earlier.at(-1) ?? previous.entry)
            : null;

        if (
            last &&
            tail &&
            groupable(burst.entry) &&
            groupable(tail) &&
            burst.entry.actor === last.actor &&
            burst.entry.projectId === tail.projectId &&
            close(tail, burst.entry)
        ) {
            last.bursts.push(burst);
        } else {
            clusters.push({
                key: burst.entry.id,
                actor: burst.entry.actor,
                projectName: burst.entry.projectName ?? null,
                bursts: [burst],
            });
        }
    }

    return clusters;
}
