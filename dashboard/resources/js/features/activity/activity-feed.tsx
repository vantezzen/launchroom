import { Link, router } from '@inertiajs/react';
import {
    ArrowRightLeft,
    BellRing,
    Copy,
    FolderPlus,
    History,
    PackagePlus,
    RefreshCw,
    Rocket,
    Server,
    Undo2,
    UserPlus,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useMemo } from 'react';
import { TimeRangeSelect } from '@/components/charts/time-range-select';
import type { TimeRangeOption } from '@/components/charts/time-range-select';
import { EmptyState } from '@/components/patterns/empty-state';
import { StatusIcon } from '@/components/status';
import { TimeAgo } from '@/components/time-ago';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';

type Entry = App.Data.Activity.ActivityEntryData;
export type ActivityKindFilter =
    | 'all'
    | 'deploys'
    | 'promotions'
    | 'changes'
    | 'team'
    | 'alerts';

const icons: Record<string, LucideIcon> = {
    deploy: Rocket,
    redeploy: RefreshCw,
    rollback: Undo2,
    promotion: ArrowRightLeft,
    created: Copy,
    service: PackagePlus,
    project: FolderPlus,
    server: Server,
    member: UserPlus,
    alert: BellRing,
};

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

    return date.toDateString() === yesterday.toDateString()
        ? 'Yesterday'
        : dayFormat.format(date);
}

function Item({ entry, showProject }: { entry: Entry; showProject: boolean }) {
    const Icon = icons[entry.kind] ?? History;
    // Alerts are things that happened, not something someone did.
    const sentence =
        entry.kind === 'alert' ? (
            <span className="text-foreground">{entry.title}</span>
        ) : (
            <>
                <span className="font-medium text-foreground">
                    {entry.actor ?? 'launchroom'}
                </span>{' '}
                {entry.title}
            </>
        );

    return (
        <li className="relative flex min-h-16 items-center gap-3.5 px-4 py-3 transition-colors duration-fast hover:bg-(--hover)/50">
            <span className="flex size-8 shrink-0 items-center justify-center rounded-full border bg-card text-muted-foreground">
                <Icon aria-hidden="true" className="size-4" />
            </span>
            <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                <p className="type-body text-muted-foreground">
                    {entry.href ? (
                        <Link
                            href={entry.href}
                            className="outline-none after:absolute after:inset-0 focus-visible:underline"
                        >
                            {sentence}
                        </Link>
                    ) : (
                        sentence
                    )}
                    {(entry.status === 'failed' ||
                        entry.status === 'deploying') && (
                        <span className="ml-2 inline-flex items-center gap-1 align-[-0.125em] type-small text-muted-foreground">
                            <StatusIcon
                                state={
                                    entry.status === 'failed'
                                        ? 'failed'
                                        : 'progress'
                                }
                            />
                            {entry.status === 'failed' ? 'Failed' : 'Deploying'}
                        </span>
                    )}
                    {entry.kind === 'alert' && (
                        <span className="ml-2 inline-flex items-center gap-1 align-[-0.125em] type-small text-muted-foreground">
                            <StatusIcon
                                state={
                                    entry.status === 'resolved'
                                        ? 'active'
                                        : entry.severity === 'critical'
                                          ? 'failed'
                                          : 'warning'
                                }
                            />
                            {entry.status === 'resolved' ? 'Resolved' : 'Open'}
                        </span>
                    )}
                </p>
                {(entry.detail || entry.sha) && (
                    <p className="flex min-w-0 items-baseline gap-2 type-small text-muted-foreground">
                        {entry.sha && (
                            <span className="shrink-0 type-mono-small">
                                {entry.sha}
                            </span>
                        )}
                        {entry.detail && (
                            <span className="truncate">{entry.detail}</span>
                        )}
                    </p>
                )}
            </div>
            <div className="flex shrink-0 flex-col items-end gap-0.5 type-small text-muted-foreground">
                <TimeAgo value={entry.at} />
                {showProject && entry.projectName && (
                    <span className="max-sm:hidden">{entry.projectName}</span>
                )}
            </div>
        </li>
    );
}

const KINDS: TimeRangeOption<ActivityKindFilter>[] = [
    { value: 'all', label: 'All' },
    { value: 'deploys', label: 'Deploys' },
    { value: 'promotions', label: 'Promotions' },
    { value: 'changes', label: 'Changes' },
    { value: 'team', label: 'Team' },
    { value: 'alerts', label: 'Alerts' },
];

/**
 * The activity feed (direction §5.4): items grouped by day, an icon per
 * kind, "**mara** deployed api and web to production" with the commit
 * underneath, and the age right. Only failures and running deploys carry a
 * status mark, so the page stays calm. Filters: kind (and project on the
 * team page); changing one is a GET visit, so the URL is shareable.
 */
export function ActivityFeed({
    entries,
    url,
    filters,
    projects,
    teamKinds = true,
}: {
    entries: Entry[];
    /** The page URL the filters apply to. */
    url: string;
    filters: { project: string | null; kind: string | null };
    /** Team page: the project filter's options (also shows the project per item). */
    projects?: App.Data.Canvas.ProjectLinkData[];
    teamKinds?: boolean;
}) {
    const days = useMemo(() => {
        const result: { label: string; entries: Entry[] }[] = [];

        for (const entry of entries) {
            const label = dayLabel(entry.at);
            const last = result.at(-1);

            if (last?.label === label) {
                last.entries.push(entry);
            } else {
                result.push({ label, entries: [entry] });
            }
        }

        return result;
    }, [entries]);

    const visit = (next: { project?: string | null; kind?: string | null }) => {
        const query = { ...filters, ...next };
        router.get(
            url,
            Object.fromEntries(
                Object.entries(query).filter(
                    ([, value]) => value !== null && value !== 'all',
                ),
            ),
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['entries', 'filters'],
            },
        );
    };

    return (
        <div className="flex flex-col gap-8">
            <div className="flex flex-wrap items-center gap-3">
                <TimeRangeSelect<ActivityKindFilter>
                    value={(filters.kind as ActivityKindFilter | null) ?? 'all'}
                    onChange={(kind) => visit({ kind })}
                    options={
                        teamKinds
                            ? KINDS
                            : KINDS.filter((kind) => kind.value !== 'team')
                    }
                    label="Kind"
                />
                {projects && projects.length > 1 && (
                    <Select
                        value={filters.project ?? 'all'}
                        onValueChange={(project) =>
                            visit({
                                project: project === 'all' ? null : project,
                            })
                        }
                    >
                        <SelectTrigger
                            size="sm"
                            className="h-9 w-auto min-w-40 gap-2"
                            aria-label="Project"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All projects</SelectItem>
                            {projects.map((project) => (
                                <SelectItem key={project.id} value={project.id}>
                                    {project.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                )}
            </div>

            {days.length === 0 ? (
                <EmptyState icon={History}>
                    {filters.kind || filters.project
                        ? 'Nothing like this happened recently.'
                        : 'Deploys, promotions and changes will show up here.'}
                </EmptyState>
            ) : (
                days.map((day) => (
                    <section
                        key={day.label}
                        aria-label={day.label}
                        className="flex flex-col gap-3"
                    >
                        <h2 className="type-label text-muted-foreground">
                            {day.label}
                        </h2>
                        <ol
                            className={cn(
                                'flex flex-col divide-y overflow-hidden rounded-[14px] border bg-card shadow-card',
                            )}
                        >
                            {day.entries.map((entry) => (
                                <Item
                                    key={entry.id}
                                    entry={entry}
                                    showProject={Boolean(projects)}
                                />
                            ))}
                        </ol>
                    </section>
                ))
            )}
        </div>
    );
}
