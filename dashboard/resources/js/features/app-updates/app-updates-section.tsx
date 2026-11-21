import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from '@inertiajs/react';
import { ArrowRight, TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import { MetaLine } from '@/components/patterns/meta-line';
import { Section } from '@/components/patterns/section';
import { ServiceGlyph } from '@/components/service-glyph';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { requestJson } from '@/lib/http';
import { cn } from '@/lib/utils';
import { index as appUpdatesRoute } from '@/routes/app-updates';
import services from '@/routes/projects/services';
import {
    ageWords,
    isOld,
    isOpen,
    kindLabel,
    publishedWords,
    runStep,
} from './format';
import type { ServiceUpdate, UpdateCandidate } from './format';
import { UpdateDialog } from './update-dialog';

/** Rows shown before "Show N more". */
const ROWS = 5;

export const appUpdateKeys = {
    list: (project: string | null) => ['app-updates', project] as const,
};

/**
 * Projects → App updates (docs/04 §14.4): every image service of the team
 * with a newer release or an update in flight. Quiet by default: nothing
 * renders while every app is current, patch and minor updates are plain
 * rows, and only a major or a version more than a year old is marked and
 * listed first.
 */
export function AppUpdatesSection({
    project = null,
}: {
    project?: string | null;
}) {
    const client = useQueryClient();
    const key = appUpdateKeys.list(project);
    const { data } = useQuery({
        queryKey: key,
        queryFn: ({ signal }) =>
            requestJson<ServiceUpdate[]>(
                'get',
                appUpdatesRoute({ query: project ? { project } : {} }).url,
                undefined,
                signal,
            ),
        refetchInterval: 60_000,
        retry: false,
    });

    if (!data || data.length === 0) {
        return null;
    }

    return (
        <AppUpdatesList
            updates={data}
            onStarted={() => void client.invalidateQueries({ queryKey: key })}
        />
    );
}

/** The section with its data (the design gallery renders it with samples). */
export function AppUpdatesList({
    updates,
    onStarted,
}: {
    updates: ServiceUpdate[];
    onStarted?: () => void;
}) {
    const [expanded, setExpanded] = useState(false);
    const shown = expanded ? updates : updates.slice(0, ROWS);
    const hidden = updates.length - shown.length;
    const waiting = updates.filter(
        (update) => update.recommended && !(update.run && isOpen(update.run)),
    ).length;

    return (
        <Section
            id="app-updates"
            title="App updates"
            description={
                waiting === 0
                    ? 'Updating now.'
                    : `${waiting} ${waiting === 1 ? 'app has a newer version' : 'apps have newer versions'}. launchroom backs up first and watches each update.`
            }
        >
            <div className="overflow-hidden rounded-xl border bg-card shadow-card">
                <ul className="divide-y">
                    {shown.map((update) => (
                        <UpdateRow
                            key={`${update.serviceId}-${update.environmentId}`}
                            update={update}
                            onStarted={onStarted}
                        />
                    ))}
                </ul>
                {(hidden > 0 || expanded) && updates.length > ROWS && (
                    <button
                        type="button"
                        onClick={() => setExpanded((value) => !value)}
                        className="w-full border-t bg-recessed px-5 py-2.5 text-left type-small text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        {expanded ? 'Show fewer' : `Show ${hidden} more`}
                    </button>
                )}
            </div>
        </Section>
    );
}

function UpdateRow({
    update,
    onStarted,
}: {
    update: ServiceUpdate;
    onStarted?: () => void;
}) {
    const [open, setOpen] = useState(false);
    const candidate = update.recommended;
    const major = candidate?.kind === 'major';
    const run = update.run;
    const href = services.section({
        project: update.projectId,
        environment: update.environmentId,
        service: update.serviceId,
        section: 'source',
    }).url;
    const old = isOld(update.runningPublishedAt);

    const inFlight = run !== null && isOpen(run);

    return (
        // Phones: logo, name and the button on one row, the versions under the
        // name. Wider: the versions sit between the name and the button.
        <li className="grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-x-4 gap-y-1.5 px-5 py-3.5 sm:grid-cols-[auto_minmax(0,1fr)_auto_auto]">
            <ServiceGlyph
                logo={update.logo}
                size="md"
                className="row-span-2 self-start sm:row-span-1 sm:self-center"
            />
            <div className="flex min-w-0 flex-col gap-0.5">
                <Link
                    href={`${href}#updates`}
                    className="truncate type-body font-medium hover:underline"
                >
                    {update.serviceName}
                </Link>
                <MetaLine className="type-small text-muted-foreground">
                    {update.projectName}
                    {!update.production && update.environmentName}
                    {old && update.runningPublishedAt && (
                        <span className="inline-flex items-center gap-1 text-foreground">
                            <TriangleAlert
                                aria-hidden="true"
                                className="size-3 text-status-progress"
                            />
                            {`${update.running ?? 'Running version'} ${publishedWords(update.runningPublishedAt)}`}
                        </span>
                    )}
                </MetaLine>
            </div>
            <div className="col-start-2 row-start-2 flex min-w-0 flex-col items-start gap-0.5 sm:col-start-3 sm:row-start-1 sm:items-end">
                {run && inFlight ? (
                    <span className="inline-flex items-center gap-2 type-small text-muted-foreground">
                        <Spinner className="size-3.5 text-status-progress" />
                        {runStep(run)}
                    </span>
                ) : (
                    candidate && (
                        <>
                            <VersionArrow
                                update={update}
                                candidate={candidate}
                            />
                            <span
                                className={cn(
                                    'inline-flex items-center gap-1 type-small',
                                    major
                                        ? 'text-foreground'
                                        : 'text-muted-foreground',
                                )}
                            >
                                {major && (
                                    <TriangleAlert
                                        aria-hidden="true"
                                        className="size-3 text-status-progress"
                                    />
                                )}
                                {kindLabel(candidate.kind)}
                                {update.major &&
                                    !major &&
                                    `, ${update.major.version} is out too`}
                            </span>
                        </>
                    )
                )}
            </div>
            <div className="col-start-3 row-start-1 sm:col-start-4">
                {!inFlight && candidate && update.canUpdate && (
                    <>
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => setOpen(true)}
                        >
                            {major ? 'Review' : 'Update'}
                        </Button>
                        <UpdateDialog
                            service={update}
                            open={open}
                            onOpenChange={setOpen}
                            onStarted={onStarted}
                        />
                    </>
                )}
            </div>
        </li>
    );
}

/**
 * "6.2.0 → 6.3.0"; a moving tag says how old its image is instead of a
 * version it can't name: "latest (built 8 months ago) → 1.31.9".
 */
function VersionArrow({
    update,
    candidate,
}: {
    update: ServiceUpdate;
    candidate: UpdateCandidate;
}) {
    const target =
        candidate.kind === 'build' && candidate.version === candidate.tag
            ? 'a newer build'
            : candidate.version;

    return (
        <span className="inline-flex flex-wrap items-center gap-1.5 type-mono">
            {update.running && (
                <>
                    <span className="text-muted-foreground">
                        {update.running}
                    </span>
                    {update.moving && update.runningPublishedAt && (
                        <span className="font-sans type-small text-muted-foreground">
                            (built {ageWords(update.runningPublishedAt)})
                        </span>
                    )}
                    <ArrowRight
                        aria-label="to"
                        className="size-3.5 text-muted-foreground"
                    />
                </>
            )}
            <span
                className={cn(
                    'font-medium',
                    target === 'a newer build' && 'font-sans type-body',
                )}
            >
                {target}
            </span>
        </span>
    );
}
