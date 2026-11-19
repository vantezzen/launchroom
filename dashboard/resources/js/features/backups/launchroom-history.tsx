import { MetaLine } from '@/components/patterns/meta-line';
import { SidePanel, SheetSection } from '@/components/patterns/sheet';
import { Status, backupRunStatus } from '@/components/status';
import { TimeAgo } from '@/components/time-ago';
import { formatBytes, formatMs } from '@/lib/format';
import { reasonLabel } from './format';

/**
 * The last backups of launchroom itself (instance owners). Restoring it
 * runs from the installer (`install.sh --restore`) with the recovery kit.
 */
export function LaunchroomHistory({
    runs,
    open,
    onOpenChange,
}: {
    runs: App.Data.Backups.BackupRunData[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <SidePanel
            open={open}
            onOpenChange={onOpenChange}
            size="sm"
            title="Backups of launchroom itself"
            description="To restore, run the installer with --restore on a new server and use the recovery kit."
        >
            <SheetSection>
                {runs.length === 0 ? (
                    <p className="type-body text-muted-foreground">
                        No backup of launchroom itself yet.
                    </p>
                ) : (
                    <ul className="flex flex-col divide-y">
                        {runs.map((run) => (
                            <li
                                key={run.id}
                                className="flex flex-col gap-0.5 py-2.5 first:pt-0 last:pb-0"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-x-3">
                                    <Status
                                        state={
                                            backupRunStatus[run.status].state
                                        }
                                        label={
                                            backupRunStatus[run.status].label
                                        }
                                        detail={reasonLabel(run.reason)}
                                    />
                                    <MetaLine className="type-small text-muted-foreground tabular-nums">
                                        {run.createdAt !== null && (
                                            <TimeAgo value={run.createdAt} />
                                        )}
                                        {run.sizeBytes !== null &&
                                            formatBytes(run.sizeBytes)}
                                        {run.durationMs !== null &&
                                            `took ${formatMs(run.durationMs)}`}
                                    </MetaLine>
                                </div>
                                {run.error !== null && (
                                    <p className="pl-5 type-mono-small break-words text-muted-foreground">
                                        {run.error}
                                    </p>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </SheetSection>
        </SidePanel>
    );
}
