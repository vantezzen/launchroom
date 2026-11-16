import { Head, Link, router } from '@inertiajs/react';
import type { ReactNode } from 'react';
import {
    ArrowUpRight,
    ChevronDown,
    ChevronUp,
    RefreshCw,
    ScrollText,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { CommitAvatar } from '@/components/commit-avatar';
import { Logo } from '@/components/logo';
import { MetaLine } from '@/components/patterns/meta-line';
import { Page, PageHeader } from '@/components/patterns/page';
import { Section } from '@/components/patterns/section';
import { Button } from '@/components/ui/button';
import { ConfigFailureCard } from '@/features/config-as-code/config-failure-card';
import { FailureCard, fixAction } from '@/features/deployments/failure-card';
import type { QuotedLine } from '@/features/deployments/failure-card';
import { DeploymentImpact } from '@/features/deployments/deployment-impact';
import { OverviewCard } from '@/features/deployments/overview-card';
import {
    deploymentLogsHref,
    RelatedCards,
} from '@/features/deployments/related-cards';
import { stepRows } from '@/features/deployments/steps';
import type { PendingAction } from '@/features/deployments/deployment-actions';
import {
    DeploymentActionDialog,
    deploymentArgs,
} from '@/features/deployments/deployment-actions';
import { RollbackRows } from '@/features/deployments/rollback-rows';
import { ConfirmPopover } from '@/components/patterns/confirm-popover';
import {
    optimisticQueued,
    rollbackToast,
} from '@/features/service-lifecycle/optimistic';
import {
    deploymentTitle,
    isInProgress,
    startedBy,
} from '@/features/deployments/format';
import type { TimelineStep } from '@/features/logs/log-viewer';
import { LogViewer } from '@/features/logs/log-viewer';
import {
    failedStepErrors,
    groupBySection,
    normalizeLine,
    plain,
    withLoggedSteps,
} from '@/features/logs/step-log';
import { useDeploymentLogs } from '@/features/logs/use-deployment-logs';
import { liveReload } from '@/lib/live-reload';
import services from '@/routes/projects/services';
import { show as deploymentShow, rollback } from '@/routes/deployments';
import { TimeAgo } from '@/components/time-ago';
import { formatDuration, shortSha } from '@/lib/format';
import ProjectLayout from '@/features/app-shell/project-layout';
import { ChannelEvents } from '@/components/channel-events';
import { channelName } from '@/lib/realtime';

type Props = App.Data.Canvas.ProjectShellPropsData & {
    detail: App.Data.Deployments.DeploymentDetailData;
    logs: App.Data.Deployments.LogPageData;
    /** "Since this deploy" (docs/08 §12); null without traffic to compare. */
    impact?: App.Data.Deployments.DeploymentImpactData | null;
    /**
     * Opened from a deployment row before the server answered (instant
     * visit, features/deployments/deployment-link.tsx): `detail` holds what
     * the row knew, the lines are still coming.
     */
    pending?: boolean;
};

/**
 * Deployment detail (direction §9.7, flows §5–6): a beautiful technical
 * page. Header (logo, commit message, who and when, actions) → overview
 * card → explained failure → steps with their lines (the failed step opens
 * on the error, with search and follow) → related cards.
 * Everything in one `wide` container; the raw id sits in the overview
 * card's footer with a copy button.
 */
export default function DeploymentShow(props: Props) {
    // Inertia keeps this component mounted when it visits another
    // deployment (Redeploy and Roll back redirect to the new one): a key
    // per deployment resets the log stream, follow mode and open panels.
    // The answer to an instant visit replaces the drawn page the same way.
    return (
        <DeploymentPage
            key={`${props.detail.deployment.id}${props.pending ? ':pending' : ''}`}
            {...props}
        />
    );
}

function DeploymentPage({
    detail,
    logs,
    impact = null,
    project,
    environment,
    abilities,
    pending = false,
}: Props) {
    const deployment = detail.deployment;
    const [action, setAction] = useState<PendingAction | null>(null);
    const inProgress =
        isInProgress(deployment.status) && deployment.status !== 'ready';
    // A stopped service's live deployment runs nothing (D68): no runtime lines to follow.
    const live =
        inProgress ||
        (deployment.status === 'active' && deployment.stopped === null);
    const stream = useDeploymentLogs(
        {
            project: project.id,
            environment: deployment.environmentId,
            deployment: deployment.id,
        },
        logs,
    );
    const timeline = useMemo(
        (): TimelineStep[] =>
            // Running steps tick in their own header (Elapsed): the page
            // itself doesn't re-render every second.
            stepRows(detail).map((row) => ({
                section: row.section,
                status: row.status,
                duration: formatDuration(row.seconds),
                startedAt: row.startedAt,
                finishedAt: row.finishedAt,
                message: row.message,
            })),
        [detail],
    );
    // The failure card's "Jump to line N": the first error of the failed
    // step, quoted in the card with a line of context on each side.
    const { errorLine, excerpt } = useMemo(() => {
        const bySection = groupBySection(stream.lines.map(normalizeLine));
        const errors = failedStepErrors(
            withLoggedSteps(timeline, bySection, live),
            bySection,
        );
        const lines = errors.section
            ? (bySection.get(errors.section) ?? [])
            : [];
        const at = lines.findIndex((line) => line.number === errors.first);
        const quoted: QuotedLine[] =
            at === -1
                ? []
                : lines.slice(Math.max(0, at - 1), at + 2).map((line) => ({
                      number: line.number,
                      text: plain(line),
                  }));

        return { errorLine: errors.first, excerpt: quoted };
    }, [stream.lines, timeline, live]);
    const environmentName =
        environment?.id === deployment.environmentId
            ? environment.name
            : deployment.environmentName;
    const fix = fixAction(detail, abilities.deploy);

    const refreshDetail = () => liveReload(['detail']);

    const jumpTo = useCallback((line: number) => {
        document.getElementById('steps')?.scrollIntoView({ block: 'start' });
        window.location.hash = `L${line}`;
    }, []);

    // This deployment's runtime lines, from when it started (docs/08 §12).
    const logsHref = deploymentLogsHref(deployment);

    // One primary action, chosen by what will actually fix it (DPL-3):
    // promote a staged deployment, the known fix, a file to edit; Redeploy
    // is filled only for failures a retry can get past (timeouts, the
    // runtime, the infrastructure). A launchroom.json problem is only read
    // again on a push, so it has no Redeploy at all.
    const configProblem = detail.configErrors !== null;
    const configHref = detail.configErrors?.errors.find(
        (problem) => problem.href,
    )?.href;
    const primaryIsRedeploy =
        !detail.canPromote &&
        !fix &&
        deployment.status === 'failed' &&
        detail.failure !== null &&
        RETRYABLE.has(detail.failure.class);
    const neighbour = (
        target: App.Data.Deployments.DeploymentRefData | null,
    ) =>
        target
            ? deploymentShow({
                  project: deployment.projectId,
                  environment: deployment.environmentId,
                  deployment: target.id,
              }).url
            : null;
    const newerHref = neighbour(detail.newer);
    const olderHref = neighbour(detail.older);

    // `k` and `j` step to the newer and the older deployment (DPL-10).
    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            const target = event.target as HTMLElement | null;

            if (
                event.metaKey ||
                event.ctrlKey ||
                event.altKey ||
                target?.closest(
                    'input, textarea, select, [contenteditable="true"], [role="dialog"]',
                )
            ) {
                return;
            }

            const href =
                event.key === 'k'
                    ? newerHref
                    : event.key === 'j'
                      ? olderHref
                      : null;

            if (href) {
                event.preventDefault();
                router.visit(href);
            }
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [newerHref, olderHref]);

    return (
        <>
            <Head
                title={`Deployment ${shortSha(deployment.commitSha) ?? deployment.id} - ${deployment.serviceName}`}
            />

            {live && !pending && (
                <>
                    <ChannelEvents
                        channel={channelName('deployments', deployment.id)}
                        event="logs.appended"
                        pollMs={3_000}
                        onEvent={() => stream.nudge()}
                    />
                    <ChannelEvents
                        channel={channelName('deployments', deployment.id)}
                        event="deployment.updated"
                        pollMs={5_000}
                        onEvent={() => {
                            refreshDetail();
                            stream.nudge();
                        }}
                    />
                </>
            )}

            <Page>
                <PageHeader
                    media={
                        // An image service's logo is a container-tool mark:
                        // a commit's author says more (DPL-11).
                        detail.logo === 'docker' &&
                        deployment.commitSha !== null ? (
                            <CommitAvatar
                                name={
                                    deployment.commitAuthor ??
                                    deployment.actorName
                                }
                                avatarUrl={deployment.commitAuthorAvatarUrl}
                                size={24}
                                className="size-11 bg-hover text-sm font-medium"
                            />
                        ) : (
                            <Logo
                                kind={detail.logo}
                                name={deployment.serviceName}
                                size={44}
                            />
                        )
                    }
                    title={deploymentTitle(deployment)}
                    description={
                        <MetaLine>
                            {/* Back to the service: its panel over the canvas. */}
                            <Link
                                href={
                                    services.show({
                                        project: deployment.projectId,
                                        environment: deployment.environmentId,
                                        service: deployment.serviceId,
                                    }).url
                                }
                                className="rounded-sm text-foreground underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            >
                                {deployment.serviceName}
                            </Link>
                            {environmentName}
                            <span className="inline-flex items-center gap-1.5">
                                <CommitAvatar
                                    name={
                                        deployment.commitAuthor ??
                                        deployment.actorName
                                    }
                                    avatarUrl={deployment.commitAuthorAvatarUrl}
                                    size={16}
                                />
                                {startedBy(deployment)}
                            </span>
                            <TimeAgo value={deployment.queuedAt} />
                        </MetaLine>
                    }
                    actions={
                        <>
                            {(newerHref || olderHref) && (
                                <span className="inline-flex">
                                    <NeighbourButton
                                        href={newerHref}
                                        label="Newer deployment (k)"
                                        className="rounded-r-none"
                                    >
                                        <ChevronUp aria-hidden="true" />
                                    </NeighbourButton>
                                    <NeighbourButton
                                        href={olderHref}
                                        label="Older deployment (j)"
                                        className="-ml-px rounded-l-none"
                                    >
                                        <ChevronDown aria-hidden="true" />
                                    </NeighbourButton>
                                </span>
                            )}
                            {!configProblem && (
                                <Button variant="outline" asChild>
                                    <Link href={logsHref}>
                                        <ScrollText aria-hidden="true" />
                                        Runtime logs
                                    </Link>
                                </Button>
                            )}
                            {!pending && detail.canCancel && (
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        setAction({
                                            kind: 'cancel',
                                            deployment,
                                        })
                                    }
                                >
                                    Cancel
                                </Button>
                            )}
                            {!pending && deployment.canRollback && (
                                <ConfirmPopover
                                    title={`Roll back ${deployment.serviceName} to ${shortSha(deployment.commitSha) ?? 'this deployment'}?`}
                                    body={
                                        <RollbackRows
                                            current={detail.serving}
                                            target={deployment}
                                        />
                                    }
                                    consequence="Traffic switches back within seconds, without a rebuild. Database migrations are not reverted, and new pushes to production wait until you resume auto-deploy."
                                    confirmLabel="Roll back"
                                    onConfirm={() =>
                                        router.post(
                                            rollback(deploymentArgs(deployment))
                                                .url,
                                            {},
                                            {
                                                preserveScroll: true,
                                                optimistic: optimisticQueued(
                                                    deployment.serviceId,
                                                ),
                                                ...rollbackToast(
                                                    'Couldn’t roll back.',
                                                ),
                                            },
                                        )
                                    }
                                >
                                    <Button variant="outline">
                                        Roll back to this
                                    </Button>
                                </ConfirmPopover>
                            )}
                            {configHref && (
                                <Button asChild>
                                    <a
                                        href={configHref}
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        Open {detail.configErrors?.path}
                                        <ArrowUpRight aria-hidden="true" />
                                    </a>
                                </Button>
                            )}
                            {detail.canRedeploy && !configProblem && (
                                <Button
                                    variant={
                                        primaryIsRedeploy
                                            ? 'default'
                                            : 'outline'
                                    }
                                    onClick={() =>
                                        setAction({
                                            kind: 'redeploy',
                                            deployment,
                                        })
                                    }
                                >
                                    <RefreshCw aria-hidden="true" />
                                    Redeploy
                                </Button>
                            )}
                            {detail.canPromote && (
                                <Button
                                    onClick={() =>
                                        setAction({
                                            kind: 'promote',
                                            deployment,
                                        })
                                    }
                                >
                                    Promote
                                </Button>
                            )}
                        </>
                    }
                />

                {/* A failure leads with its explanation (DPL-2): the overview
                    follows with the facts. */}
                <div className="flex flex-col gap-4">
                    {pending ? null : detail.configErrors ? (
                        <ConfigFailureCard detail={detail} />
                    ) : (
                        <FailureCard
                            detail={detail}
                            errorLine={errorLine}
                            excerpt={excerpt}
                            onJump={jumpTo}
                            canFix={abilities.deploy}
                        />
                    )}
                    <OverviewCard
                        detail={detail}
                        environmentName={environmentName}
                        pending={pending}
                    />
                    {!pending && impact && (
                        <DeploymentImpact
                            impact={impact}
                            onRollback={(target) =>
                                setAction({
                                    kind: 'rollback',
                                    deployment: target,
                                    current: deployment,
                                })
                            }
                        />
                    )}
                </div>

                {/* Nothing ran for a launchroom.json problem: no steps. */}
                {!configProblem && (
                    <Section
                        id="steps"
                        title="Steps"
                        description={
                            deployment.status === 'ready'
                                ? 'Built and healthy. Promote to route traffic to it.'
                                : inProgress
                                  ? 'Updates as it runs.'
                                  : undefined
                        }
                    >
                        {pending ? (
                            // The lines stream in with the answer: the one
                            // thing on this page that is allowed to load.
                            <div
                                aria-busy="true"
                                aria-label="Loading lines"
                                className="min-h-60 rounded-xl border bg-card"
                            />
                        ) : (
                            <LogViewer
                                steps={timeline}
                                lines={stream.lines}
                                hasMore={stream.hasMore}
                                loadingMore={stream.loading}
                                onLoadMore={() => void stream.loadMore()}
                                onLoadAll={stream.loadAll}
                                live={live}
                                following={inProgress}
                                fileName={`${deployment.serviceName}-${deployment.id}.log`}
                            />
                        )}
                    </Section>
                )}

                {!pending && !configProblem && <RelatedCards detail={detail} />}
            </Page>

            <DeploymentActionDialog
                action={action}
                onClose={() => setAction(null)}
            />
        </>
    );
}

DeploymentShow.layout = ProjectLayout;

/** Failure classes a plain retry can get past (DPL-3). */
const RETRYABLE = new Set<App.Enums.DeploymentFailureClass>([
    'build_timeout',
    'transient',
    'runtime_error',
    'health_timeout',
    'placement_error',
]);

function NeighbourButton({
    href,
    label,
    className,
    children,
}: {
    href: string | null;
    label: string;
    className?: string;
    children: ReactNode;
}) {
    return href ? (
        <Button variant="outline" size="icon" className={className} asChild>
            <Link href={href} aria-label={label} title={label}>
                {children}
            </Link>
        </Button>
    ) : (
        <Button
            variant="outline"
            size="icon"
            className={className}
            disabled
            aria-label={label}
            title={label}
        >
            {children}
        </Button>
    );
}
