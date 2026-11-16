/**
 * "What's protected" as a summary (Settings → Backups, docs/14 §3): how each
 * schedule stands, the order rows are listed in (problems first) and the
 * one-line count on top.
 */

type Schedule = App.Data.Backups.BackupScheduleData;

export type Standing =
    | 'failing'
    | 'overdue'
    | 'missed'
    | 'waiting'
    | 'paused'
    | 'healthy';

const order: Standing[] = [
    'failing',
    'overdue',
    'missed',
    'waiting',
    'paused',
    'healthy',
];

/** Roughly how many hours lie between two runs of a cron schedule. */
export function intervalHours(cron: string): number {
    const fields = cron.trim().split(/\s+/);

    if (fields.length !== 5) {
        return 24;
    }

    const [, hour, day, , weekday] = fields;
    const every = /^\*\/(\d{1,2})$/.exec(hour);

    if (hour === '*') {
        return 1;
    }

    if (every) {
        return Number(every[1]);
    }

    if (weekday !== '*' && /^[0-7]$/.test(weekday)) {
        return 168;
    }

    if (day !== '*' && /^\d{1,2}$/.test(day)) {
        return 744;
    }

    return 24;
}

/**
 * How a schedule stands: failing, overdue (stale), missed (never backed up
 * although its first slot has passed), waiting for its first run, paused,
 * or healthy.
 */
export function standing(
    schedule: Schedule,
    now: number = Date.now(),
): Standing {
    const health = schedule.health.status;

    if (health === 'failing') {
        return 'failing';
    }

    if (health === 'stale') {
        return 'overdue';
    }

    if (health === 'none' || health === 'unconfigured') {
        if (!schedule.enabled) {
            return 'paused';
        }

        const next = schedule.nextRunAt ?? schedule.health.nextRunAt;

        return next !== null && new Date(next).getTime() > now - 3_600_000
            ? 'waiting'
            : 'missed';
    }

    return schedule.enabled ? 'healthy' : 'paused';
}

/** Problems first, then the first runs still ahead, then the healthy ones. */
export function sortByStanding(
    schedules: Schedule[],
    now: number = Date.now(),
): Schedule[] {
    return schedules
        .map((schedule, index) => ({
            schedule,
            index,
            rank: order.indexOf(standing(schedule, now)),
        }))
        .sort((a, b) => a.rank - b.rank || a.index - b.index)
        .map((item) => item.schedule);
}

/** "3 backed up, 16 waiting for their first backup, 1 failing". */
export function protectionSummary(
    schedules: Schedule[],
    now: number = Date.now(),
): string {
    const counts = new Map<Standing, number>();

    for (const schedule of schedules) {
        const s = standing(schedule, now);
        counts.set(s, (counts.get(s) ?? 0) + 1);
    }

    const n = (s: Standing) => counts.get(s) ?? 0;
    const parts = [
        n('healthy') > 0 ? `${n('healthy')} backed up` : null,
        n('waiting') > 0
            ? `${n('waiting')} waiting for ${n('waiting') === 1 ? 'its' : 'their'} first backup`
            : null,
        n('missed') > 0 ? `${n('missed')} never backed up` : null,
        n('overdue') > 0 ? `${n('overdue')} overdue` : null,
        n('failing') > 0 ? `${n('failing')} failing` : null,
        n('paused') > 0 ? `${n('paused')} paused` : null,
    ].filter((part): part is string => part !== null);

    return parts.length === 0 ? 'Nothing scheduled yet' : parts.join(', ');
}

/**
 * Whether a destination's newest backup is older than its slowest schedule
 * allows (with half an interval of slack), so its card can say so.
 */
export function destinationOverdue(
    lastBackupAt: string | null,
    schedules: Schedule[],
    now: number = Date.now(),
): boolean {
    const enabled = schedules.filter((schedule) => schedule.enabled);

    if (lastBackupAt === null || enabled.length === 0) {
        return false;
    }

    const slowest = Math.max(...enabled.map((s) => intervalHours(s.cron)));

    return now - new Date(lastBackupAt).getTime() > slowest * 1.5 * 3_600_000;
}

/** "tonight at 06:07", "tomorrow at 06:07", "on 12 Nov at 06:07" (viewer's zone). */
export function firstRunLabel(iso: string, now: Date = new Date()): string {
    const at = new Date(iso);
    const time = at.toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
    });
    const startOf = (d: Date) =>
        new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime();
    const days = Math.round((startOf(at) - startOf(now)) / 86_400_000);

    if (days <= 0) {
        return at.getHours() >= 18 || at.getHours() < 5
            ? `tonight at ${time}`
            : `today at ${time}`;
    }

    if (days === 1) {
        return `tomorrow at ${time}`;
    }

    return `on ${at.toLocaleDateString([], { day: 'numeric', month: 'short' })} at ${time}`;
}
