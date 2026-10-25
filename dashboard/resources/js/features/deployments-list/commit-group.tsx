import { Link } from '@inertiajs/react';
import { GitCommitHorizontal, MoreHorizontal } from 'lucide-react';
import { CommitAvatar } from '@/components/commit-avatar';
import { Duration } from '@/components/elapsed';
import { Logo } from '@/components/logo';
import { Status } from '@/components/status';
import { TimeAgo } from '@/components/time-ago';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { PendingAction } from '@/features/deployments/deployment-actions';
import { deploymentUrl } from '@/features/deployments/deployment-actions';
import {
    deploymentSeconds,
    deploymentState,
    deploymentTitle,
    deploymentWord,
    formatDuration,
    isInProgress,
    shortSha,
    startedBy,
} from '@/features/deployments/format';
import { useNow } from '@/hooks/use-now';
import { cn } from '@/lib/utils';

type Summary = App.Data.Deployments.DeploymentSummaryData;

export type CommitGroup = {
    key: string;
    deployments: Summary[];
};

/**
 * Consecutive deployments of one commit (or one config deploy) in one
 * environment form a group: "3b7d5e1 fix: order totals → api ✓, web ✓".
 */
export function groupByCommit(deployments: Summary[]): CommitGroup[] {
    const groups: CommitGroup[] = [];

    for (const deployment of deployments) {
        const key = `${deployment.commitSha ?? deployment.trigger}|${deployment.environmentId}|${deployment.trigger}`;
        const last = groups.at(-1);
        const head = last?.deployments[0];

        if (
            last &&
            head &&
            last.key === key &&
            Math.abs(
                Date.parse(head.queuedAt) - Date.parse(deployment.queuedAt),
            ) <
                15 * 60_000 &&
            !last.deployments.some(
                (item) => item.serviceId === deployment.serviceId,
            )
        ) {
            last.deployments.push(deployment);
        } else {
            groups.push({ key, deployments: [deployment] });
        }
    }

    return groups;
}

/**
 * Duration relative to the service's median: a quiet bar (neutral, never a
 * status colour) so a slow build stands out by length, not by alarm.
 */
function DurationBar({
    seconds,
    median,
}: {
    seconds: number | null;
    median: number | null;
}) {
    if (seconds === null || median === null || median <= 0) {
        return <span className="hidden w-16 sm:block" aria-hidden="true" />;
    }

    const share = Math.min(1, seconds / (median * 2));

    return (
        <span
            aria-hidden="true"
            title={`Median ${formatDuration(median)}`}
            className="relative hidden h-1 w-16 overflow-hidden rounded-full bg-(--hover) sm:block"
        >
            <span
                className="absolute inset-y-0 left-0 rounded-full bg-foreground/25"
                style={{ width: `${Math.max(6, share * 100)}%` }}
            />
            <span className="absolute inset-y-0 left-1/2 w-px bg-background" />
        </span>
    );
}

/**
 * The duration bar and number of one row. A running deployment ticks on
 * the page's shared clock; only this cell re-renders.
 */
function RowTiming({
    deployment,
    median,
}: {
    deployment: Summary;
    median: number | null;
}) {
    const running =
        deployment.durationSeconds === null &&
        deployment.startedAt !== null &&
        deployment.finishedAt === null;
    const now = useNow(running);
    const seconds = deploymentSeconds(deployment, now);

    return (
        <>
            <DurationBar
                seconds={deployment.status === 'failed' ? null : seconds}
                median={median}
            />
            <span
                className={cn(
                    'w-14 shrink-0 text-right type-small tabular-nums',
                    isInProgress(deployment.status)
                        ? 'text-foreground'
                        : 'text-muted-foreground',
                )}
            >
                <Duration seconds={seconds} />
            </span>
        </>
    );
}

function RowActions({
    deployment,
    onAction,
}: {
    deployment: Summary;
    onAction: (action: PendingAction) => void;
}) {
    const running =
        isInProgress(deployment.status) && deployment.status !== 'ready';

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon-sm"
                    className="relative z-10 shrink-0 text-muted-foreground"
                    aria-label={`Actions for ${deployment.serviceName} ${shortSha(deployment.commitSha) ?? deployment.id}`}
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
                <DropdownMenuSeparator />
                {deployment.status === 'ready' && (
                    <DropdownMenuItem
                        onSelect={() =>
                            onAction({ kind: 'promote', deployment })
                        }
                    >
                        Promote
                    </DropdownMenuItem>
                )}
                {!running && (
                    <DropdownMenuItem
                        onSelect={() =>
                            onAction({ kind: 'redeploy', deployment })
                        }
                    >
                        Redeploy
                    </DropdownMenuItem>
                )}
                {deployment.canRollback && (
                    <DropdownMenuItem
                        onSelect={() =>
                            onAction({ kind: 'rollback', deployment })
                        }
                    >
                        Roll back to this
                    </DropdownMenuItem>
                )}
                {(running || deployment.status === 'ready') && (
                    <DropdownMenuItem
                        onSelect={() =>
                            onAction({ kind: 'cancel', deployment })
                        }
                    >
                        Cancel deployment
                    </DropdownMenuItem>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/**
 * One commit's deployments as a calm card (direction §9.6): the commit on
 * top (message, sha, who, environment, age), then one row per service with
 * its status word, logo and name, the failure line, a duration bar against
 * the service's median and the duration.
 */
export function CommitGroupCard({
    group,
    logos,
    medians,
    showEnvironment,
    canDeploy,
    onAction,
}: {
    group: CommitGroup;
    logos: Map<string, string>;
    medians: Map<string, number | null>;
    showEnvironment: boolean;
    canDeploy: boolean;
    onAction: (action: PendingAction) => void;
}) {
    const head = group.deployments[0];

    return (
        <li className="overflow-hidden rounded-[14px] border bg-card shadow-card">
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 pt-3.5 pb-2.5">
                <span className="flex min-w-0 flex-1 items-center gap-2.5">
                    <CommitAvatar
                        name={head.commitAuthor ?? head.actorName}
                        avatarUrl={head.commitAuthorAvatarUrl}
                    />
                    <span className="min-w-0 truncate type-body font-medium">
                        {deploymentTitle(head)}
                    </span>
                </span>
                <span className="flex items-center gap-x-3 type-small text-muted-foreground">
                    {head.commitSha && (
                        <span className="inline-flex items-center gap-1 type-mono-small">
                            <GitCommitHorizontal
                                aria-hidden="true"
                                className="size-3.5"
                            />
                            {shortSha(head.commitSha)}
                        </span>
                    )}
                    <span>{startedBy(head)}</span>
                    {showEnvironment && <span>{head.environmentName}</span>}
                    <TimeAgo value={head.queuedAt} />
                </span>
            </div>
            <ul className="border-t">
                {group.deployments.map((deployment) => {
                    return (
                        <li
                            key={deployment.id}
                            className="group relative flex min-h-12 items-center gap-3 border-b px-4 py-2 transition-colors duration-fast last:border-b-0 hover:bg-(--hover)/50"
                        >
                            <span className="w-28 shrink-0 max-sm:w-auto">
                                <Status
                                    state={
                                        deployment.isActive
                                            ? 'active'
                                            : deploymentState(deployment.status)
                                    }
                                    label={deploymentWord(deployment)}
                                />
                            </span>
                            <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                                <Link
                                    href={deploymentUrl(deployment)}
                                    className="flex min-w-0 items-center gap-2 outline-none after:absolute after:inset-0 focus-visible:underline"
                                >
                                    <Logo
                                        kind={
                                            logos.get(deployment.serviceId) ??
                                            'app'
                                        }
                                        name={deployment.serviceName}
                                        size={20}
                                        decorative
                                    />
                                    <span className="truncate type-label">
                                        {deployment.serviceName}
                                    </span>
                                </Link>
                                {deployment.status === 'failed' &&
                                    deployment.failureSummary && (
                                        <span className="truncate pl-7 type-small text-muted-foreground">
                                            {deployment.failureSummary}
                                        </span>
                                    )}
                            </span>
                            <RowTiming
                                deployment={deployment}
                                median={
                                    medians.get(
                                        `${deployment.serviceId}|${deployment.environmentId}`,
                                    ) ?? null
                                }
                            />
                            {canDeploy ? (
                                <RowActions
                                    deployment={deployment}
                                    onAction={onAction}
                                />
                            ) : (
                                <span
                                    className="size-8 shrink-0"
                                    aria-hidden="true"
                                />
                            )}
                        </li>
                    );
                })}
            </ul>
        </li>
    );
}
