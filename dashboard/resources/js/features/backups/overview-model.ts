/**
 * The Backups page's words (docs/14 §3, docs/design/flows.md §14): the
 * hero sentence, group summaries, each row's last/next line and the live
 * progress of a run. Pure, so it is tested without React.
 */
import { formatBytes, formatDuration, plural } from '@/lib/format';
import type {
    BackupBatch,
    BackupItem,
    BackupRunProgress,
    BackupsSummary,
} from './page-types';

/** "at 16:12" today, "tonight at 03:12", "tomorrow at 09:00", "on 21 Nov at 03:12". */
export function whenLabel(iso: string, now: Date = new Date()): string {
    const at = new Date(iso);
    const time = at.toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
    });
    const startOf = (d: Date) =>
        new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime();
    const days = Math.round((startOf(at) - startOf(now)) / 86_400_000);
    const night = at.getHours() >= 18 || at.getHours() < 5;

    if (days <= 0) {
        return night ? `tonight at ${time}` : `at ${time}`;
    }

    if (days === 1) {
        return at.getHours() < 5 ? `tonight at ${time}` : `tomorrow at ${time}`;
    }

    return `on ${at.toLocaleDateString([], { day: 'numeric', month: 'short' })} at ${time}`;
}

/** "just now", "12 minutes ago", "2 hours ago", "3 days ago". */
export function agoLabel(iso: string, now: number = Date.now()): string {
    const minutes = Math.round((now - new Date(iso).getTime()) / 60_000);

    if (minutes < 1) {
        return 'just now';
    }

    if (minutes < 60) {
        return `${plural(minutes, 'minute')} ago`;
    }

    const hours = Math.round(minutes / 60);

    if (hours < 48) {
        return `${plural(hours, 'hour')} ago`;
    }

    return `${plural(Math.round(hours / 24), 'day')} ago`;
}

export type HeroTone = BackupsSummary['state'];

/**
 * The page's headline, the state as one sentence (Google Photos' "Backup
 * is on"): everything backed up, what needs you, the first backups ahead,
 * or what runs now.
 */
export function heroTitle(
    summary: BackupsSummary,
    now: Date = new Date(),
): string {
    switch (summary.state) {
        case 'unconfigured':
            return 'Nothing is backed up off your servers yet.';
        case 'running': {
            const waiting = summary.queued;
            const head =
                summary.running === 1
                    ? 'Backing up 1 item'
                    : `Backing up ${plural(summary.running, 'item')}`;

            if (waiting > 0 && waiting === summary.running) {
                return `${plural(waiting, 'backup')} waiting ${waiting === 1 ? 'its' : 'their'} turn. They start by themselves.`;
            }

            return waiting > 0
                ? `${head}, ${waiting} waiting ${waiting === 1 ? 'its' : 'their'} turn. You can leave this page.`
                : `${head}. You can leave this page.`;
        }
        case 'attention':
            return summary.attention.length === 1
                ? `${summary.attention[0].name} needs you.`
                : `${plural(summary.attention.length, 'item')} need you.`;
        case 'waiting':
            if (summary.lastSucceededAt === null) {
                return summary.nextRunAt !== null
                    ? `Waiting for the first backups. The first one starts ${whenLabel(summary.nextRunAt, now)}.`
                    : 'Waiting for the first backups.';
            }

            return `${plural(summary.ok, 'item')} backed up, ${summary.waiting} waiting for ${summary.waiting === 1 ? 'its' : 'their'} first backup.`;
        case 'ok': {
            const last =
                summary.lastSucceededAt !== null
                    ? ` Last backup ${agoLabel(summary.lastSucceededAt, now.getTime())}`
                    : '';
            const next =
                summary.nextRunAt !== null
                    ? `${last === '' ? ' Next backup' : ', next'} ${whenLabel(summary.nextRunAt, now)}`
                    : '';

            return `Everything is backed up.${last}${next}${last + next === '' ? '' : '.'}`;
        }
    }
}

/** "4 items, all backed up", "3 items, 1 failed", "Backed up". */
export function groupSummary(items: BackupItem[]): string {
    const count = plural(items.length, 'item');
    const n = (states: BackupItem['state'][]) =>
        items.filter((item) => states.includes(item.state)).length;
    const failed = n(['failed']);
    const overdue = n(['overdue']);
    const busy = n(['running', 'queued']);
    const waiting = n(['waiting']);
    const paused = n(['paused']);

    if (failed > 0) {
        return `${count}, ${failed} failed`;
    }

    if (overdue > 0) {
        return `${count}, ${overdue} overdue`;
    }

    if (busy > 0) {
        return `${count}, ${busy} backing up`;
    }

    if (waiting > 0) {
        return `${count}, ${waiting} waiting for ${waiting === 1 ? 'its' : 'their'} first backup`;
    }

    if (paused > 0) {
        return `${count}, ${paused} stopped`;
    }

    return items.length === 1 ? '1 item, backed up' : `${count}, all backed up`;
}

const engines: Record<string, string> = {
    postgres: 'Postgres',
    mysql: 'MySQL',
    mariadb: 'MariaDB',
    redis: 'Redis',
    valkey: 'Valkey',
    mongodb: 'MongoDB',
    clickhouse: 'ClickHouse',
};

/** The row's second line: "Postgres 17, production", "/app/storage on api, production". */
export function itemDetail(item: BackupItem): string {
    if (item.kind === 'control_plane') {
        return 'Projects, settings and keys';
    }

    const environment = item.environmentName;
    const what =
        item.kind === 'database'
            ? [
                  item.engine !== null
                      ? (engines[item.engine] ?? item.engine)
                      : 'Database',
                  item.engineVersion,
              ]
                  .filter(Boolean)
                  .join(' ')
            : item.serviceName !== null
              ? `Files of ${item.serviceName}`
              : 'Files';

    return environment !== null ? `${what}, ${environment}` : what;
}

/** "3 hours ago, next tonight at 03:12" / "First backup tonight at 03:12". */
export function lastNextLine(item: BackupItem, now: Date = new Date()): string {
    const next =
        item.nextRunAt !== null ? whenLabel(item.nextRunAt, now) : null;

    if (item.lastSucceededAt === null) {
        if (item.state === 'failed' || item.state === 'overdue') {
            return next !== null
                ? `No backup yet, next try ${next}`
                : 'No backup yet';
        }

        return next !== null ? `First backup ${next}` : 'No backup yet';
    }

    const last = agoLabel(item.lastSucceededAt, now.getTime());

    return next !== null
        ? `${last.charAt(0).toUpperCase()}${last.slice(1)}, next ${next}`
        : `${last.charAt(0).toUpperCase()}${last.slice(1)}`;
}

/** "412 MB", "1.2 GB of about 1.5 GB", "1.2 GB of 1.5 GB". */
export function bytesLine(run: BackupRunProgress): string | null {
    if (run.bytesDone === null || run.bytesDone <= 0) {
        return null;
    }

    if (run.bytesExpected === null || run.bytesExpected <= 0) {
        return formatBytes(run.bytesDone);
    }

    if (run.expectedIsEstimate) {
        return run.bytesExpected > run.bytesDone
            ? `${formatBytes(run.bytesDone)} of about ${formatBytes(run.bytesExpected)}`
            : formatBytes(run.bytesDone);
    }

    return `${formatBytes(run.bytesDone)} of ${formatBytes(run.bytesExpected)}`;
}

/**
 * 0–100 for a bar, only with a real total (launchroomd's percentage or a
 * reported size). A stream measured against the last size shows bytes
 * instead: a bar there would be a guess.
 */
export function runPercent(run: BackupRunProgress): number | null {
    if (run.percent !== null) {
        return run.percent;
    }

    if (
        run.expectedIsEstimate ||
        run.bytesDone === null ||
        run.bytesExpected === null ||
        run.bytesExpected <= 0
    ) {
        return null;
    }

    return Math.min(99, (run.bytesDone / run.bytesExpected) * 100);
}

export type StepState = 'succeeded' | 'running' | 'pending';

export type ProgressStep = {
    id: string;
    name: string;
    status: StepState;
    /** "412 MB of about 1.5 GB" on the running copy step. */
    detail: string | null;
};

const phases = ['preparing', 'streaming', 'finishing'] as const;

/**
 * The steps of a running backup, from its phase: open the storage, copy
 * and upload in one stream, store the snapshot. Queued runs have every
 * step ahead.
 */
export function runSteps(
    kind: BackupItem['kind'],
    run: BackupRunProgress,
): ProgressStep[] {
    const names: Record<(typeof phases)[number], string> = {
        preparing: 'Open the backup storage',
        streaming:
            kind === 'database'
                ? 'Copy and upload the database'
                : kind === 'volume'
                  ? 'Copy and upload the files'
                  : 'Copy launchroom’s database and keys',
        finishing: 'Store the snapshot',
    };
    const current =
        run.phase === 'stored'
            ? phases.length
            : phases.indexOf(run.phase as (typeof phases)[number]);

    return phases.map((phase, index) => ({
        id: phase,
        name: names[phase],
        status:
            current < 0 || index > current
                ? 'pending'
                : index < current
                  ? 'succeeded'
                  : 'running',
        detail:
            index === current && phase === 'streaming' ? bytesLine(run) : null,
    }));
}

/**
 * The short line a busy row and its button show: "Waiting for hel-1",
 * "Waiting to start", "Uploading 412 MB", "Storing the snapshot".
 */
export function runLabel(run: BackupRunProgress): string {
    switch (run.phase) {
        case 'queued':
        case 'waiting':
            return run.waitingFor !== null
                ? `Waiting for ${run.waitingFor}`
                : 'Waiting to start';
        case 'streaming': {
            const bytes = bytesLine(run);

            return bytes !== null ? `Uploading ${bytes}` : 'Uploading';
        }
        case 'finishing':
        case 'stored':
            return 'Storing the snapshot';
        default:
            return 'Starting';
    }
}

/**
 * The busy button's label: short, so it never truncates at phone width.
 * "Uploading", "Uploading 42%" only when a real total is known; the bytes
 * ("412 MB of about 1.5 GB") live in the expanded steps.
 */
export function runButtonLabel(run: BackupRunProgress): string {
    switch (run.phase) {
        case 'queued':
        case 'waiting':
            return 'Waiting';
        case 'streaming': {
            const percent = runPercent(run);

            return percent !== null
                ? `Uploading ${Math.floor(percent)}%`
                : 'Uploading';
        }
        case 'finishing':
        case 'stored':
            return 'Storing';
        default:
            return 'Starting';
    }
}

/** Seconds since the run started (launchroomd's figure when newer), or null while queued. */
export function runElapsedSeconds(
    run: BackupRunProgress,
    now: number = Date.now(),
): number | null {
    if (run.startedAt === null) {
        return null;
    }

    const local = Math.floor((now - new Date(run.startedAt).getTime()) / 1000);

    return Math.max(0, local, Math.floor((run.elapsedMs ?? 0) / 1000));
}

/** "1m 20s, usually 2m 5s". */
export function elapsedLine(
    run: BackupRunProgress,
    now: number = Date.now(),
): string | null {
    const seconds = runElapsedSeconds(run, now);

    if (seconds === null) {
        return null;
    }

    const usual =
        run.usualDurationMs !== null
            ? `, usually ${formatDuration(Math.round(run.usualDurationMs / 1000))}`
            : '';

    return `${formatDuration(seconds)}${usual}`;
}

/** "Backing up 3 of 8" while a batch runs. */
export function batchLabel(batch: BackupBatch): string {
    const done = batch.succeeded + batch.failed;

    return batch.total <= 1
        ? 'Backing up'
        : `Backing up ${Math.min(done + 1, batch.total)} of ${batch.total}`;
}

/** A finished batch: "8 backed up in 6m 2s." or "7 of 8 backed up. orders-db failed." */
export function batchOutcome(batch: BackupBatch): string | null {
    if (batch.finishedAt === null) {
        return null;
    }

    const seconds = Math.max(
        0,
        Math.round(
            (new Date(batch.finishedAt).getTime() -
                new Date(batch.startedAt).getTime()) /
                1000,
        ),
    );

    if (batch.failed === 0) {
        return `${plural(batch.succeeded, 'item')} backed up in ${formatDuration(seconds)}.`;
    }

    const names = batch.failures.map((failure) => failure.name);
    const failedPart =
        names.length === 1
            ? `${names[0]} failed.`
            : `${plural(batch.failed, 'item')} failed.`;

    return `${batch.succeeded} of ${batch.total} backed up. ${failedPart}`;
}
