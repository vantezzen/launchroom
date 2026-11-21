import { Link, router } from '@inertiajs/react';
import {
    ArrowUpRight,
    CircleArrowUp,
    CircleCheck,
    CircleX,
    Clock,
} from 'lucide-react';
import { useState } from 'react';
import { MetaLine } from '@/components/patterns/meta-line';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import update from '@/routes/projects/services/update';
import {
    availableLine,
    isOld,
    isOpen,
    kindLabel,
    publishedWords,
    runStep,
} from './format';
import type { ServiceUpdate, UpdateRun } from './format';
import { UpdateDialog } from './update-dialog';

/**
 * The service panel's update row (docs/04 §14.4), under the runtime facts:
 * the update running now with its step, the last one when it ended badly
 * (for a day) or well (for an hour), else the newest available version
 * with **Update**. Nothing when the service is current.
 */
export function UpdateStrip({ service }: { service: ServiceUpdate }) {
    const [open, setOpen] = useState(false);
    const run = service.run;

    if (run) {
        return (
            <>
                <RunRow
                    service={service}
                    run={run}
                    onRetry={() => setOpen(true)}
                />
                {service.canUpdate && service.recommended && (
                    <UpdateDialog
                        service={service}
                        open={open}
                        onOpenChange={setOpen}
                    />
                )}
            </>
        );
    }

    const candidate = service.recommended;

    if (!candidate) {
        return null;
    }

    const majorOnly = candidate.kind === 'major';
    const old = isOld(service.runningPublishedAt);
    const published = publishedWords(service.runningPublishedAt);

    return (
        <div className="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-lg bg-muted px-3 py-2 type-small text-muted-foreground">
            <CircleArrowUp
                aria-hidden="true"
                className="size-3.5 shrink-0 text-foreground"
            />
            <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                <p className="text-foreground">
                    {availableLine(candidate)}
                    {majorOnly && ', a major update'}
                </p>
                <MetaLine>
                    {!majorOnly && kindLabel(candidate.kind)}
                    {service.running && (
                        <span className="inline-flex items-center gap-1">
                            {old && (
                                <Clock
                                    aria-hidden="true"
                                    className="size-3 text-status-progress"
                                />
                            )}
                            {`You run ${service.running}${published ? `, ${published}` : ''}`}
                        </span>
                    )}
                    {!majorOnly && service.major && (
                        <span>{service.major.version} is out too</span>
                    )}
                    {service.releaseNotesUrl && (
                        <a
                            href={service.releaseNotesUrl}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="inline-flex items-center gap-0.5 hover:text-foreground hover:underline"
                        >
                            Release notes
                            <ArrowUpRight
                                aria-hidden="true"
                                className="size-3"
                            />
                        </a>
                    )}
                </MetaLine>
            </div>
            {service.canUpdate && (
                <>
                    <Button
                        size="sm"
                        variant="outline"
                        className="h-7"
                        onClick={() => setOpen(true)}
                    >
                        {majorOnly ? 'Review update' : 'Update'}
                    </Button>
                    <UpdateDialog
                        service={service}
                        open={open}
                        onOpenChange={setOpen}
                    />
                </>
            )}
        </div>
    );
}

/** An update in flight, or the last one with what happened. */
function RunRow({
    service,
    run,
    onRetry,
}: {
    service: ServiceUpdate;
    run: UpdateRun;
    onRetry: () => void;
}) {
    const running = isOpen(run);
    const failed = run.status === 'failed' || run.status === 'rolled_back';
    const route = {
        project: service.projectId,
        environment: service.environmentId,
        service: service.serviceId,
    };

    return (
        <div
            aria-live="polite"
            className="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-lg bg-muted px-3 py-2 type-small text-muted-foreground"
        >
            {running ? (
                <Spinner className="size-3.5 shrink-0 text-status-progress" />
            ) : failed ? (
                <CircleX
                    aria-hidden="true"
                    className="size-3.5 shrink-0 text-status-error"
                />
            ) : (
                <CircleCheck
                    aria-hidden="true"
                    className="size-3.5 shrink-0 text-status-ok"
                />
            )}
            <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                <p className="text-foreground">{runStep(run)}</p>
                {running && run.status === 'watching' && (
                    <p>
                        If it keeps crashing, launchroom rolls back to{' '}
                        {run.fromVersion}.
                    </p>
                )}
                {running && run.status === 'backing_up' && run.backupNote && (
                    <p>{run.backupNote}</p>
                )}
                {!running && run.message && <p>{run.message}</p>}
            </div>
            {run.deploymentHref && (
                <Link
                    href={run.deploymentHref}
                    className="shrink-0 hover:text-foreground hover:underline"
                >
                    {failed ? 'See why' : 'Deployment'}
                </Link>
            )}
            {failed && service.canUpdate && service.recommended && (
                <Button
                    size="sm"
                    variant="outline"
                    className="h-7"
                    onClick={onRetry}
                >
                    Try again
                </Button>
            )}
            {run.status === 'backing_up' && service.canUpdate && (
                <Button
                    size="sm"
                    variant="ghost"
                    className="h-7"
                    onClick={() =>
                        router.post(update.cancel(route).url, undefined, {
                            preserveScroll: true,
                        })
                    }
                >
                    Cancel
                </Button>
            )}
        </div>
    );
}
