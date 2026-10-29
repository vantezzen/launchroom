import { Link, router } from '@inertiajs/react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { MoreHorizontal, Rocket } from 'lucide-react';
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
import { compactAge } from '@/features/canvas/format';
import { stepRows } from '@/features/deployment-detail/steps';
import type { StepRow } from '@/features/deployment-detail/steps';
import { requestJson } from '@/features/projects/http';
import {
    DeployButton,
    StartButton,
} from '@/features/service-panel/service-controls';
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
    serviceDeploymentsUrl,
} from './deployment-actions';
import {
    branchName,
    deploymentStatusState,
    deploymentTitle,
    deploymentWord,
    formatDuration,
    isInProgress,
    shortSha,
    stoppedLine,
} from './format';
import { listHead, needsRetry } from './list-head';
import type { DeploymentUpdatedEvent } from './realtime';
import { ChannelEvents } from './realtime';

export type DeploymentsTabProps = {
    project: string;
    environment: string;
    service: string;
    /** A database: offers importing data before the first deploy (docs/14 §12.9). */
    database?: boolean;
    /** The panel header's actions (D68): Start on the stopped row. */
    controls?: App.Data.Canvas.ServiceControlsData | null;
};

type Summary = App.Data.Deployments.DeploymentSummaryData;

type ServiceDeployments = {
    active: Summary | null;
    deployments: Summary[];
    /** The expanded first row: in progress, else live, else the newest, with its steps. */
    current: {
        id: string;
        steps: App.Data.Deployments.DeploymentStepData[];
        /** Show "Release" from the start (docs/04 §6). */
        expectsRelease: boolean;
    } | null;
    canDeploy: boolean;
};

export function serviceDeploymentsKey(environment: string, service: string) {
    return ['service-deployments', environment, service] as const;
}

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
 * Service panel → Deployments (docs/08 §12, direction §9.5): one list,
 * newest first. The current deployment (the one in progress, else the live
 * one, else the newest: a failed first deploy with "Try again") is its
 * first row, expanded: the same avatar column, title and meta
 * line as every other row, plus the steps with live status and times and
 * its actions. The rest are compact rows with Roll back on hover. Live over
 * the environment channel.
 */
export function DeploymentsTab({
    project,
    environment,
    service,
    database = false,
    controls = null,
}: DeploymentsTabProps) {
    const queryClient = useQueryClient();
    const key = serviceDeploymentsKey(environment, service);
    const [action, setAction] = useState<PendingAction | null>(null);
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

    const { data, isPending, isError, error } = useQuery({
        queryKey: key,
        queryFn: ({ signal }) =>
            requestJson<ServiceDeployments>(
                'get',
                serviceDeploymentsUrl(project, environment, service),
                undefined,
                signal,
            ),
    });

    const refresh = (event: DeploymentUpdatedEvent | null) => {
        if (event === null || event.serviceId === service) {
            void queryClient.invalidateQueries({ queryKey: key });
        }
    };

    const head = data ? listHead(data) : null;
    const rest =
        data?.deployments.filter((deployment) => deployment.id !== head?.id) ??
        [];

    return (
        <div className="flex flex-col gap-6">
            <ChannelEvents<DeploymentUpdatedEvent>
                channel={`environments.${environment}`}
                event=".deployment.updated"
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
                <ol
                    aria-label="Deployments"
                    className="flex flex-col overflow-hidden rounded-xl border bg-card shadow-card"
                >
                    <DeploymentRow
                        deployment={head}
                        canDeploy={data.canDeploy}
                        onAction={setAction}
                        expanded={{
                            steps:
                                data.current?.id === head.id
                                    ? data.current.steps
                                    : [],
                            expectsRelease:
                                data.current?.id === head.id &&
                                data.current.expectsRelease,
                            actions: head.stopped ? (
                                <StoppedActions
                                    deployment={head}
                                    start={
                                        controls?.canControl &&
                                        controls.stopped ? (
                                            <StartButton
                                                project={project}
                                                environment={environment}
                                                service={service}
                                                name={head.serviceName}
                                                processes={controls.processes}
                                            />
                                        ) : null
                                    }
                                />
                            ) : (
                                <CurrentActions
                                    deployment={head}
                                    canDeploy={data.canDeploy}
                                    onAction={setAction}
                                    retry={
                                        needsRetry(head, data.active) &&
                                        data.canDeploy ? (
                                            controls?.canControl ? (
                                                <DeployButton
                                                    project={project}
                                                    environment={environment}
                                                    service={service}
                                                    name={head.serviceName}
                                                    staged={controls.staged}
                                                    versioned={
                                                        controls.versioned
                                                    }
                                                    label="Try again"
                                                />
                                            ) : (
                                                <Button
                                                    size="sm"
                                                    onClick={() =>
                                                        setAction({
                                                            kind: 'redeploy-service',
                                                            project,
                                                            environment,
                                                            service,
                                                            name: head.serviceName,
                                                        })
                                                    }
                                                >
                                                    Try again
                                                </Button>
                                            )
                                        ) : null
                                    }
                                    redeployService={() =>
                                        setAction({
                                            kind: 'redeploy-service',
                                            project,
                                            environment,
                                            service,
                                            name: head.serviceName,
                                        })
                                    }
                                />
                            ),
                        }}
                    />
                    {rest.map((deployment) => (
                        <DeploymentRow
                            key={deployment.id}
                            deployment={deployment}
                            canDeploy={data.canDeploy}
                            onAction={setAction}
                        />
                    ))}
                </ol>
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
}: {
    deployment: Summary;
    icon?: boolean;
}) {
    const running = isInProgress(deployment.status);
    const state = deploymentStatusState(deployment);

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
                {running ? deployment.statusLabel : deploymentWord(deployment)}
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
 * row) adds the steps and the actions under the text column.
 */
function DeploymentRow({
    deployment,
    canDeploy,
    onAction,
    expanded,
}: {
    deployment: Summary;
    canDeploy: boolean;
    onAction: (action: PendingAction) => void;
    expanded?: {
        steps: App.Data.Deployments.DeploymentStepData[];
        expectsRelease: boolean;
        actions: ReactNode;
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
                'border-b last:border-b-0',
                expanded ? 'gap-y-4 py-4' : 'py-3 hover:bg-hover',
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
                <Link
                    href={deploymentUrl(deployment)}
                    className="truncate type-body font-medium outline-none after:absolute after:inset-0 focus-visible:after:ring-2 focus-visible:after:ring-ring focus-visible:after:ring-inset"
                >
                    {deploymentTitle(deployment)}
                </Link>
                <MetaLine className="type-small text-muted-foreground tabular-nums">
                    <RowStatus
                        deployment={deployment}
                        icon={!(expanded && running && !deployment.stopped)}
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
                canDeploy={canDeploy}
                onAction={onAction}
                rollbackOnHover={!expanded}
            />
            {expanded && (
                <div className="relative z-10 col-span-2 col-start-2 flex flex-wrap items-center justify-between gap-x-4 gap-y-3">
                    {deployment.stopped ? (
                        <StoppedNote stopped={deployment.stopped} />
                    ) : (
                        <StepTrack
                            rows={stepRows({
                                deployment,
                                steps: expanded.steps,
                                expectsRelease: expanded.expectsRelease,
                            })}
                        />
                    )}
                    <div className="flex shrink-0 items-center gap-2">
                        {expanded.actions}
                    </div>
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

/** The stopped row's buttons: View logs and Start (it runs this version again). */
function StoppedActions({
    deployment,
    start,
}: {
    deployment: Summary;
    start: ReactNode;
}) {
    return (
        <>
            <Button variant="ghost" size="sm" asChild>
                <Link href={deploymentUrl(deployment)}>View logs</Link>
            </Button>
            {start}
        </>
    );
}

/**
 * The expanded row's buttons: Cancel and View logs while it runs, else
 * View logs and Redeploy; a failed or cancelled first deploy has View logs
 * and Try again (`retry`, the header's Deploy).
 */
function CurrentActions({
    deployment,
    canDeploy,
    onAction,
    redeployService,
    retry,
}: {
    deployment: Summary;
    canDeploy: boolean;
    onAction: (action: PendingAction) => void;
    redeployService: () => void;
    retry: ReactNode;
}) {
    const running = isInProgress(deployment.status);
    const cancellable =
        deployment.status === 'queued' ||
        deployment.status === 'building' ||
        deployment.status === 'ready';

    return (
        <>
            {running && canDeploy && cancellable && (
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => onAction({ kind: 'cancel', deployment })}
                >
                    Cancel
                </Button>
            )}
            <Button variant={running ? 'outline' : 'ghost'} size="sm" asChild>
                <Link href={deploymentUrl(deployment)}>View logs</Link>
            </Button>
            {retry ??
                (!running && canDeploy && (
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={redeployService}
                    >
                        Redeploy
                    </Button>
                ))}
        </>
    );
}

/**
 * Roll back on hover for compact rows (wide panels; phones use the menu)
 * and the `⋯` menu: open, roll back, redeploy this version.
 */
function RowMenu({
    deployment,
    canDeploy,
    onAction,
    rollbackOnHover,
}: {
    deployment: Summary;
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
                    consequence="Traffic switches back within seconds. Database migrations are not reverted, and new pushes to production wait until you resume auto-deploy."
                    confirmLabel="Roll back"
                    onConfirm={() =>
                        new Promise<void>((resolve) =>
                            router.post(
                                rollback(deploymentArgs(deployment)).url,
                                {},
                                {
                                    preserveScroll: true,
                                    onFinish: () => resolve(),
                                },
                            ),
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
                                onAction({ kind: 'rollback', deployment })
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
