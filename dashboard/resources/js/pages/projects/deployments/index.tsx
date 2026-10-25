import { Head, Link, router } from '@inertiajs/react';
import { Rocket } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import { EmptyState } from '@/components/patterns/empty-state';
import { MetaLine } from '@/components/patterns/meta-line';
import { Page, PageHeader } from '@/components/patterns/page';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { PendingAction } from '@/features/deployments/deployment-actions';
import { DeploymentActionDialog } from '@/features/deployments/deployment-actions';
import { formatDuration } from '@/features/deployments/format';
import { ChannelEvents } from '@/features/deployments/realtime';
import {
    CommitGroupCard,
    groupByCommit,
} from '@/features/deployments-list/commit-group';
import type { CommitGroup } from '@/features/deployments-list/commit-group';
import type { ProjectShellProps } from '@/features/projects/types';
import { project as projectDeployments } from '@/routes/deployments';
import { canvas } from '@/routes/projects';

type Summary = App.Data.Deployments.DeploymentSummaryData;

type Props = ProjectShellProps & {
    deployments: Summary[];
    stats: App.Data.Deployments.DeploymentStatsData;
    environmentOptions: App.Data.Variables.EnvironmentOptionData[];
    serviceOptions: { id: string; name: string; logo?: string }[];
    filters: {
        environment: string | null;
        service: string | null;
        status: string | null;
    };
};

const ALL = 'all';

const statuses = [
    { value: 'in_progress', label: 'Deploying' },
    { value: 'queued', label: 'Queued' },
    { value: 'ready', label: 'Ready to promote' },
    { value: 'active', label: 'Live' },
    { value: 'failed', label: 'Failed' },
    { value: 'superseded', label: 'Superseded' },
    { value: 'cancelled', label: 'Cancelled' },
];

const dayFormat = new Intl.DateTimeFormat('en-GB', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
});

function dayLabel(iso: string): string {
    const date = new Date(iso);
    const today = new Date();
    const yesterday = new Date();
    yesterday.setDate(today.getDate() - 1);

    if (date.toDateString() === today.toDateString()) {
        return 'Today';
    }

    if (date.toDateString() === yesterday.toDateString()) {
        return 'Yesterday';
    }

    return dayFormat.format(date);
}

function FilterSelect({
    label,
    all,
    value,
    options,
    onChange,
}: {
    label: string;
    all: string;
    value: string | null;
    options: { value: string; label: string }[];
    onChange: (value: string | null) => void;
}) {
    return (
        <Select
            value={value ?? ALL}
            onValueChange={(next) => onChange(next === ALL ? null : next)}
        >
            <SelectTrigger
                size="sm"
                className="h-8 w-auto min-w-36 gap-2"
                aria-label={label}
            >
                <SelectValue />
            </SelectTrigger>
            <SelectContent align="end">
                <SelectItem value={ALL}>{all}</SelectItem>
                {options.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                        {option.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

/**
 * Project deployments (direction §9.6): one-line stats under the title,
 * compact filters right, then the deployments by day; each commit is a
 * calm card with one row per service (status, logo, duration against the
 * service median). Realtime: every watched environment's
 * `.deployment.updated` reloads the list (polling without Reverb).
 */
export default function ProjectDeployments({
    project,
    deployments,
    stats,
    environmentOptions,
    serviceOptions,
    filters,
    abilities,
}: Props) {
    const [action, setAction] = useState<PendingAction | null>(null);
    const reloadTimer = useRef<number | null>(null);
    const days = useMemo(() => {
        const result: { label: string; groups: CommitGroup[] }[] = [];

        // Newest first by when they started (ids follow creation, not time).
        const ordered = [...deployments].sort(
            (a, b) => Date.parse(b.queuedAt) - Date.parse(a.queuedAt),
        );

        for (const group of groupByCommit(ordered)) {
            const label = dayLabel(group.deployments[0].queuedAt);
            const last = result.at(-1);

            if (last && last.label === label) {
                last.groups.push(group);
            } else {
                result.push({ label, groups: [group] });
            }
        }

        return result;
    }, [deployments]);
    const logos = useMemo(
        () =>
            new Map(
                serviceOptions.map((option) => [
                    option.id,
                    option.logo ?? 'app',
                ]),
            ),
        [serviceOptions],
    );
    const medians = useMemo(
        () =>
            new Map(
                stats.services.map((entry) => [
                    `${entry.serviceId}|${entry.environmentId}`,
                    entry.medianDurationSeconds,
                ]),
            ),
        [stats.services],
    );

    const filter = (next: Partial<Props['filters']>) => {
        const query = { ...filters, ...next };
        router.get(
            projectDeployments(project.id).url,
            Object.fromEntries(
                Object.entries(query).filter(([, value]) => value !== null),
            ),
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['deployments', 'filters', 'environment', 'stats'],
            },
        );
    };

    const reload = () => {
        if (reloadTimer.current !== null) {
            return;
        }

        reloadTimer.current = window.setTimeout(() => {
            reloadTimer.current = null;
            router.reload({ only: ['deployments', 'stats'] });
        }, 400);
    };

    const watched = filters.environment
        ? [filters.environment]
        : environmentOptions.map((option) => option.id);
    const filtered = Boolean(
        filters.environment || filters.service || filters.status,
    );
    const summary = (
        <MetaLine>
            {`${stats.weekTotal} ${stats.weekTotal === 1 ? 'deploy' : 'deploys'} this week`}
            {stats.weekFailed > 0 && `${stats.weekFailed} failed`}
            {stats.medianDurationSeconds !== null &&
                `Median ${formatDuration(stats.medianDurationSeconds)}`}
        </MetaLine>
    );

    return (
        <>
            <Head title={`Deployments - ${project.name}`} />
            {watched.map((environment) => (
                <ChannelEvents
                    key={environment}
                    channel={`environments.${environment}`}
                    event=".deployment.updated"
                    pollMs={15_000}
                    onEvent={reload}
                />
            ))}

            <Page>
                <PageHeader
                    title="Deployments"
                    description={
                        stats.weekTotal > 0
                            ? summary
                            : 'Every push, redeploy, rollback and config change.'
                    }
                    actions={
                        <>
                            <FilterSelect
                                label="Environment"
                                all="All environments"
                                value={filters.environment}
                                options={environmentOptions.map((option) => ({
                                    value: option.id,
                                    label: option.name,
                                }))}
                                onChange={(environment) =>
                                    filter({ environment })
                                }
                            />
                            <FilterSelect
                                label="Service"
                                all="All services"
                                value={filters.service}
                                options={serviceOptions.map((option) => ({
                                    value: option.id,
                                    label: option.name,
                                }))}
                                onChange={(service) => filter({ service })}
                            />
                            <FilterSelect
                                label="Status"
                                all="Any status"
                                value={filters.status}
                                options={statuses}
                                onChange={(status) => filter({ status })}
                            />
                        </>
                    }
                />

                {days.length === 0 ? (
                    filtered ? (
                        <EmptyState
                            icon={Rocket}
                            action={
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        filter({
                                            environment: null,
                                            service: null,
                                            status: null,
                                        })
                                    }
                                >
                                    Clear filters
                                </Button>
                            }
                        >
                            No deployments match these filters.
                        </EmptyState>
                    ) : (
                        <EmptyState
                            icon={Rocket}
                            action={
                                environmentOptions[0] && (
                                    <Button variant="outline" asChild>
                                        <Link
                                            href={
                                                canvas([
                                                    project.id,
                                                    environmentOptions[0].id,
                                                ]).url
                                            }
                                        >
                                            Open the canvas
                                        </Link>
                                    </Button>
                                )
                            }
                        >
                            No deployments yet. Push to main or press Deploy.
                        </EmptyState>
                    )
                ) : (
                    <div className="flex flex-col gap-8">
                        {days.map((day) => (
                            <section
                                key={day.label}
                                aria-label={day.label}
                                className="flex flex-col gap-3"
                            >
                                <h2 className="type-label text-muted-foreground">
                                    {day.label}
                                </h2>
                                <ol className="flex flex-col gap-3">
                                    {day.groups.map((group) => (
                                        <CommitGroupCard
                                            key={`${group.key}-${group.deployments[0].id}`}
                                            group={group}
                                            logos={logos}
                                            medians={medians}
                                            showEnvironment={
                                                !filters.environment &&
                                                environmentOptions.length > 1
                                            }
                                            canDeploy={abilities.deploy}
                                            onAction={setAction}
                                        />
                                    ))}
                                </ol>
                            </section>
                        ))}
                    </div>
                )}
            </Page>

            <DeploymentActionDialog
                action={action}
                onClose={() => setAction(null)}
            />
        </>
    );
}
