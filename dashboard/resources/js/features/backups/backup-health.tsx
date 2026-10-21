import type { StatusState } from '@/components/status';
import { Status } from '@/components/status';
import { relativeTime } from '@/components/time-ago';

type Health = App.Data.Backups.ServiceBackupStatusData;

const states: Record<App.Enums.Backups.BackupHealth, StatusState> = {
    ok: 'active',
    stale: 'warning',
    failing: 'failed',
    running: 'progress',
    none: 'idle',
    unconfigured: 'warning',
};

/**
 * One-line backup state: "Backed up 3 hours ago", "Last backup failed", …
 * Also used for canvas nodes of databases (docs/14 §3 "Visibility").
 */
export function backupHealthLabel(health: Health): string {
    const last =
        health.lastSuccessfulAt === null
            ? null
            : relativeTime(health.lastSuccessfulAt);

    switch (health.status) {
        case 'ok':
            return `Backed up ${last}`;
        case 'stale':
            return `Last backup ${last}`;
        case 'failing':
            return 'Last backup failed';
        case 'running':
            return 'Backing up';
        case 'none':
            return 'No backup yet';
        case 'unconfigured':
            return 'Backups off';
    }
}

export function BackupHealthStatus({
    health,
    className,
}: {
    health: Health;
    className?: string;
}) {
    return (
        <Status
            state={states[health.status]}
            label={backupHealthLabel(health)}
            className={className}
        />
    );
}
