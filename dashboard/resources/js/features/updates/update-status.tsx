import { Form, Link } from '@inertiajs/react';
import {
    ArrowUpRight,
    Check,
    RefreshCw,
    RotateCcw,
    Sparkles,
    TriangleAlert,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { ProgressRing } from '@/components/progress-ring';
import { CopyValue } from '@/components/copy-value';
import { MetaLine } from '@/components/patterns/meta-line';
import { Status } from '@/components/status';
import { StepList } from '@/components/steps/step-list';
import { TimeAgo } from '@/components/time-ago';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { UpdateDialog } from '@/features/updates/update-dialog';
import { updateSteps } from '@/features/updates/update-steps';
import { cn } from '@/lib/utils';
import { vocabulary } from '@/lib/vocabulary';
import { index as backupSettings } from '@/routes/backups';
import { check } from '@/routes/updates';
import { cancel as cancelControlPlane } from '@/routes/updates/control-plane';

export type UpdatesPageProps = {
    currentVersion: string;
    check: App.Data.Updates.ReleaseCheckData;
    highlights: string[];
    hasBackupDestination: boolean;
    backupDestinationName: string | null;
    latestBackup: App.Data.Updates.PreUpgradeBackupData | null;
    runningBackup: App.Data.Updates.PreUpgradeBackupData | null;
    controlPlaneUpdate: App.Data.Updates.ControlPlaneUpdateData | null;
    fleetUpdate: App.Data.Updates.FleetUpdateData | null;
    history: App.Data.Updates.ControlPlaneUpdateData[];
    preferences: App.Data.Updates.UpdatePreferencesData;
    servers: App.Data.Updates.FleetServerData[];
    serversToUpdate: number;
    serversBehindRelease: number;
    fleetUpdatable: boolean;
    running: boolean;
};

const time = (iso: string | null): string =>
    iso === null
        ? ''
        : new Date(iso).toLocaleTimeString([], {
              hour: '2-digit',
              minute: '2-digit',
          });

function CheckButton({
    variant = 'outline',
    label = 'Check for updates',
}: {
    variant?: 'default' | 'outline' | 'ghost';
    label?: string;
}) {
    return (
        <Form {...check.form()} options={{ preserveScroll: true }}>
            {({ processing }) => (
                <Button
                    type="submit"
                    variant={variant}
                    size="sm"
                    disabled={processing}
                >
                    {processing && <Spinner />}
                    {label}
                </Button>
            )}
        </Form>
    );
}

/**
 * "Cancel update" (docs/11 §4.3): offered while nothing has changed yet,
 * whatever the update is waiting for.
 */
function CancelUpdateButton({
    update,
    variant = 'outline',
}: {
    update: App.Data.Updates.ControlPlaneUpdateData;
    variant?: 'default' | 'outline' | 'ghost';
}) {
    return (
        <Form
            {...cancelControlPlane.form({ update: update.id })}
            options={{ preserveScroll: true }}
        >
            {({ processing }) => (
                <Button
                    type="submit"
                    variant={variant}
                    size="sm"
                    disabled={processing}
                >
                    {processing && <Spinner />}
                    Cancel update
                </Button>
            )}
        </Form>
    );
}

function HeroIcon({
    tone,
    children,
}: {
    tone: 'ok' | 'signal' | 'progress' | 'attention' | 'neutral';
    children: ReactNode;
}) {
    return (
        <span
            className={cn(
                'flex size-11 shrink-0 items-center justify-center rounded-full',
                tone === 'ok' && 'bg-status-ok-soft text-status-ok',
                tone === 'neutral' && 'bg-recessed text-muted-foreground',
                tone === 'signal' && 'bg-signal-soft text-signal',
                tone === 'progress' &&
                    'bg-status-progress-soft text-status-progress',
                tone === 'attention' &&
                    'bg-status-progress-soft text-status-progress',
            )}
        >
            {children}
        </span>
    );
}

function HeroCard({
    icon,
    title,
    meta,
    actions,
    children,
    footer,
}: {
    icon: ReactNode;
    title: ReactNode;
    meta: ReactNode;
    actions?: ReactNode;
    children?: ReactNode;
    footer?: ReactNode;
}) {
    return (
        <div className="overflow-hidden rounded-xl border bg-card shadow-card">
            <div className="flex flex-col gap-5 p-6 sm:flex-row sm:items-start sm:justify-between">
                <div className="flex min-w-0 items-start gap-4">
                    {icon}
                    <div className="flex min-w-0 flex-col gap-1">
                        <h2 className="type-stat">{title}</h2>
                        <p className="type-body text-muted-foreground">
                            {meta}
                        </p>
                    </div>
                </div>
                {actions && (
                    <div className="flex shrink-0 flex-wrap items-center gap-2 sm:pt-1">
                        {actions}
                    </div>
                )}
            </div>
            {children && (
                <div className="flex flex-col gap-5 border-t px-6 py-5 sm:pl-[84px]">
                    {children}
                </div>
            )}
            {footer && (
                <div className="border-t bg-recessed px-6 py-3 type-small text-muted-foreground">
                    {footer}
                </div>
            )}
        </div>
    );
}

function Checked({ result }: { result: App.Data.Updates.ReleaseCheckData }) {
    return result.checkedAt === null ? (
        <span>Not checked yet</span>
    ) : (
        <span>
            Checked <TimeAgo value={result.checkedAt} />
        </span>
    );
}

/** "Stable channel", "Beta channel". */
function channelLabel(channel: string): string {
    return `${channel.charAt(0).toUpperCase()}${channel.slice(1)} channel`;
}

/** What "Update now" will do, as three quiet numbered rows. */
function Plan({
    version,
    hasBackupDestination,
    backupDestinationName,
    serverCount,
}: {
    version: string;
    hasBackupDestination: boolean;
    backupDestinationName: string | null;
    serverCount: number;
}) {
    const rows: { title: string; detail: ReactNode }[] = [
        {
            title: 'Back up launchroom',
            detail: hasBackupDestination ? (
                `To ${backupDestinationName ?? 'your backup destination'}, before anything changes.`
            ) : (
                <>
                    No backup destination yet: only a local snapshot is kept.{' '}
                    <Link
                        href={backupSettings()}
                        className="text-signal underline-offset-4 hover:underline"
                    >
                        Set one up
                    </Link>
                </>
            ),
        },
        {
            title: `Install ${version}`,
            detail: 'The dashboard is unavailable for a few minutes while your apps keep running.',
        },
    ];

    if (serverCount > 0) {
        rows.push({
            title:
                serverCount === 1
                    ? 'Update your other server'
                    : `Update ${serverCount} servers`,
            detail: 'Servers update one at a time, with edge servers last. A server that can’t start the new version goes back to its old one.',
        });
    }

    return (
        <ol className="flex flex-col gap-3">
            {rows.map((row, index) => (
                <li key={row.title} className="flex gap-3">
                    <span
                        aria-hidden="true"
                        className="flex size-5 shrink-0 items-center justify-center rounded-full border type-small text-muted-foreground tabular-nums"
                    >
                        {index + 1}
                    </span>
                    <div className="flex min-w-0 flex-col gap-0.5">
                        <span className="type-body font-medium">
                            {row.title}
                        </span>
                        <span className="type-small text-muted-foreground">
                            {row.detail}
                        </span>
                    </div>
                </li>
            ))}
        </ol>
    );
}

/**
 * A release the dashboard can't install itself (its image list couldn't be
 * read): why, in plain words, and the installer command to run instead.
 */
function InstallByHand({ release }: { release: App.Data.Updates.ReleaseData }) {
    return (
        <div className="flex flex-col gap-3">
            <Status
                state="warning"
                label="This release can’t be installed from the dashboard"
            />
            <p className="type-body text-muted-foreground">
                {release.cannotInstallReason ??
                    'The release doesn’t pin its images by digest.'}{' '}
                Check again in a few minutes, or install it with the installer:
                on the main server, in the folder that contains{' '}
                <code className="type-mono">launchroom/</code>, run
            </p>
            <CopyValue
                value={release.installerCommand}
                label="installer command"
            />
            <p className="type-small text-muted-foreground">
                Installed somewhere else? Add{' '}
                <code className="type-mono">--dir /path/to/launchroom</code>.
                Your settings and data are kept. The installer doesn’t take a
                backup first, so run{' '}
                <code className="type-mono">./launchroom backup</code> before.
            </p>
        </div>
    );
}

/**
 * The status hero (docs/08 §11, flows §15): up to date, a release that is
 * ready with what will happen, or the running update as one step list.
 */
export function UpdateHero(
    props: UpdatesPageProps & {
        /** The dashboard doesn't answer right now (lib/connection.ts). */
        restarting?: boolean;
    },
) {
    const {
        currentVersion,
        check: result,
        highlights,
        hasBackupDestination,
        backupDestinationName,
        latestBackup,
        runningBackup,
        controlPlaneUpdate: update,
        fleetUpdate,
        serversBehindRelease,
        running,
        restarting = false,
    } = props;
    const available = result.available;
    const updating =
        update !== null &&
        !vocabulary.Updates.ControlPlaneUpdateStatus[update.status].isTerminal;
    const serversRunning =
        !updating &&
        fleetUpdate !== null &&
        fleetUpdate.status === 'running' &&
        update !== null &&
        update.fleetUpdateId === fleetUpdate.id;

    if ((updating || serversRunning) && update !== null) {
        const stalled = updating && update.stalled && !restarting;

        return (
            <section className="flex flex-col gap-4" aria-live="polite">
                <HeroCard
                    icon={
                        stalled ? (
                            <HeroIcon tone="attention">
                                <TriangleAlert
                                    aria-hidden="true"
                                    className="size-5"
                                />
                            </HeroIcon>
                        ) : (
                            <HeroIcon tone="progress">
                                <ProgressRing
                                    tone="current"
                                    className="size-5"
                                />
                            </HeroIcon>
                        )
                    }
                    title={
                        restarting
                            ? 'launchroom is restarting…'
                            : stalled
                              ? `The update to ${update.toVersion} isn’t making progress`
                              : serversRunning
                                ? `Updating your servers to ${update.toVersion}`
                                : `Updating to ${update.toVersion}`
                    }
                    actions={
                        updating && update.canCancel && !restarting ? (
                            <CancelUpdateButton
                                update={update}
                                variant={stalled ? 'default' : 'outline'}
                            />
                        ) : undefined
                    }
                    meta={
                        <MetaLine>
                            {update.automatic && 'Automatic update'}
                            <span>
                                Started{' '}
                                <TimeAgo
                                    value={update.startedAt ?? update.createdAt}
                                />
                            </span>
                            Your apps keep running
                        </MetaLine>
                    }
                    footer={
                        restarting
                            ? `This takes about a minute. The page reloads when ${update.toVersion} responds.`
                            : stalled
                              ? stalledFooter(update)
                              : 'You can leave this page. The dashboard is unavailable for a few minutes while it restarts, and this page continues afterwards.'
                    }
                />
                <StepList
                    steps={updateSteps(
                        update,
                        fleetUpdate,
                        serversBehindRelease,
                    )}
                />
            </section>
        );
    }

    if (available === null && result.checkedAt === null) {
        return (
            <section aria-live="polite">
                <HeroCard
                    icon={
                        <HeroIcon tone="neutral">
                            <RefreshCw aria-hidden="true" className="size-5" />
                        </HeroIcon>
                    }
                    title="Not checked for updates yet"
                    meta={
                        <MetaLine>
                            <span>
                                You have{' '}
                                <span className="type-mono">
                                    {currentVersion}
                                </span>
                            </span>
                            {channelLabel(result.channel)}
                        </MetaLine>
                    }
                    actions={
                        <CheckButton variant="default" label="Check now" />
                    }
                    footer={
                        result.error !== null ? (
                            <Status
                                state="failed"
                                label={`Couldn’t check for updates: ${result.error}`}
                            />
                        ) : undefined
                    }
                />
            </section>
        );
    }

    if (available === null) {
        const last =
            update !== null && update.status === 'succeeded' ? update : null;

        return (
            <section aria-live="polite">
                <HeroCard
                    icon={
                        <HeroIcon tone="ok">
                            <Check aria-hidden="true" className="size-5" />
                        </HeroIcon>
                    }
                    title="You’re up to date"
                    meta={
                        <MetaLine>
                            <span className="type-mono">{currentVersion}</span>
                            {channelLabel(result.channel)}
                            <Checked result={result} />
                        </MetaLine>
                    }
                    actions={<CheckButton />}
                    footer={
                        result.error !== null ? (
                            <Status
                                state="failed"
                                label={`Couldn’t check for updates: ${result.error}`}
                            />
                        ) : last !== null ? (
                            <>
                                Updated from {last.fromVersion}{' '}
                                <TimeAgo value={last.finishedAt} />
                                {last.automatic ? ', automatically' : ''}.
                            </>
                        ) : undefined
                    }
                />
            </section>
        );
    }

    // The attention card above offers "Try again" for this very release.
    if (
        update !== null &&
        update.toVersion === available.version &&
        (vocabulary.Updates.ControlPlaneUpdateStatus[update.status].isFailed ||
            update.status === 'cancelled')
    ) {
        return null;
    }

    return (
        <section aria-live="polite">
            <HeroCard
                icon={
                    <HeroIcon tone="signal">
                        <Sparkles aria-hidden="true" className="size-5" />
                    </HeroIcon>
                }
                title={`launchroom ${available.version} is ready`}
                meta={
                    <MetaLine>
                        <span>
                            You have{' '}
                            <span className="type-mono">{currentVersion}</span>
                        </span>
                        {available.publishedAt !== null && (
                            <span>
                                Released{' '}
                                <TimeAgo value={available.publishedAt} />
                            </span>
                        )}
                        {available.prerelease && 'Beta'}
                    </MetaLine>
                }
                actions={
                    <>
                        {available.daemonImage !== null && (
                            <UpdateDialog
                                version={available.version}
                                hasBackupDestination={hasBackupDestination}
                                backupDestinationName={backupDestinationName}
                                latestBackup={latestBackup}
                                runningBackup={runningBackup}
                                serverCount={serversBehindRelease}
                                disabled={running}
                            />
                        )}
                        <CheckButton variant="ghost" />
                    </>
                }
                footer={
                    result.error !== null ? (
                        <Status
                            state="failed"
                            label={`Couldn’t check for updates: ${result.error}`}
                        />
                    ) : (
                        <MetaLine>
                            {channelLabel(result.channel)}
                            <Checked result={result} />
                        </MetaLine>
                    )
                }
            >
                {highlights.length > 0 && (
                    <div className="flex flex-col gap-2">
                        <h3 className="type-label text-muted-foreground">
                            What’s new
                        </h3>
                        <ul className="flex flex-col gap-2">
                            {highlights.map((line) => (
                                <li
                                    key={line}
                                    className="flex gap-2.5 type-body"
                                >
                                    <span
                                        aria-hidden="true"
                                        className="mt-2 size-1.5 shrink-0 rounded-full bg-muted-foreground"
                                    />
                                    {line}
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
                {available.daemonImage === null ? (
                    <InstallByHand release={available} />
                ) : (
                    <div className="flex flex-col gap-3">
                        <h3 className="type-label text-muted-foreground">
                            What happens
                        </h3>
                        <Plan
                            version={available.version}
                            hasBackupDestination={hasBackupDestination}
                            backupDestinationName={backupDestinationName}
                            serverCount={serversBehindRelease}
                        />
                    </div>
                )}
                {available.url !== '' && (
                    <a
                        href={available.url}
                        target="_blank"
                        rel="noreferrer noopener"
                        className="inline-flex w-fit items-center gap-0.5 type-small text-signal underline-offset-4 hover:underline"
                    >
                        Full release notes
                        <ArrowUpRight aria-hidden="true" className="size-3.5" />
                    </a>
                )}
            </HeroCard>
        </section>
    );
}

/** What a stalled update waits for, and that cancelling is safe. */
function stalledFooter(
    update: App.Data.Updates.ControlPlaneUpdateData,
): string {
    const waiting =
        update.status === 'pending'
            ? 'launchroom’s background worker hasn’t started it.'
            : update.status === 'backing_up'
              ? 'The backup before the update stopped reporting progress. launchroom starts it again by itself if its worker stopped.'
              : 'The main server hasn’t started installing it. It may be offline.';

    return update.canCancel
        ? `${waiting} Nothing has changed yet, so you can cancel the update and try again.`
        : waiting;
}

/**
 * After an update that didn't go through (docs/08 §11): what happened, in
 * which state launchroom is now, and what to do. Calm: amber, not red,
 * because the dashboard is up (you are reading this) and apps kept running.
 */
export function UpdateAttention({
    update,
    currentVersion,
    fleetUpdate,
    serverCount,
    retry,
}: {
    update: App.Data.Updates.ControlPlaneUpdateData;
    currentVersion: string;
    fleetUpdate: App.Data.Updates.FleetUpdateData | null;
    serverCount: number;
    /**
     * "Try again" here: the same release is still the newest, or the release
     * can't be checked right now (the update's pinned release is used again).
     */
    retry?: {
        version: string;
        url: string | null;
        hasBackupDestination: boolean;
        backupDestinationName: string | null;
        latestBackup: App.Data.Updates.PreUpgradeBackupData | null;
        runningBackup: App.Data.Updates.PreUpgradeBackupData | null;
        disabled: boolean;
    };
}) {
    const rolledBack = update.status === 'rolled_back';
    const cancelled = update.status === 'cancelled';
    const backupFailed =
        update.status === 'failed' && update.backupStatus === 'failed';
    // The update supervisor couldn't put the old version back (CPOPS).
    const rollbackFailed =
        update.status === 'failed' &&
        (update.error ?? '').includes('ROLLBACK FAILED');
    const stillOnOld = currentVersion === update.fromVersion;
    const window =
        update.startedAt !== null && update.finishedAt !== null
            ? `${time(update.startedAt)}–${time(update.finishedAt)}`
            : null;

    const summary = rolledBack
        ? `${update.toVersion} didn’t start, so launchroom rolled back to ${update.fromVersion}. Your apps kept running.`
        : cancelled
          ? `The update was cancelled before anything changed. launchroom is still on ${update.fromVersion}.`
          : backupFailed
            ? 'The backup before the update failed, so nothing was changed.'
            : stillOnOld
              ? `launchroom is still on ${update.fromVersion}. Your apps kept running.`
              : `The update to ${update.toVersion} stopped before it finished.`;

    const todo: ReactNode[] = cancelled
        ? [
              'Try again when you’re ready. A new backup is taken first, unless you pick an existing one.',
          ]
        : rolledBack
          ? [
                `You don’t need to fix anything. Everything is as it was before the update${window ? ` (dashboard changes made between ${window} were undone)` : ''}.`,
                'Read the release notes for known issues, then try again. A release that failed is never installed automatically.',
            ]
          : backupFailed
            ? [
                  <>
                      Check the backup storage on the{' '}
                      <Link
                          href={backupSettings()}
                          className="text-signal underline-offset-4 hover:underline"
                      >
                          Backups page
                      </Link>{' '}
                      (a test backup shows the problem), then try again.
                  </>,
              ]
            : rollbackFailed
              ? [
                    <>
                        The rollback didn’t finish. In the launchroom folder on
                        the main server, run{' '}
                        <code className="type-mono">./launchroom doctor</code>{' '}
                        to finish it, then try the update again.
                    </>,
                ]
              : [
                    'If the dashboard works as usual, you can try again.',
                    <>
                        If something looks wrong, run{' '}
                        <code className="type-mono">./launchroom status</code>{' '}
                        in the launchroom folder on the main server
                        {update.backupStatus === 'succeeded' ? (
                            <>
                                ; the backup taken at{' '}
                                {time(update.backupFinishedAt)} can bring
                                everything back (
                                <Link
                                    href={backupSettings()}
                                    className="text-signal underline-offset-4 hover:underline"
                                >
                                    Backups page
                                </Link>
                                )
                            </>
                        ) : null}
                        .
                    </>,
                ];

    return (
        <section className="flex flex-col gap-4" aria-live="polite">
            <HeroCard
                icon={
                    <HeroIcon tone="attention">
                        {rolledBack ? (
                            <RotateCcw aria-hidden="true" className="size-5" />
                        ) : (
                            <TriangleAlert
                                aria-hidden="true"
                                className="size-5"
                            />
                        )}
                    </HeroIcon>
                }
                title={
                    cancelled
                        ? `The update to ${update.toVersion} was cancelled`
                        : `The update to ${update.toVersion} didn’t go through`
                }
                meta={
                    <MetaLine>
                        {update.automatic && 'Automatic update'}
                        <TimeAgo value={update.finishedAt} />
                    </MetaLine>
                }
                actions={
                    retry !== undefined ? (
                        <>
                            <UpdateDialog
                                version={retry.version}
                                retryOf={update.id}
                                hasBackupDestination={
                                    retry.hasBackupDestination
                                }
                                backupDestinationName={
                                    retry.backupDestinationName
                                }
                                latestBackup={retry.latestBackup}
                                runningBackup={retry.runningBackup}
                                serverCount={serverCount}
                                disabled={retry.disabled}
                                label="Try again"
                            />
                            <CheckButton variant="ghost" />
                        </>
                    ) : undefined
                }
                footer={
                    retry !== undefined &&
                    retry.url !== null &&
                    retry.url !== '' ? (
                        <a
                            href={retry.url}
                            target="_blank"
                            rel="noreferrer noopener"
                            className="inline-flex items-center gap-0.5 text-signal underline-offset-4 hover:underline"
                        >
                            Release notes for {retry.version}
                            <ArrowUpRight
                                aria-hidden="true"
                                className="size-3.5"
                            />
                        </a>
                    ) : undefined
                }
            >
                <div className="flex flex-col gap-1">
                    <p className="type-body">{summary}</p>
                    {!rolledBack && !cancelled && update.error !== null && (
                        <p className="type-small break-words text-muted-foreground">
                            {update.error}
                        </p>
                    )}
                </div>
                <div className="flex flex-col gap-2">
                    <h3 className="type-label text-muted-foreground">
                        What you can do
                    </h3>
                    <ul className="flex flex-col gap-2">
                        {todo.map((line, index) => (
                            <li key={index} className="flex gap-2.5 type-body">
                                <span
                                    aria-hidden="true"
                                    className="mt-2 size-1.5 shrink-0 rounded-full bg-muted-foreground"
                                />
                                <span>{line}</span>
                            </li>
                        ))}
                    </ul>
                </div>
            </HeroCard>
            <div className="flex flex-col gap-2">
                <h3 className="type-label text-muted-foreground">
                    What happened
                </h3>
                <StepList
                    steps={updateSteps(update, fleetUpdate, serverCount)}
                />
            </div>
        </section>
    );
}
