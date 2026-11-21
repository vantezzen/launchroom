import { Link, router } from '@inertiajs/react';
import { HardDrive, MoreHorizontal } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { Logo } from '@/components/logo';
import { ConfirmPopover } from '@/components/patterns/confirm-popover';
import { MetaLine } from '@/components/patterns/meta-line';
import { Status } from '@/components/status';
import type { StatusState } from '@/components/status';
import { RunStrip } from '@/components/steps/run-strip';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';
import { formatBytes } from '@/lib/format';
import { cn } from '@/lib/utils';
import { ActionReason } from './action-reason';
import { itemDetail, lastNextLine, runButtonLabel } from './overview-model';
import type { BackupItem } from './page-types';
import { RunProgress } from './run-progress';
import { ScheduleSheet } from './schedule-sheet';
import type { EditableSchedule } from './schedule-sheet';

const states: Record<BackupItem['state'], [StatusState, string]> = {
    ok: ['active', 'Backed up'],
    running: ['progress', 'Backing up'],
    queued: ['progress', 'Waiting'],
    waiting: ['idle', 'Waiting for its first backup'],
    overdue: ['warning', 'Overdue'],
    failed: ['failed', 'Failed'],
    paused: ['idle', 'Paused'],
};

type DayStatus = 'ok' | 'failed' | 'none';

function dayWord(date: string, status: DayStatus): string {
    const day = new Date(`${date}T00:00:00Z`).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
        timeZone: 'UTC',
    });

    return `${day}: ${status === 'ok' ? 'backed up' : status === 'failed' ? 'failed' : 'no backup'}`;
}

function ItemMedia({ item }: { item: BackupItem }) {
    if (item.kind === 'database' && item.engine !== null) {
        return <Logo kind={item.engine} size={32} decorative />;
    }

    return (
        <span className="flex size-8 shrink-0 items-center justify-center rounded-[9px] border bg-card text-foreground">
            {item.kind === 'control_plane' ? (
                <AppLogoIcon className="size-5" />
            ) : (
                <HardDrive aria-hidden="true" className="size-4" />
            )}
        </span>
    );
}

/**
 * Row actions show on hover or keyboard focus where there is a pointer, and
 * always on touch; a running backup or an open menu stays visible.
 */
const rowAction =
    'transition-opacity duration-fast pointer-fine:opacity-0 pointer-fine:group-focus-within/row:opacity-100 pointer-fine:group-hover/row:opacity-100 data-[state=open]:opacity-100';

/**
 * The row's one action: "Back up now", or while a run is queued or in
 * flight the run itself ("Uploading 412 MB", "Waiting for hel-1"),
 * disabled, so a second click can't happen. Nothing when the action is
 * blocked: the reason and its fix sit under the row instead.
 */
function BackUpButton({ item }: { item: BackupItem }) {
    const [starting, setStarting] = useState(false);

    if (item.run !== null) {
        return (
            <Button
                size="sm"
                variant="outline"
                disabled
                className="disabled:opacity-100"
            >
                <Spinner />
                {runButtonLabel(item.run)}
            </Button>
        );
    }

    const url = item.backUp.url;

    if (url === null) {
        return null;
    }

    return (
        <Button
            size="sm"
            variant="outline"
            disabled={starting}
            className={cn(!starting && rowAction)}
            onClick={() =>
                router.post(
                    url,
                    {},
                    {
                        preserveScroll: true,
                        onStart: () => setStarting(true),
                        onFinish: () => setStarting(false),
                    },
                )
            }
        >
            {starting && <Spinner />}
            {starting ? 'Starting' : 'Back up now'}
        </Button>
    );
}

/**
 * One thing that holds data (docs/design/flows.md §14): a database, a
 * volume or launchroom itself. What it is and where, how it stands with
 * last and next, the newest copy's size, its schedule and retention as a
 * sentence, the 30-day strip, and Back up now. A running backup unfolds
 * its steps under the row; a blocked action says why, with the fix.
 */
export function BackupItemRow({
    item,
    onShowHistory,
}: {
    item: BackupItem;
    /** launchroom itself keeps its history on this page (a sheet). */
    onShowHistory?: () => void;
}) {
    const [editing, setEditing] = useState(false);
    const [turningOff, setTurningOff] = useState(false);
    const [state, word] = item.storageRemoved
        ? (['warning', 'Storage removed'] as [StatusState, string])
        : states[item.state];
    const schedule = item.schedule;
    const size =
        item.lastSizeBytes ?? (item.liveSizeBytes ? item.liveSizeBytes : null);
    const volumeUrl = item.volumeBackup?.url ?? null;
    const detail = schedule?.detail ?? null;
    const editable: EditableSchedule | null =
        schedule !== null && detail !== null
            ? {
                  id: schedule.id,
                  label:
                      item.kind === 'volume' && item.serviceName !== null
                          ? `Files of ${item.serviceName}`
                          : item.kind === 'control_plane'
                            ? 'launchroom itself'
                            : item.name,
                  destinationName: detail.destinationName,
                  cron: schedule.cron,
                  retention: detail.retention,
                  retentionLimits: detail.retentionLimits,
                  enabled: schedule.enabled,
                  updateUrl: schedule.updateUrl,
              }
            : null;
    const reason =
        item.run === null && item.backUp.disabled !== null
            ? item.backUp.disabled
            : null;
    const hasMenu =
        item.restoreHref !== null ||
        editable !== null ||
        volumeUrl !== null ||
        item.historyHref !== null ||
        onShowHistory !== undefined;
    const label =
        item.kind === 'volume' ? (item.mountPath ?? item.name) : item.name;

    const turnOff = () =>
        new Promise<void>((resolve) => {
            if (volumeUrl === null) {
                resolve();

                return;
            }

            router.put(
                volumeUrl,
                { enabled: false },
                { preserveScroll: true, onFinish: () => resolve() },
            );
        });

    return (
        <li
            id={`backup-${item.id}`}
            className="group/row @container flex scroll-mt-24 flex-col gap-3 px-4 py-4 sm:px-5"
        >
            <div className="flex flex-wrap items-start gap-x-3.5 gap-y-3">
                <ItemMedia item={item} />

                <div className="grid min-w-0 flex-1 basis-60 gap-x-6 gap-y-2 @2xl:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)_5.5rem]">
                    <div className="flex min-w-0 flex-col gap-0.5">
                        <span
                            className={cn(
                                'truncate type-body font-medium',
                                item.kind === 'volume' &&
                                    'font-mono text-[13px]',
                            )}
                            title={label}
                        >
                            {item.kind === 'control_plane'
                                ? 'launchroom itself'
                                : label}
                        </span>
                        <span className="truncate type-small text-muted-foreground">
                            {itemDetail(item)}
                        </span>
                        {schedule !== null && (
                            <span className="truncate type-small text-muted-foreground">
                                {schedule.enabled
                                    ? `${schedule.cronDescription ?? 'On a custom schedule'}, keeps ${schedule.retentionSummary}`
                                    : 'Automatic backups paused'}
                            </span>
                        )}
                    </div>

                    <div className="flex min-w-0 flex-col gap-1">
                        <Status
                            state={state}
                            label={word}
                            className="type-small"
                        />
                        {item.run === null && (
                            <span className="truncate type-small text-muted-foreground">
                                {lastNextLine(item)}
                            </span>
                        )}
                        {item.days.some((day) => day.status !== 'none') && (
                            <RunStrip
                                runs={item.days.map((day) => ({
                                    key: day.date,
                                    status: day.status as DayStatus,
                                    label: dayWord(
                                        day.date,
                                        day.status as DayStatus,
                                    ),
                                }))}
                                label={`${label}, last 30 days`}
                                height={14}
                                className="mt-0.5 hidden w-36 @2xl:block"
                            />
                        )}
                    </div>

                    <div className="flex flex-col @2xl:items-end">
                        {size !== null && (
                            <>
                                <span className="type-body tabular-nums">
                                    {formatBytes(size)}
                                </span>
                                <span className="type-small text-muted-foreground">
                                    {item.lastSizeBytes !== null
                                        ? 'newest copy'
                                        : 'on disk'}
                                </span>
                            </>
                        )}
                    </div>
                </div>

                <div className="flex shrink-0 items-center gap-1 @max-2xl:w-full @max-2xl:pl-[46px] @2xl:justify-end @4xl:w-60">
                    <BackUpButton item={item} />
                    {hasMenu && (
                        <ConfirmPopover
                            open={turningOff}
                            onOpenChange={setTurningOff}
                            title="Stop backing up this volume?"
                            consequence={`New backups stop. Existing backups stay until they expire${schedule !== null ? ` (${schedule.retentionSummary})` : ''}.`}
                            confirmLabel="Stop backing up"
                            tone="destructive"
                            onConfirm={turnOff}
                        >
                            <DropdownMenu modal={false}>
                                <DropdownMenuTrigger asChild>
                                    <Button
                                        variant="ghost"
                                        size="icon-sm"
                                        aria-label={`More for ${label}`}
                                        className={cn(!turningOff && rowAction)}
                                    >
                                        <MoreHorizontal aria-hidden="true" />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    {item.restoreHref !== null && (
                                        <DropdownMenuItem asChild>
                                            <Link href={item.restoreHref}>
                                                Restore…
                                            </Link>
                                        </DropdownMenuItem>
                                    )}
                                    {editable !== null && (
                                        <DropdownMenuItem
                                            onSelect={() => setEditing(true)}
                                        >
                                            {schedule?.updateUrl !== null
                                                ? 'Change schedule…'
                                                : 'Show schedule'}
                                        </DropdownMenuItem>
                                    )}
                                    {onShowHistory !== undefined ? (
                                        <DropdownMenuItem
                                            onSelect={onShowHistory}
                                        >
                                            Show history
                                        </DropdownMenuItem>
                                    ) : (
                                        item.historyHref !== null && (
                                            <DropdownMenuItem asChild>
                                                <Link href={item.historyHref}>
                                                    Show history
                                                </Link>
                                            </DropdownMenuItem>
                                        )
                                    )}
                                    {volumeUrl !== null && (
                                        <DropdownMenuItem
                                            variant="destructive"
                                            onSelect={() => setTurningOff(true)}
                                        >
                                            Stop backing up…
                                        </DropdownMenuItem>
                                    )}
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </ConfirmPopover>
                    )}
                </div>
            </div>

            <div className="flex min-w-0 flex-col gap-2 sm:pl-[46px]">
                {item.run !== null && (
                    <RunProgress kind={item.kind} run={item.run} />
                )}
                {item.state === 'failed' && item.lastError !== null && (
                    <p className="type-small break-words text-muted-foreground">
                        <span className="font-mono text-status-error">
                            {item.lastError}
                        </span>
                        {item.historyHref !== null && (
                            <>
                                {' '}
                                <Link
                                    href={item.historyHref}
                                    className="font-medium text-signal underline-offset-4 hover:underline"
                                >
                                    See what happened
                                </Link>
                            </>
                        )}
                    </p>
                )}
                {reason !== null && <ActionReason reason={reason} />}
                {item.volumeBackup?.looksLikeCache && volumeUrl !== null && (
                    <p className="type-small text-muted-foreground">
                        Looks like a cache.{' '}
                        <button
                            type="button"
                            className="rounded-sm font-medium text-signal underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            onClick={() => setTurningOff(true)}
                        >
                            Stop backing it up
                        </button>
                    </p>
                )}
            </div>

            {editable !== null && (
                <ScheduleSheet
                    schedule={editable}
                    open={editing}
                    onOpenChange={setEditing}
                >
                    {schedule !== null && schedule.sharedWithVolumes > 1 && (
                        <p className="px-5 pb-5 type-small text-muted-foreground sm:px-6">
                            This schedule covers all{' '}
                            {schedule.sharedWithVolumes} volumes of{' '}
                            {item.serviceName}.
                        </p>
                    )}
                </ScheduleSheet>
            )}
        </li>
    );
}

/** A titled group of rows ("shop", "4 items, all backed up"). */
export function ItemGroup({
    title,
    summary,
    children,
}: {
    title: string | null;
    summary: string;
    children: ReactNode;
}) {
    return (
        <section
            aria-label={title ?? 'launchroom itself'}
            className="border-t first:border-t-0"
        >
            {title !== null && (
                <MetaLine className="flex w-full border-b bg-recessed px-4 py-2 type-small text-muted-foreground sm:px-5">
                    <span className="type-label text-foreground">{title}</span>
                    {summary}
                </MetaLine>
            )}
            <ul className="divide-y">{children}</ul>
        </section>
    );
}
