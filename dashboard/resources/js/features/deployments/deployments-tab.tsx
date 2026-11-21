import { Link, router } from '@inertiajs/react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { MoreHorizontal, RefreshCw, Rocket, ScrollText } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { CommitAvatar } from '@/components/commit-avatar';
import { Duration, Elapsed } from '@/components/elapsed';
import { ConfirmPopover } from '@/components/patterns/confirm-popover';
import { EmptyState } from '@/components/patterns/empty-state';
import { MetaLine } from '@/components/patterns/meta-line';
import { StatusIcon } from '@/components/status';
import { StepIcon, STEP_STATUS_LABEL } from '@/components/steps/step-icon';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Skeleton } from '@/components/ui/skeleton';
import { stepRows } from '@/features/deployments/steps';
import type { StepRow } from '@/features/deployments/steps';
import { useNow } from '@/hooks/use-now';
import { cn } from '@/lib/utils';
import { rollback } from '@/routes/deployments';
import services from '@/routes/projects/services';
import { show as stagedChanges } from '@/routes/staged-changes';
import type { PendingAction } from './deployment-actions';
import {
    DeploymentActionDialog,
    deploymentArgs,
    deploymentUrl,
} from './deployment-actions';
import { DeploymentLink } from './deployment-link';
import { RollbackRows } from './rollback-rows';
import { deploymentLogsHref } from './related-cards';
import {
    branchName,
    deploymentStatusState,
    deploymentTitle,
    deploymentWord,
    isCancellable,
    isInProgress,
    stoppedLine,
} from './format';
import { listHead } from './list-head';
import {
    flushDeploymentPages,
    serviceDeploymentsQuery,
} from './service-deployments';
import { compactAge, formatDuration, shortSha } from '@/lib/format';
import {
    optimisticQueued,
    rollbackToast,
} from '@/features/service-lifecycle/optimistic';
import { ChannelEvents } from '@/components/channel-events';
import { DeployButton } from '@/features/service-lifecycle/deploy-button';
import { channelName } from '@/lib/realtime';
import { vocabulary } from '@/lib/vocabulary';
import { LIFECYCLE_ICON } from '@/features/service-lifecycle/lifecycle';
import { runRemedy } from '@/features/service-status/remedy';
import {
    notServing,
    notServingSentence,
} from '@/features/service-status/live-status';
import { useServiceStatus } from '@/features/service-status/store';

export { serviceDeploymentsKey } from './service-deployments';

type DeploymentsTabProps = {
    project: string;
    environment: string;
    service: string;
    /** A database: offers importing data before the first deploy (docs/14 §12.9). */
    database?: boolean;
    /** The panel header's actions (D68): Start on the stopped row. */
    controls?: App.Data.Canvas.ServiceControlsData | null;
    /** The service's logo: the deployment page opens with it (instant visit). */
    logo?: string;
};

type Summary = App.Data.Deployments.DeploymentSummaryData;

/** History rows shown before "Show all". */
const HISTORY = 5;

/** Short step names for the row's track ("Release command" is long). */
const TRACK_LABELS: Record<string, string> = {
    plan: 'Plan',
    build: 'Build',
    release: 'Release',
    rollout: 'Roll out',
    health: 'Health check',
    route: 'Switch traffic',
};

/**
 * Service panel → Deployments (docs/08 §12, direction §9.5). The current
 * deployment (the one in progress, else the live one, else the newest) is
 * pinned in its own card, expanded: the same avatar column, title and meta
 * line as every other row, plus the steps with live status and times, and
 * View logs and Redeploy under it. Then the History, newest first, five
 * rows until "Show all". Every row is one link to its deployment (its logs);
 * actions live in the row's `⋯` menu, and the service's own (Deploy,
 * Start) in the panel header. The rest are compact rows with Roll back on
 * hover. Live over the environment channel.
 */
export function DeploymentsTab({
    project,
    environment,
    service,
    database = false,
    controls = null,
    logo,
}: DeploymentsTabProps) {
    const queryClient = useQueryClient();
    const query = serviceDeploymentsQuery(project, environment, service);
    const key = query.queryKey;
    const [action, setAction] = useState<PendingAction | null>(null);
    const [showAll, setShowAll] = useState(false);
    const stoppedAt = controls?.stoppedAt ?? null;
    const seenStop = useRef(stoppedAt);

    // Stop and Start change no deployment row by themselves: refetch when
    // the header reports the service stopped or started.
    useEffect(() => {
        if (seenStop.current !== stoppedAt) {
            seenStop.current = stoppedAt;
            void queryClient.invalidateQueries({ queryKey: key });
        }
    }, [stoppedAt, queryClient, key]);

    // Usually already loading or loaded: the canvas starts it on hover and
    // on click, in parallel with the panel (docs/10 §3).
    const { data, isPending, isError, error } = useQuery(query);

    const refresh = (
        event: App.Data.Broadcasts.DeploymentUpdatedData | null,
    ) => {
        if (event === null || event.serviceId === service) {
            // Prefetched deployment pages of this service are stale now.
            flushDeploymentPages(environment, service);
            void queryClient.invalidateQueries({ queryKey: key });
        }
    };

    const head = data ? listHead(data) : null;
    const live = data?.deployments.find((item) => item.isActive) ?? null;
    // The live deployment says what the service does (D108): never "Live"
    // while the header says "Not running".
    const serviceStatus = notServing(useServiceStatus(environment, service));
    const runtimeLogs =
        serviceStatus && head?.isActive
            ? services.show({ project, environment, service, tab: 'logs' }).url
            : null;
    const rest =
        data?.deployments.filter((deployment) => deployment.id !== head?.id) ??
        [];

    return (
        <div className="flex flex-col gap-6">
            <ChannelEvents
                channel={channelName('environments', environment)}
                event="deployment.updated"
                onEvent={refresh}
            />

            {isPending && <ListSkeleton />}

            {isError && (
                <p className="type-body text-status-error">
                    Could not load deployments: {error.message}
                </p>
            )}

            {data && head === null && (
                <EmptyState
                    icon={Rocket}
                    action={
                        <div className="flex flex-wrap justify-center gap-2">
                            {controls?.staged === false ? (
                                controls.canControl && (
                                    <DeployButton
                                        project={project}
                                        environment={environment}
                                        service={service}
                                        name="this service"
                                        staged={false}
                                        versioned={controls.versioned}
                                    />
                                )
                            ) : (
                                <Button variant="outline" asChild>
                                    <Link
                                        href={
                                            stagedChanges({
                                                project,
                                                environment,
                                            }).url
                                        }
                                    >
                                        Review staged changes
                                    </Link>
                                </Button>
                            )}
                            {database && (
                                <Button variant="ghost" asChild>
                                    <Link
                                        href={services.section({
                                            project,
                                            environment,
                                            service,
                                            section: 'backups',
                                        })}
                                        only={['panel']}
                                        preserveState
                                        preserveScroll
                                    >
                                        Import data first
                                    </Link>
                                </Button>
                            )}
                        </div>
                    }
                >
                    {controls?.staged === false
                        ? 'Not deployed here yet.'
                        : 'Not running here yet. Deploy the staged changes to start it.'}
                </EmptyState>
            )}

            {data && head !== null && (
                <>
                    {/* The current deployment, pinned (DPL-9): in progress,
                        else live, else the newest. */}
                    <section
                        aria-label="Current deployment"
                        className="flex flex-col overflow-hidden rounded-xl border bg-card shadow-card"
                    >
                        <ol>
                            <DeploymentRow
                                deployment={head}
                                live={live}
                                canDeploy={data.canDeploy}
                                onAction={setAction}
                                logo={logo}
                                serviceStatus={serviceStatus}
                                remedy={
                                    data.canDeploy &&
                                    serviceStatus?.remedy &&
                                    head.isActive ? (
                                        <RemedyButton
                                            remedy={serviceStatus.remedy}
                                            target={{
                                                project,
                                                environment,
                                                service,
                                                name: head.serviceName,
                                                deployment:
                                                    serviceStatus.deploymentId,
                                            }}
                                        />
                                    ) : null
                                }
                                expanded={{
                                    steps:
                                        data.current?.id === head.id
                                            ? data.current.steps
                                            : [],
                                    expectsRelease:
                                        data.current?.id === head.id &&
                                        data.current.expectsRelease,
                                }}
                            />
                        </ol>
                        <div className="flex flex-wrap justify-end gap-2 border-t bg-(--recessed) px-4 py-2.5">
                            <Button variant="outline" size="sm" asChild>
                                {/* Not running: what the container printed says why. */}
                                <Link
                                    href={
                                        runtimeLogs ?? deploymentLogsHref(head)
                                    }
                                >
                                    <ScrollText aria-hidden="true" />
                                    View logs
                                </Link>
                            </Button>
                            {data.canDeploy &&
                                !isInProgress(head.status) &&
                                !head.stopped && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            setAction({
                                                kind: 'redeploy',
                                                deployment: head,
                                            })
                                        }
                                    >
                                        <RefreshCw aria-hidden="true" />
                                        Redeploy
                                    </Button>
                                )}
                        </div>
                    </section>

                    {rest.length > 0 && (
                        <section
                            aria-labelledby="deployment-history"
                            className="flex flex-col gap-2"
                        >
                            <h3
                                id="deployment-history"
                                className="type-label text-muted-foreground"
                            >
                                History
                            </h3>
                            <ol
                                aria-label="Earlier deployments"
                                className="flex flex-col overflow-hidden rounded-xl border bg-card shadow-card"
                            >
                                {(showAll ? rest : rest.slice(0, HISTORY)).map(
                                    (deployment) => (
                                        <DeploymentRow
                                            key={deployment.id}
                                            deployment={deployment}
                                            live={live}
                                            canDeploy={data.canDeploy}
                                            onAction={setAction}
                                            logo={logo}
                                            serviceStatus={serviceStatus}
                                        />
                                    ),
                                )}
                            </ol>
                            {rest.length > HISTORY && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    className="self-start"
                                    onClick={() => setShowAll(!showAll)}
                                >
                                    {showAll
                                        ? 'Show fewer'
                                        : `Show all ${rest.length}`}
                                </Button>
                            )}
                        </section>
                    )}
                </>
            )}

            <DeploymentActionDialog
                action={action}
                onClose={() => setAction(null)}
            />
        </div>
    );
}

/**
 * The status word with its cue: "Building 41s", "Live", "Stopped", "Failed".
 * `icon` is off on the expanded row while it runs: its step track carries
 * the spinner, so there is one per deployment.
 */
function RowStatus({
    deployment,
    icon = true,
    serviceStatus = null,
}: {
    deployment: Summary;
    icon?: boolean;
    /** The service isn't serving: the live row says so instead of "Live". */
    serviceStatus?: App.Data.Canvas.NodeStatusData | null;
}) {
    const running = isInProgress(deployment.status);
    const instead =
        serviceStatus && deployment.isActive && !deployment.stopped
            ? serviceStatus
            : null;
    const state = instead?.state ?? deploymentStatusState(deployment);

    return (
        <span className="inline-flex shrink-0 items-center gap-1.5">
            {icon && <StatusIcon state={state} className="size-3" />}
            <span
                className={cn(
                    state === 'active' && 'text-foreground',
                    state === 'failed' && 'text-status-error',
                    state === 'progress' && 'text-foreground',
                )}
            >
                {running
                    ? deployment.statusLabel
                    : (instead?.label ?? deploymentWord(deployment))}
            </span>
            {running && (
                <Elapsed
                    since={deployment.startedAt ?? deployment.queuedAt}
                    className="text-muted-foreground"
                />
            )}
        </span>
    );
}

/**
 * One deployment of the list. Every row has the same three columns: the
 * commit author's avatar, the message over a meta line (status, sha,
 * branch, who, age, duration) and the row's menu. `expanded` (the first
 * row) adds the steps under the text column. The title's link covers
 * the whole row, so a click anywhere but the menu opens the deployment.
 */
function DeploymentRow({
    deployment,
    live,
    canDeploy,
    onAction,
    logo,
    expanded,
    serviceStatus = null,
    remedy = null,
}: {
    deployment: Summary;
    /** The serving deployment: the rollback confirm shows it above the target. */
    live: Summary | null;
    canDeploy: boolean;
    onAction: (action: PendingAction) => void;
    logo?: string;
    /** The service's status while its live deployment isn't serving (D108). */
    serviceStatus?: App.Data.Canvas.NodeStatusData | null;
    /** The fix for that, under the note (Start, Restart). */
    remedy?: ReactNode;
    expanded?: {
        steps: App.Data.Deployments.DeploymentStepData[];
        expectsRelease: boolean;
    };
}) {
    const sha = shortSha(deployment.commitSha);
    const running = isInProgress(deployment.status);
    const failure =
        deployment.status === 'failed' && deployment.failureSummary
            ? deployment.failureSummary.split('\n')[0]
            : null;

    return (
        <li
            aria-current={expanded ? 'true' : undefined}
            className={cn(
                'group relative grid grid-cols-[24px_minmax(0,1fr)_auto] gap-x-3 px-4 transition-colors duration-fast',
                'border-b last:border-b-0 hover:bg-hover',
                expanded ? 'gap-y-4 py-4' : 'py-3',
            )}
        >
            <span className="flex h-5 items-center pt-px">
                <CommitAvatar
                    name={deployment.commitAuthor ?? deployment.actorName}
                    avatarUrl={deployment.commitAuthorAvatarUrl}
                    size={24}
                />
            </span>
            <div className="flex min-w-0 flex-col gap-1">
                {/* The expanded row is the one people open: its page is
                    loaded as soon as the row appears. */}
                <DeploymentLink
                    deployment={deployment}
                    eager={expanded !== undefined}
                    preview={{
                        logo,
                        steps: expanded?.steps,
                        expectsRelease: expanded?.expectsRelease,
                    }}
                    className="truncate type-body font-medium outline-none after:absolute after:inset-0 focus-visible:after:ring-2 focus-visible:after:ring-ring focus-visible:after:ring-inset"
                >
                    {deploymentTitle(deployment)}
                </DeploymentLink>
                <MetaLine className="type-small text-muted-foreground tabular-nums">
                    <RowStatus
                        deployment={deployment}
                        icon={!(expanded && running && !deployment.stopped)}
                        serviceStatus={serviceStatus}
                    />
                    {sha && <span className="type-mono-small">{sha}</span>}
                    {expanded && branchName(deployment.gitRef)}
                    {deployment.commitAuthor ?? deployment.actorName}
                    {compactAge(deployment.queuedAt)}
                    {!running &&
                        deployment.durationSeconds !== null &&
                        formatDuration(deployment.durationSeconds)}
                </MetaLine>
                {failure && (
                    <p className="truncate type-small text-status-error">
                        {failure}
                    </p>
                )}
            </div>
            <RowMenu
                deployment={deployment}
                live={live}
                canDeploy={canDeploy}
                onAction={onAction}
                rollbackOnHover={!expanded}
            />
            {/* Under the text column, inside the row's link: a click on
                the steps opens the deployment like the rest of the row. */}
            {expanded && (
                <div className="col-span-2 col-start-2 min-w-0">
                    {deployment.stopped ? (
                        <StoppedNote stopped={deployment.stopped} />
                    ) : serviceStatus && deployment.isActive ? (
                        <NotServingNote
                            status={serviceStatus}
                            remedy={remedy}
                        />
                    ) : (
                        <StepTrack
                            rows={stepRows({
                                deployment,
                                steps: expanded.steps,
                                expectsRelease: expanded.expectsRelease,
                            })}
                        />
                    )}
                </div>
            )}
        </li>
    );
}

/**
 * The steps of the expanded row: icon, name and time per step, 16 px
 * apart so a wrapped line starts clean ("✓ Build 1m 2s  ◌ Health check 12s"). The
 * running step ticks and is emphasised; waiting ones stay quiet.
 */
function StepTrack({ rows }: { rows: StepRow[] }) {
    if (rows.length === 0) {
        return null;
    }

    return (
        <ol
            aria-label="Steps"
            className="flex min-w-0 flex-wrap items-center gap-x-4 gap-y-1.5"
        >
            {rows.map((row) => (
                <li key={row.section} className="flex items-center type-small">
                    <span
                        className={cn(
                            'inline-flex items-center gap-1.5',
                            row.status === 'running'
                                ? 'font-medium text-foreground'
                                : row.status === 'failed'
                                  ? 'text-status-error'
                                  : row.status === 'succeeded'
                                    ? 'text-foreground'
                                    : 'text-muted-foreground',
                        )}
                    >
                        <StepIcon status={row.status} size={14} />
                        {TRACK_LABELS[row.section] ?? row.section}
                        <span className="sr-only">
                            ({STEP_STATUS_LABEL[row.status]})
                        </span>
                        {row.status === 'running' && row.startedAt ? (
                            <Elapsed
                                since={row.startedAt}
                                className="font-normal text-muted-foreground"
                            />
                        ) : (
                            row.seconds !== null &&
                            row.finishedAt !== null && (
                                <Duration
                                    seconds={row.seconds}
                                    className="text-muted-foreground"
                                />
                            )
                        )}
                    </span>
                </li>
            ))}
        </ol>
    );
}

/**
 * "Live deployment, not running: exited 2 hours ago." in place of the step
 * track while the live deployment's containers don't serve (D108), with
 * the fix beside it. Above the row's link, so the button stays clickable.
 */
export function NotServingNote({
    status,
    remedy,
}: {
    status: App.Data.Canvas.NodeStatusData;
    remedy: ReactNode;
}) {
    const now = useNow(true, 60_000);

    return (
        <div className="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-2">
            <p className="min-w-0 type-small text-muted-foreground">
                {notServingSentence(status, now)}
            </p>
            {remedy && <div className="relative z-10">{remedy}</div>}
        </div>
    );
}

export function RemedyButton({
    remedy,
    target,
}: {
    remedy: App.Enums.ServiceRemedy;
    target: Parameters<typeof runRemedy>[1];
}) {
    const Icon =
        remedy === 'restart'
            ? LIFECYCLE_ICON.restart
            : remedy === 'retry_deploy'
              ? LIFECYCLE_ICON.redeploy
              : LIFECYCLE_ICON.start;

    return (
        <Button
            variant="outline"
            size="sm"
            onClick={(event) => {
                event.preventDefault();
                runRemedy(remedy, target);
            }}
        >
            <Icon aria-hidden="true" />
            {vocabulary.ServiceRemedy[remedy].label}
        </Button>
    );
}

/** Who stopped the service and when, on its stopped row (D68). */
function StoppedNote({
    stopped,
}: {
    stopped: App.Data.Deployments.ServiceStoppedData;
}) {
    const now = useNow(true, 60_000);

    return (
        <p className="min-w-0 type-small text-muted-foreground">
            <time
                dateTime={stopped.at}
                title={new Date(stopped.at).toLocaleString()}
            >
                {stoppedLine(stopped, now)}
            </time>
            . Its domains show a stopped page until it starts again.
        </p>
    );
}

/**
 * Roll back on hover for compact rows (wide panels; phones use the menu)
 * and the `⋯` menu: open, roll back, redeploy this version.
 */
function RowMenu({
    deployment,
    live,
    canDeploy,
    onAction,
    rollbackOnHover,
}: {
    deployment: Summary;
    live: Summary | null;
    canDeploy: boolean;
    onAction: (action: PendingAction) => void;
    rollbackOnHover: boolean;
}) {
    const sha = shortSha(deployment.commitSha);

    if (!canDeploy) {
        return <span />;
    }

    return (
        <div className="relative z-10 flex shrink-0 items-start gap-1">
            {rollbackOnHover && deployment.canRollback && (
                <ConfirmPopover
                    title={`Roll back to ${sha ?? 'this deployment'}?`}
                    body={<RollbackRows current={live} target={deployment} />}
                    consequence="Traffic switches back within seconds. Database migrations are not reverted, and new pushes to production wait until you resume auto-deploy."
                    confirmLabel="Roll back"
                    onConfirm={() =>
                        router.post(
                            rollback(deploymentArgs(deployment)).url,
                            {},
                            {
                                preserveScroll: true,
                                optimistic: optimisticQueued(
                                    deployment.serviceId,
                                ),
                                ...rollbackToast('Couldn’t roll back.'),
                            },
                        )
                    }
                >
                    <Button
                        variant="outline"
                        size="sm"
                        className="hidden opacity-0 transition-opacity duration-fast group-hover:opacity-100 focus-visible:opacity-100 md:inline-flex"
                    >
                        Roll back
                    </Button>
                </ConfirmPopover>
            )}
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        variant="ghost"
                        size="icon-sm"
                        aria-label={`More actions for ${deploymentTitle(deployment)}`}
                    >
                        <MoreHorizontal />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuItem asChild>
                        <Link href={deploymentUrl(deployment)}>
                            Open deployment
                        </Link>
                    </DropdownMenuItem>
                    {deployment.canRollback && (
                        <DropdownMenuItem
                            onSelect={() =>
                                onAction({
                                    kind: 'rollback',
                                    deployment,
                                    current: live,
                                })
                            }
                        >
                            Roll back to this
                        </DropdownMenuItem>
                    )}
                    {!isInProgress(deployment.status) && (
                        <DropdownMenuItem
                            onSelect={() =>
                                onAction({ kind: 'redeploy', deployment })
                            }
                        >
                            Redeploy this version
                        </DropdownMenuItem>
                    )}
                    {isCancellable(deployment.status) && (
                        <DropdownMenuItem
                            variant="destructive"
                            onSelect={() =>
                                onAction({ kind: 'cancel', deployment })
                            }
                        >
                            Cancel deployment
                        </DropdownMenuItem>
                    )}
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    );
}

function ListSkeleton() {
    return (
        <div
            className="flex flex-col overflow-hidden rounded-xl border bg-card motion-safe:animate-skeleton-in"
            aria-busy="true"
            aria-label="Loading deployments"
        >
            {[0, 1, 2, 3].map((index) => (
                <div
                    key={index}
                    className={cn(
                        'flex gap-3 border-b px-4 last:border-b-0',
                        index === 0 ? 'py-4' : 'py-3',
                    )}
                >
                    <Skeleton className="size-6 rounded-full" />
                    <div className="flex flex-1 flex-col gap-2">
                        <Skeleton className="h-4 w-1/2" />
                        <Skeleton className="h-3 w-2/3" />
                        {index === 0 && <Skeleton className="mt-2 h-4 w-3/4" />}
                    </div>
                </div>
            ))}
        </div>
    );
}
