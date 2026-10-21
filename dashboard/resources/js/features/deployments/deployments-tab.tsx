import { CommitAvatar } from '@/components/commit-avatar';
import { Link, router } from '@inertiajs/react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { MoreHorizontal, Rocket } from 'lucide-react';
import { useState } from 'react';
import { DeploymentCard } from '@/components/cards/deployment-card';
import { ConfirmPopover } from '@/components/patterns/confirm-popover';
import { EmptyState } from '@/components/patterns/empty-state';
import { StatusIcon, StatusPill } from '@/components/status';
import type { StepStatus } from '@/components/steps/step-icon';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Skeleton } from '@/components/ui/skeleton';
import { compactAge } from '@/features/canvas/format';
import { requestJson } from '@/features/projects/http';
import { rollback } from '@/routes/deployments';
import { show as stagedChanges } from '@/routes/staged-changes';
import type { PendingAction } from './deployment-actions';
import {
    DeploymentActionDialog,
    deploymentArgs,
    deploymentUrl,
    serviceDeploymentsUrl,
} from './deployment-actions';
import {
    deploymentState,
    deploymentTitle,
    elapsedSeconds,
    formatDuration,
    isInProgress,
    shortSha,
} from './format';
import type { DeploymentUpdatedEvent } from './realtime';
import { ChannelEvents } from './realtime';

export type DeploymentsTabProps = {
    project: string;
    environment: string;
    service: string;
};

type Summary = App.Data.Deployments.DeploymentSummaryData;

type ServiceDeployments = {
    active: Summary | null;
    deployments: Summary[];
    canDeploy: boolean;
};

export function serviceDeploymentsKey(environment: string, service: string) {
    return ['service-deployments', environment, service] as const;
}

const STEPS = ['Build', 'Release', 'Roll out', 'Health', 'Route'] as const;

/** Where a deployment is, as step pills (the summary carries the status only). */
function stepsFor(status: App.Enums.DeploymentStatus) {
    const at: Partial<Record<App.Enums.DeploymentStatus, number>> = {
        queued: 0,
        building: 0,
        releasing: 1,
        rolling_out: 2,
        health_checking: 3,
        ready: 4,
        routing: 4,
    };
    const current = at[status] ?? STEPS.length;

    return STEPS.map((name, index) => ({
        id: name,
        name,
        status: (index < current
            ? 'succeeded'
            : index === current && status !== 'queued'
              ? 'running'
              : 'pending') as StepStatus,
    }));
}

/** The commit author's avatar beside a deployment card's title. */
function CardAvatar({ deployment }: { deployment: Summary }) {
    return (
        <CommitAvatar
            name={deployment.commitAuthor ?? deployment.actorName}
            avatarUrl={deployment.commitAuthorAvatarUrl}
            size={24}
        />
    );
}

function metaLine(deployment: Summary): string {
    return [
        shortSha(deployment.commitSha),
        deployment.gitRef?.replace(/^refs\/(heads|tags)\//, ''),
        deployment.commitAuthor ?? deployment.actorName,
        compactAge(deployment.queuedAt),
    ]
        .filter(Boolean)
        .join(' · ');
}

/**
 * Service panel → Deployments (direction §9.5): the deployment in
 * progress and the live one as cards with step pills and actions, then
 * "Earlier" as quiet rows with Roll back on hover (confirm popover). Live
 * over the environment channel.
 */
export function DeploymentsTab({
    project,
    environment,
    service,
}: DeploymentsTabProps) {
    const queryClient = useQueryClient();
    const key = serviceDeploymentsKey(environment, service);
    const [action, setAction] = useState<PendingAction | null>(null);

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

    const running = data?.deployments.find((deployment) =>
        isInProgress(deployment.status),
    );
    const earlier =
        data?.deployments.filter(
            (deployment) =>
                deployment.id !== running?.id &&
                deployment.id !== data.active?.id,
        ) ?? [];

    return (
        <div className="flex flex-col gap-8">
            <ChannelEvents<DeploymentUpdatedEvent>
                channel={`environments.${environment}`}
                event=".deployment.updated"
                onEvent={refresh}
            />

            {isPending && <TabSkeleton />}

            {isError && (
                <p className="type-body text-status-error">
                    Could not load deployments: {error.message}
                </p>
            )}

            {data && (
                <>
                    <div className="flex flex-col gap-3" aria-live="polite">
                        {running && (
                            <DeploymentCard
                                status={
                                    <StatusPill
                                        state="progress"
                                        label={running.statusLabel}
                                        detail={formatDuration(
                                            elapsedSeconds(
                                                running.startedAt ??
                                                    running.queuedAt,
                                                null,
                                            ),
                                        )}
                                    />
                                }
                                title={deploymentTitle(running)}
                                avatar={<CardAvatar deployment={running} />}
                                meta={metaLine(running)}
                                steps={stepsFor(running.status)}
                                actions={
                                    <>
                                        {data.canDeploy && (
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    setAction({
                                                        kind: 'cancel',
                                                        deployment: running,
                                                    })
                                                }
                                            >
                                                Cancel
                                            </Button>
                                        )}
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            asChild
                                        >
                                            <Link href={deploymentUrl(running)}>
                                                View logs
                                            </Link>
                                        </Button>
                                    </>
                                }
                            />
                        )}

                        {data.active ? (
                            <DeploymentCard
                                status={
                                    <StatusPill
                                        state="active"
                                        label="Live"
                                        detail={
                                            data.active.finishedAt
                                                ? compactAge(
                                                      data.active.finishedAt,
                                                  )
                                                : undefined
                                        }
                                    />
                                }
                                title={deploymentTitle(data.active)}
                                avatar={<CardAvatar deployment={data.active} />}
                                meta={
                                    <>
                                        {metaLine(data.active)}
                                        {data.active.durationSeconds !== null &&
                                            ` · took ${formatDuration(data.active.durationSeconds)}`}
                                    </>
                                }
                                className={
                                    running ? 'bg-transparent shadow-none' : ''
                                }
                                actions={
                                    <>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            asChild
                                        >
                                            <Link
                                                href={deploymentUrl(
                                                    data.active,
                                                )}
                                            >
                                                View logs
                                            </Link>
                                        </Button>
                                        {data.canDeploy && (
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    setAction({
                                                        kind: 'redeploy-service',
                                                        project,
                                                        environment,
                                                        service,
                                                        name:
                                                            data.active
                                                                ?.serviceName ??
                                                            'this service',
                                                    })
                                                }
                                            >
                                                Redeploy
                                            </Button>
                                        )}
                                    </>
                                }
                            />
                        ) : (
                            !running && (
                                <EmptyState
                                    icon={Rocket}
                                    action={
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
                                    }
                                >
                                    Not running here yet. Deploy the staged
                                    changes to start it.
                                </EmptyState>
                            )
                        )}
                    </div>

                    {earlier.length > 0 && (
                        <section className="flex flex-col gap-2">
                            <div className="flex items-baseline justify-between gap-3">
                                <h3 className="type-label text-muted-foreground">
                                    Earlier
                                </h3>
                                <p className="type-small text-muted-foreground">
                                    Rolls back in seconds, no rebuild
                                </p>
                            </div>
                            <ul className="flex flex-col">
                                {earlier.map((deployment) => (
                                    <HistoryRow
                                        key={deployment.id}
                                        deployment={deployment}
                                        canDeploy={data.canDeploy}
                                        onAction={setAction}
                                    />
                                ))}
                            </ul>
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
 * One earlier deployment: status icon, message, sha · age · duration, the
 * failure reason in red, and Roll back on hover (always visible on touch).
 */
function HistoryRow({
    deployment,
    canDeploy,
    onAction,
}: {
    deployment: Summary;
    canDeploy: boolean;
    onAction: (action: PendingAction) => void;
}) {
    const state = deploymentState(deployment.status);
    const duration = formatDuration(deployment.durationSeconds);
    const sha = shortSha(deployment.commitSha);

    return (
        <li className="group relative -mx-3 flex items-start gap-3 rounded-lg px-3 py-3 transition-colors duration-fast hover:bg-hover">
            <span className="flex h-5 items-center">
                <StatusIcon state={state} />
                <span className="sr-only">{deployment.statusLabel}</span>
            </span>
            <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                <Link
                    href={deploymentUrl(deployment)}
                    className="truncate type-body outline-none after:absolute after:inset-0 after:rounded-lg focus-visible:after:ring-2 focus-visible:after:ring-ring"
                >
                    {deploymentTitle(deployment)}
                </Link>
                <p className="flex min-w-0 items-center gap-1.5 type-small text-muted-foreground tabular-nums">
                    <CommitAvatar
                        name={deployment.commitAuthor ?? deployment.actorName}
                        avatarUrl={deployment.commitAuthorAvatarUrl}
                        size={16}
                    />
                    <span className="truncate">
                        {[
                            sha,
                            deployment.commitAuthor ?? deployment.actorName,
                            compactAge(deployment.queuedAt),
                            duration,
                            deployment.status === 'failed'
                                ? null
                                : deployment.statusLabel,
                        ]
                            .filter(Boolean)
                            .join(' · ')}
                    </span>
                </p>
                {deployment.status === 'failed' &&
                    deployment.failureSummary && (
                        <p className="truncate type-small text-status-error">
                            {deployment.failureSummary.split('\n')[0]}
                        </p>
                    )}
            </div>
            {canDeploy && (
                <div className="relative z-10 flex shrink-0 items-center gap-1">
                    {deployment.canRollback && (
                        <ConfirmPopover
                            title={`Roll back to ${sha ?? 'this deployment'}?`}
                            consequence="Traffic switches in seconds. New pushes to production wait until you resume auto-deploy; migrations are not reverted."
                            confirmLabel="Roll back"
                            onConfirm={() =>
                                new Promise<void>((resolve) =>
                                    router.post(
                                        rollback(deploymentArgs(deployment))
                                            .url,
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
                                className="opacity-100 transition-opacity duration-fast focus-visible:opacity-100 md:opacity-0 md:group-hover:opacity-100"
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
                            <DropdownMenuItem
                                onSelect={() =>
                                    onAction({ kind: 'redeploy', deployment })
                                }
                            >
                                Redeploy this version
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            )}
        </li>
    );
}

function TabSkeleton() {
    return (
        <div
            className="flex flex-col gap-4 motion-safe:animate-skeleton-in"
            aria-busy="true"
            aria-label="Loading deployments"
        >
            <Skeleton className="h-32 w-full rounded-[14px]" />
            <Skeleton className="h-12 w-full" />
            <Skeleton className="h-12 w-full" />
        </div>
    );
}
