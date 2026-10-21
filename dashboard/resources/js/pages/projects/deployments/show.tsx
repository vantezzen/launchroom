import { Head, Link, router } from '@inertiajs/react';
import { ChevronRight, ScrollText } from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';
import { CommitAvatar } from '@/components/commit-avatar';
import { Logo } from '@/components/logo';
import { Page, PageHeader } from '@/components/patterns/page';
import { Section } from '@/components/patterns/section';
import { StepList } from '@/components/steps';
import { Button } from '@/components/ui/button';
import { ConfigFailureCard } from '@/features/config-as-code/config-failure-card';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    FailureCard,
    fixAction,
} from '@/features/deployment-detail/failure-card';
import { OverviewCard } from '@/features/deployment-detail/overview-card';
import { RelatedCards } from '@/features/deployment-detail/related-cards';
import {
    stepRows,
    useDeploymentSteps,
} from '@/features/deployment-detail/steps';
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
import { useDeploymentLogs } from '@/features/logs/use-deployment-logs';
import type { ProjectShellProps } from '@/features/projects/types';
import { logs as projectLogs } from '@/routes/projects';
import { TimeAgo } from '@/components/time-ago';

type Props = ProjectShellProps & {
    detail: App.Data.Deployments.DeploymentDetailData;
    logs: App.Data.Deployments.LogPageData;
};

/**
 * Deployment detail (direction §9.7, flows §5–6): a beautiful technical
 * page. Header (logo, commit message, who and when, actions) → overview
 * card → explained failure → steps with their lines (the failed step opens
 * on the error) → related cards → the full log with search and follow.
 * Everything in one `wide` container; the raw id sits in the overview
 * card's footer with a copy button.
 */
export default function DeploymentShow({
    detail,
    logs,
    project,
    environment,
    abilities,
}: Props) {
    const deployment = detail.deployment;
    const [action, setAction] = useState<PendingAction | null>(null);
    const inProgress =
        isInProgress(deployment.status) && deployment.status !== 'ready';
    const live = inProgress || deployment.status === 'active';
    const [showLog, setShowLog] = useState(inProgress);
    const stream = useDeploymentLogs(
        {
            project: project.id,
            environment: deployment.environmentId,
            deployment: deployment.id,
        },
        logs,
    );
    const { steps, errorLine } = useDeploymentSteps(detail, stream.lines);
    const timeline = useMemo(
        (): TimelineStep[] =>
            stepRows(detail).map((row) => ({
                section: row.section,
                status: row.status === 'canceled' ? 'skipped' : row.status,
                duration: formatDuration(row.seconds),
                message: row.message,
            })),
        [detail],
    );
    const environmentName =
        environment?.id === deployment.environmentId
            ? environment.name
            : deployment.environmentName;
    const fix = fixAction(detail, abilities.deploy);

    const refreshDetail = () => router.reload({ only: ['detail'] });

    const jumpTo = useCallback((line: number) => {
        setShowLog(true);
        window.setTimeout(() => {
            document
                .getElementById('full-log')
                ?.scrollIntoView({ block: 'start' });
            window.location.hash = `L${line}`;
        }, 50);
    }, []);

    const logsHref = projectLogs(
        { project: project.id, environment: deployment.environmentId },
        { query: { service: deployment.serviceId } },
    ).url;

    // One primary action: promote a staged deployment, fix a known failure,
    // otherwise redeploy (filled only when retrying a failure).
    const primaryIsRedeploy =
        !detail.canPromote && !fix && deployment.status === 'failed';

    return (
        <>
            <Head
                title={`${deployment.serviceName} · ${shortSha(deployment.commitSha) ?? deployment.id}`}
            />

            {live && (
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
                        <span className="inline-flex flex-wrap items-center gap-x-1.5">
                            <span>
                                {deployment.serviceName} · {environmentName} ·
                            </span>
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
                        </span>
                    }
                    actions={
                        <>
                            <Button variant="outline" asChild>
                                <Link href={logsHref}>
                                    <ScrollText aria-hidden="true" />
                                    Logs
                                </Link>
                            </Button>
                            {detail.canCancel && (
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
                            {deployment.canRollback && (
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
                    />
                    {detail.configErrors ? (
                        <ConfigFailureCard detail={detail} />
                    ) : (
                        <FailureCard
                            detail={detail}
                            errorLine={errorLine}
                            onJump={jumpTo}
                            canFix={abilities.deploy}
                        />
                    )}
                </div>

                <Section
                    title="Steps"
                    description={
                        deployment.status === 'ready'
                            ? 'Built and healthy. Promote to route traffic to it.'
                            : inProgress
                              ? 'Updates as it runs.'
                              : undefined
                    }
                >
                    <StepList key={deployment.status} steps={steps} />
                </Section>

                <RelatedCards detail={detail} />

                <Collapsible
                    open={showLog}
                    onOpenChange={setShowLog}
                    className="flex flex-col gap-3.5"
                >
                    <CollapsibleTrigger className="group flex items-center gap-1.5 self-start rounded-md type-heading focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-signal">
                        <ChevronRight
                            aria-hidden="true"
                            className="size-4 text-muted-foreground transition-transform duration-fast group-data-[state=open]:rotate-90"
                        />
                        Full log
                        <span className="type-body font-normal text-muted-foreground tabular-nums">
                            {stream.lines.length}{' '}
                            {stream.lines.length === 1 ? 'line' : 'lines'}
                            {stream.hasMore && '+'}
                        </span>
                    </CollapsibleTrigger>
                    <CollapsibleContent>
                        <section
                            id="full-log"
                            aria-label="Full log"
                            className="flex h-[70vh] min-h-[420px] flex-col overflow-hidden rounded-[14px] border bg-card"
                        >
                            <LogViewer
                                steps={timeline}
                                lines={stream.lines}
                                hasMore={stream.hasMore}
                                loadingMore={stream.loading}
                                onLoadMore={() => void stream.loadMore()}
                                live={live}
                                following={inProgress}
                                className="flex-1"
                            />
                        </section>
                    </CollapsibleContent>
                </Collapsible>
            </Page>

            <DeploymentActionDialog
                action={action}
                onClose={() => setAction(null)}
            />
        </>
    );
}
