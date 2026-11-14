import { Head, Link } from '@inertiajs/react';
import { RefreshCw, ScrollText } from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';
import { CommitAvatar } from '@/components/commit-avatar';
import { Logo } from '@/components/logo';
import { MetaLine } from '@/components/patterns/meta-line';
import { Page, PageHeader } from '@/components/patterns/page';
import { Section } from '@/components/patterns/section';
import { Button } from '@/components/ui/button';
import { ConfigFailureCard } from '@/features/config-as-code/config-failure-card';
import {
    FailureCard,
    fixAction,
} from '@/features/deployment-detail/failure-card';
import { DeploymentImpact } from '@/features/deployment-detail/deployment-impact';
import { OverviewCard } from '@/features/deployment-detail/overview-card';
import {
    deploymentLogsHref,
    RelatedCards,
} from '@/features/deployment-detail/related-cards';
import { stepRows } from '@/features/deployment-detail/steps';
import type { PendingAction } from '@/features/deployments/deployment-actions';
import { DeploymentActionDialog } from '@/features/deployments/deployment-actions';
import {
    deploymentTitle,
    formatDuration,
    isInProgress,
    shortSha,
    startedBy,
} from '@/features/deployments/format';
import { ChannelEvents } from '@/features/deployments/realtime';
import type { TimelineStep } from '@/features/logs/log-viewer';
import { LogViewer } from '@/features/logs/log-viewer';
import {
    failedStepErrors,
    groupBySection,
    normalizeLine,
    withLoggedSteps,
} from '@/features/logs/step-log';
import { useDeploymentLogs } from '@/features/logs/use-deployment-logs';
import { liveReload } from '@/features/projects/environment-live-updates';
import type { ProjectShellProps } from '@/features/projects/types';
import services from '@/routes/projects/services';
import { TimeAgo } from '@/components/time-ago';

type Props = ProjectShellProps & {
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
    // The failure card's "Jump to line N": the first error of the failed step.
    const errorLine = useMemo(() => {
        const bySection = groupBySection(stream.lines.map(normalizeLine));

        return failedStepErrors(
            withLoggedSteps(timeline, bySection, live),
            bySection,
        ).first;
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

    // One primary action: promote a staged deployment, fix a known failure,
    // otherwise redeploy (filled only when retrying a failure).
    const primaryIsRedeploy =
        !detail.canPromote && !fix && deployment.status === 'failed';

    return (
        <>
            <Head
                title={`Deployment ${shortSha(deployment.commitSha) ?? deployment.id} - ${deployment.serviceName}`}
            />

            {live && !pending && (
                <>
                    <ChannelEvents
                        channel={`deployments.${deployment.id}`}
                        event=".logs.appended"
                        pollMs={3_000}
                        onEvent={() => stream.nudge()}
                    />
                    <ChannelEvents
                        channel={`deployments.${deployment.id}`}
                        event=".deployment.updated"
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
                        <Logo
                            kind={detail.logo}
                            name={deployment.serviceName}
                            size={44}
                        />
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
                            <Button variant="outline" asChild>
                                <Link href={logsHref}>
                                    <ScrollText aria-hidden="true" />
                                    Runtime logs
                                </Link>
                            </Button>
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
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        setAction({
                                            kind: 'rollback',
                                            deployment,
                                        })
                                    }
                                >
                                    Roll back to this
                                </Button>
                            )}
                            {detail.canRedeploy && (
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

                <div className="flex flex-col gap-4">
                    <OverviewCard
                        detail={detail}
                        environmentName={environmentName}
                        pending={pending}
                    />
                    {pending ? null : detail.configErrors ? (
                        <ConfigFailureCard detail={detail} />
                    ) : (
                        <FailureCard
                            detail={detail}
                            errorLine={errorLine}
                            onJump={jumpTo}
                            canFix={abilities.deploy}
                        />
                    )}
                    {!pending && impact && (
                        <DeploymentImpact
                            impact={impact}
                            onRollback={(target) =>
                                setAction({
                                    kind: 'rollback',
                                    deployment: target,
                                })
                            }
                        />
                    )}
                </div>

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

                {!pending && <RelatedCards detail={detail} />}
            </Page>

            <DeploymentActionDialog
                action={action}
                onClose={() => setAction(null)}
            />
        </>
    );
}
