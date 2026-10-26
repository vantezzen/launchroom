import {
    Check,
    Copy,
    ExternalLink,
    GitBranch,
    GitCommitHorizontal,
    Globe,
} from 'lucide-react';
import { Duration, Elapsed } from '@/components/elapsed';
import { MetaLine } from '@/components/patterns/meta-line';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { Status } from '@/components/status';
import { TimeAgo } from '@/components/time-ago';
import {
    branchName,
    deploymentStatusState,
    deploymentWord,
    shortSha,
    firstLine,
    triggerLabel,
} from '@/features/deployments/format';
import { cn } from '@/lib/utils';
import { DeploymentStage } from './deployment-stage';

type Detail = App.Data.Deployments.DeploymentDetailData;

function Fact({
    label,
    children,
    className,
}: {
    label: string;
    children: ReactNode;
    className?: string;
}) {
    return (
        <div className={cn('flex min-w-0 flex-col gap-1.5', className)}>
            <dt className="type-small text-muted-foreground">{label}</dt>
            <dd className="min-w-0 type-body">{children}</dd>
        </div>
    );
}

export function CopyButton({
    value,
    label,
    className,
}: {
    value: string;
    label: string;
    className?: string;
}) {
    const [copied, setCopied] = useState(false);

    return (
        <button
            type="button"
            aria-label={label}
            title={label}
            onClick={() => {
                void navigator.clipboard?.writeText(value).then(() => {
                    setCopied(true);
                    window.setTimeout(() => setCopied(false), 1500);
                });
            }}
            className={cn(
                'inline-flex size-6 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors duration-fast hover:bg-hover hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-signal',
                className,
            )}
        >
            {copied ? (
                <Check aria-hidden="true" className="size-3.5" />
            ) : (
                <Copy aria-hidden="true" className="size-3.5" />
            )}
        </button>
    );
}

/**
 * Overview card (direction §9.7): the stage left (`DeploymentStage`),
 * facts right (status, duration, environment, built on; source; domains),
 * and a quiet footer with when, revision and the raw deployment id.
 */
export function OverviewCard({
    detail,
    environmentName,
}: {
    detail: Detail;
    environmentName: string;
}) {
    const deployment = detail.deployment;
    const branch = branchName(deployment.gitRef);
    const message = firstLine(deployment.commitMessage);

    return (
        <section
            aria-label="Overview"
            className="overflow-hidden rounded-[14px] border bg-card shadow-card"
        >
            <div className="grid gap-6 p-5 lg:grid-cols-[minmax(0,340px)_minmax(0,1fr)] lg:gap-8">
                <DeploymentStage detail={detail} />
                <dl className="grid min-w-0 grid-cols-2 content-start gap-x-6 gap-y-6 sm:grid-cols-4">
                    <Fact label="Status">
                        <span aria-live="polite">
                            <Status
                                state={deploymentStatusState(deployment)}
                                label={deploymentWord(deployment)}
                            />
                        </span>
                    </Fact>
                    <Fact label="Duration">
                        {deployment.durationSeconds !== null ? (
                            <Duration seconds={deployment.durationSeconds} />
                        ) : (
                            <Elapsed
                                since={deployment.startedAt}
                                until={deployment.finishedAt}
                                fallback={
                                    <span className="text-muted-foreground">
                                        Not started
                                    </span>
                                }
                            />
                        )}
                    </Fact>
                    <Fact label="Environment">{environmentName}</Fact>
                    <Fact label="Built on">
                        {detail.builtOn ?? (
                            <span className="text-muted-foreground">
                                {deployment.status === 'building'
                                    ? 'Waiting for a build server'
                                    : deployment.trigger === 'rollback' ||
                                        deployment.trigger === 'manual'
                                      ? 'Reused image'
                                      : 'No build'}
                            </span>
                        )}
                    </Fact>
                    <Fact label="Source" className="col-span-2 sm:col-span-4">
                        {deployment.commitSha ? (
                            <span className="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1">
                                {branch && (
                                    <span className="inline-flex items-center gap-1.5 type-mono">
                                        <GitBranch
                                            aria-hidden="true"
                                            className="size-3.5 text-muted-foreground"
                                        />
                                        {branch}
                                    </span>
                                )}
                                <span className="inline-flex min-w-0 items-center gap-1.5">
                                    <GitCommitHorizontal
                                        aria-hidden="true"
                                        className="size-3.5 shrink-0 text-muted-foreground"
                                    />
                                    <span className="type-mono">
                                        {shortSha(deployment.commitSha)}
                                    </span>
                                    {message && (
                                        <span className="truncate">
                                            {message}
                                        </span>
                                    )}
                                </span>
                            </span>
                        ) : (
                            <MetaLine className="text-muted-foreground">
                                {triggerLabel(deployment.trigger)}
                                {detail.revision !== null &&
                                    `revision ${detail.revision}`}
                            </MetaLine>
                        )}
                    </Fact>
                    <Fact
                        label={
                            deployment.status === 'failed'
                                ? 'Would have served'
                                : 'Domains'
                        }
                        className="col-span-2 sm:col-span-4"
                    >
                        {deployment.urls.length > 0 ? (
                            <ul className="flex flex-col gap-1">
                                {deployment.urls.map((url) => (
                                    <li key={url}>
                                        <a
                                            href={url}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="inline-flex max-w-full items-center gap-1.5 type-mono hover:underline"
                                        >
                                            <Globe
                                                aria-hidden="true"
                                                className="size-3.5 shrink-0 text-muted-foreground"
                                            />
                                            <span className="truncate">
                                                {url.replace(/^https:\/\//, '')}
                                            </span>
                                            <ExternalLink
                                                aria-hidden="true"
                                                className="size-3 shrink-0 text-muted-foreground"
                                            />
                                        </a>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <span className="text-muted-foreground">
                                Private: reachable from other services only
                            </span>
                        )}
                    </Fact>
                </dl>
            </div>
            <div className="flex flex-wrap items-center gap-x-4 gap-y-1 border-t bg-(--recessed) px-5 py-2.5 type-small text-muted-foreground">
                <span>
                    Created <TimeAgo value={deployment.queuedAt} />
                </span>
                {detail.revision !== null && (
                    <span>Revision {detail.revision}</span>
                )}
                <span className="ml-auto inline-flex items-center gap-1">
                    <span className="type-mono-small">{deployment.id}</span>
                    <CopyButton
                        value={deployment.id}
                        label="Copy deployment id"
                    />
                </span>
            </div>
        </section>
    );
}
