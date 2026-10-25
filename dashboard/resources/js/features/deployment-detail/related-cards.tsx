import { Link } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { Sparkline } from '@/components/charts/sparkline';
import { SheetSection, SidePanel } from '@/components/patterns/sheet';
import { TimeAgo } from '@/components/time-ago';
import { formatDuration, shortSha } from '@/features/deployments/format';
import { cn } from '@/lib/utils';
import { show as showDeployment } from '@/routes/deployments';
import { logs as projectLogs } from '@/routes/projects';

type Detail = App.Data.Deployments.DeploymentDetailData;

const cardClass =
    'group relative flex sm:min-h-[88px] min-w-0 flex-col gap-1 rounded-[14px] border bg-card p-4 text-left shadow-card transition-colors duration-fast hover:border-border-strong focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-signal';

function CardTitle({ children }: { children: ReactNode }) {
    return (
        <span className="flex items-center justify-between gap-2 type-label">
            {children}
            <ArrowUpRight
                aria-hidden="true"
                className="size-3.5 text-faint transition-colors duration-fast group-hover:text-foreground"
            />
        </span>
    );
}

function median(values: number[]): number | null {
    if (values.length === 0) {
        return null;
    }

    const sorted = [...values].sort((a, b) => a - b);
    const middle = Math.floor(sorted.length / 2);

    return sorted.length % 2 === 0
        ? Math.round((sorted[middle - 1] + sorted[middle]) / 2)
        : sorted[middle];
}

/**
 * Related features under the steps: runtime
 * logs of the service, the configuration it ran with (a sheet), compare
 * with the previous deployment, and the build history sparkline.
 */
export function RelatedCards({ detail }: { detail: Detail }) {
    const deployment = detail.deployment;
    const [config, setConfig] = useState(false);
    const builds = detail.buildHistory
        .map((entry) => entry.buildDurationMs)
        .filter((value): value is number => value !== null && value > 0)
        .map((ms) => Math.round(ms / 1000));
    const typical = median(builds);
    const previous = detail.previous;

    return (
        <section
            aria-label="Related"
            className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
        >
            <div className={cardClass}>
                <Link
                    href={
                        projectLogs(
                            {
                                project: deployment.projectId,
                                environment: deployment.environmentId,
                            },
                            { query: { service: deployment.serviceId } },
                        ).url
                    }
                    className="after:absolute after:inset-0 focus-visible:outline-none"
                >
                    <CardTitle>Runtime logs</CardTitle>
                </Link>
                <span className="type-small text-muted-foreground">
                    What {deployment.serviceName} prints while it runs
                </span>
            </div>

            <div className={cardClass}>
                <button
                    type="button"
                    onClick={() => setConfig(true)}
                    className="text-left after:absolute after:inset-0 focus-visible:outline-none"
                >
                    <CardTitle>Configuration</CardTitle>
                </button>
                <span className="type-small text-muted-foreground">
                    {detail.config.length > 0
                        ? `${detail.revision !== null ? `Revision ${detail.revision}, ` : ''}${detail.env.length} variable names`
                        : 'Resolved settings and variable names'}
                </span>
            </div>

            <div className={cn(cardClass, !previous && 'hover:border-border')}>
                {previous ? (
                    detail.compareUrl ? (
                        <a
                            href={detail.compareUrl}
                            target="_blank"
                            rel="noreferrer"
                            className="after:absolute after:inset-0 focus-visible:outline-none"
                        >
                            <CardTitle>Compare</CardTitle>
                        </a>
                    ) : (
                        <Link
                            href={
                                showDeployment({
                                    project: deployment.projectId,
                                    environment: deployment.environmentId,
                                    deployment: previous.id,
                                }).url
                            }
                            className="after:absolute after:inset-0 focus-visible:outline-none"
                        >
                            <CardTitle>Previous deployment</CardTitle>
                        </Link>
                    )
                ) : (
                    <span className="type-label">Compare</span>
                )}
                <span className="type-small text-muted-foreground">
                    {previous ? (
                        <>
                            Since{' '}
                            <span className="type-mono-small">
                                {shortSha(previous.commitSha) ??
                                    'the previous version'}
                            </span>
                            , <TimeAgo value={previous.queuedAt} />
                        </>
                    ) : (
                        'The first deployment of this service'
                    )}
                </span>
            </div>

            <div className={cn(cardClass, 'hover:border-border')}>
                <span className="flex items-baseline justify-between gap-2">
                    <span className="type-label">Build history</span>
                    {typical !== null && (
                        <span className="type-small text-muted-foreground tabular-nums">
                            typical {formatDuration(typical)}
                        </span>
                    )}
                </span>
                {builds.length >= 2 ? (
                    <Sparkline
                        values={builds}
                        label={`Build durations of the last ${builds.length} deployments, typical ${formatDuration(typical)}`}
                        height={36}
                        area
                        min={0}
                        className="mt-auto"
                    />
                ) : (
                    <span className="type-small text-muted-foreground">
                        {builds.length === 1
                            ? `One build so far: ${formatDuration(builds[0])}`
                            : 'Durations show here after a few builds'}
                    </span>
                )}
            </div>

            <ConfigurationSheet
                detail={detail}
                open={config}
                onOpenChange={setConfig}
            />
        </section>
    );
}

/**
 * The resolved configuration of the deployment and its env snapshot (names
 * and keyed hashes, never values), docs/06 §3.5.
 */
function ConfigurationSheet({
    detail,
    open,
    onOpenChange,
}: {
    detail: Detail;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const groups = new Map<
        string,
        App.Data.Deployments.ResolvedConfigEntryData[]
    >();

    for (const entry of detail.config) {
        groups.set(entry.group, [...(groups.get(entry.group) ?? []), entry]);
    }

    return (
        <SidePanel
            open={open}
            onOpenChange={onOpenChange}
            title="Configuration"
            description={
                detail.revision !== null
                    ? `What ${detail.deployment.serviceName} ran with: revision ${detail.revision}`
                    : `What ${detail.deployment.serviceName} ran with`
            }
            size="lg"
        >
            {detail.config.length === 0 ? (
                <SheetSection title="Settings">
                    <p className="type-body text-muted-foreground">
                        No configuration was recorded for this deployment.
                    </p>
                </SheetSection>
            ) : (
                [...groups.entries()].map(([group, entries]) => (
                    <SheetSection key={group} title={group}>
                        <dl className="flex flex-col divide-y">
                            {entries.map((entry) => (
                                <div
                                    key={entry.key}
                                    className="grid grid-cols-[minmax(0,2fr)_minmax(0,3fr)] gap-4 py-1.5"
                                >
                                    <dt className="truncate type-mono-small text-muted-foreground">
                                        {entry.key}
                                    </dt>
                                    <dd className="type-mono-small break-all">
                                        {entry.value}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    </SheetSection>
                ))
            )}
            <SheetSection
                title="Variables"
                description="Names only. The hash changes when a value changes."
            >
                <ul className="grid gap-x-6 sm:grid-cols-2">
                    {detail.env.map((entry) => (
                        <li
                            key={entry.name}
                            className="flex min-w-0 items-baseline justify-between gap-3 border-b py-1.5"
                        >
                            <span className="truncate type-mono-small">
                                {entry.name}
                            </span>
                            <span className="shrink-0 type-mono-small text-muted-foreground">
                                {entry.hash}
                            </span>
                        </li>
                    ))}
                </ul>
            </SheetSection>
        </SidePanel>
    );
}
