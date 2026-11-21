import { Link } from '@inertiajs/react';
import { ArrowLeft, RotateCcw, Square } from 'lucide-react';
import { useState } from 'react';
import { Callout } from '@/components/patterns/callout';
import { ConfirmPopover } from '@/components/patterns/confirm-popover';
import { MetaLine } from '@/components/patterns/meta-line';
import { Elapsed } from '@/components/elapsed';
import { StatusPill } from '@/components/status';
import { TimeAgo } from '@/components/time-ago';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { show as deploymentShow } from '@/routes/deployments';
import { cancelRun } from './api';
import type { Run, ServiceRef, TabData } from './api';
import { formatArgv } from './argv';
import { CommandOutput } from './command-output';
import type { RunRequest } from './commands-tab';
import { outcome, ranBy, runStatus, timeoutLabel } from './format';
import { useRun } from './use-run';

/**
 * One run (docs/08 §22): the argv as the title, the status with how long
 * it ran, who and where, Cancel while it is open and Run again after; then
 * the output, followed while it runs.
 */
export function RunDetail({
    id,
    initial,
    data,
    serviceRef,
    onBack,
    onRunAgain,
    onChanged,
}: {
    id: string;
    initial: Run | null;
    data: TabData;
    serviceRef?: ServiceRef;
    onBack: () => void;
    onRunAgain: (request: RunRequest, timeoutSeconds: number) => Promise<void>;
    onChanged: () => void;
}) {
    const { run, lines, error, setRun } = useRun(id, initial);

    return (
        <div className="flex flex-col gap-6">
            <Button
                variant="ghost"
                size="sm"
                className="-ml-2.5 self-start text-muted-foreground"
                onClick={onBack}
            >
                <ArrowLeft aria-hidden="true" />
                Commands
            </Button>
            {run === null ? (
                error ? (
                    <Callout tone="error" title="This run can’t be shown">
                        {error}
                    </Callout>
                ) : (
                    <RunDetailSkeleton />
                )
            ) : (
                <RunView
                    run={run}
                    lines={lines}
                    data={data}
                    serviceRef={serviceRef}
                    onRunAgain={onRunAgain}
                    onCancelled={(next) => {
                        setRun(next);
                        onChanged();
                    }}
                />
            )}
        </div>
    );
}

export function RunView({
    run,
    lines,
    data,
    serviceRef,
    onRunAgain,
    onCancelled,
}: {
    run: Run;
    lines: App.Data.Commands.CommandOutputLineData[];
    data: TabData;
    serviceRef?: ServiceRef;
    onRunAgain: (request: RunRequest, timeoutSeconds: number) => Promise<void>;
    onCancelled: (run: Run) => void;
}) {
    const status = runStatus[run.status];
    const argv = formatArgv(run.argv);
    const ended = outcome(run);
    const listed = run.name
        ? data.catalogue.commands.find((command) => command.name === run.name)
        : undefined;

    return (
        <>
            <header className="flex flex-col gap-3">
                <div className="flex flex-wrap items-start gap-x-4 gap-y-3">
                    <h3 className="min-w-0 flex-1 basis-72 type-mono font-medium break-all">
                        <span
                            aria-hidden="true"
                            className="mr-2 text-muted-foreground select-none"
                        >
                            $
                        </span>
                        {argv}
                    </h3>
                    <div className="flex shrink-0 items-center gap-2">
                        {run.finished ? (
                            <RunAgain
                                run={run}
                                danger={listed?.danger ?? false}
                                environment={data.environment}
                                blocked={data.blocked}
                                onRunAgain={onRunAgain}
                            />
                        ) : (
                            <CancelButton run={run} onCancelled={onCancelled} />
                        )}
                    </div>
                </div>
                <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
                    <StatusPill
                        state={status.state}
                        label={status.label}
                        detail={
                            run.status === 'queued' ? null : (
                                <Elapsed
                                    since={run.startedAt}
                                    until={
                                        run.finished
                                            ? (run.finishedAt ?? run.startedAt)
                                            : null
                                    }
                                />
                            )
                        }
                    />
                    <span className="type-small text-muted-foreground">
                        <MetaLine>
                            {ranBy(run)}
                            <TimeAgo value={run.createdAt} />
                            {run.server ? `on ${run.server}` : null}
                            {run.deployment && serviceRef ? (
                                <Link
                                    href={
                                        deploymentShow({
                                            project: serviceRef.project,
                                            environment: serviceRef.environment,
                                            deployment: run.deployment,
                                        }).url
                                    }
                                    className="underline decoration-border underline-offset-4 hover:text-foreground"
                                >
                                    Deployment
                                </Link>
                            ) : null}
                            {run.finished
                                ? null
                                : `Stops after ${timeoutLabel(run.timeoutSeconds)}`}
                        </MetaLine>
                    </span>
                </div>
            </header>
            {run.status === 'queued' && (
                <p className="type-small text-muted-foreground">
                    Waiting for {run.server ?? 'the server'} to start it. It
                    pulls the image first when the server doesn’t have it yet.
                </p>
            )}
            {ended && (
                <Callout
                    tone={run.status === 'cancelled' ? 'info' : 'error'}
                    title={
                        run.status === 'failed' ? (
                            <>
                                Exited with code{' '}
                                <span className="tabular-nums">
                                    {run.exitCode}
                                </span>
                            </>
                        ) : (
                            status.label
                        )
                    }
                >
                    {run.status === 'failed'
                        ? 'The last lines of the output usually say why.'
                        : ended}
                </Callout>
            )}
            <CommandOutput
                lines={lines}
                live={!run.finished}
                title={argv}
                fileName={`${run.id}.log`}
            />
        </>
    );
}

function CancelButton({
    run,
    onCancelled,
}: {
    run: Run;
    onCancelled: (run: Run) => void;
}) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    return (
        <div className="flex items-center gap-3">
            {error && (
                <span role="alert" className="type-small text-status-error">
                    {error}
                </span>
            )}
            <Button
                size="sm"
                variant="outline"
                className="text-status-error hover:text-status-error"
                disabled={busy}
                onClick={() => {
                    setBusy(true);
                    setError(null);
                    cancelRun(run.id)
                        .then(onCancelled)
                        .catch((failure: unknown) =>
                            setError(
                                failure instanceof Error
                                    ? failure.message
                                    : 'It didn’t cancel. Try again.',
                            ),
                        )
                        .finally(() => setBusy(false));
                }}
            >
                <Square aria-hidden="true" className="fill-current" />
                {busy ? 'Cancelling…' : 'Cancel'}
            </Button>
        </div>
    );
}

function RunAgain({
    run,
    danger,
    environment,
    blocked,
    onRunAgain,
}: {
    run: Run;
    danger: boolean;
    environment: string;
    blocked: string | null;
    onRunAgain: (request: RunRequest, timeoutSeconds: number) => Promise<void>;
}) {
    const [error, setError] = useState<string | null>(null);
    const request: RunRequest = run.name
        ? { name: run.name }
        : { argv: run.argv };
    const again = async () => {
        setError(null);

        try {
            await onRunAgain(request, run.timeoutSeconds);
        } catch (failure) {
            setError(
                failure instanceof Error
                    ? failure.message
                    : 'It didn’t start. Try again in a moment.',
            );
        }
    };
    const button = (
        <Button
            size="sm"
            variant="outline"
            disabled={blocked !== null}
            title={blocked ?? undefined}
            onClick={danger ? undefined : () => void again()}
        >
            <RotateCcw aria-hidden="true" />
            Run again
        </Button>
    );

    return (
        <div className="flex items-center gap-3">
            {error && (
                <span role="alert" className="type-small text-status-error">
                    {error}
                </span>
            )}
            {danger ? (
                <ConfirmPopover
                    title={`Run it again in ${environment}?`}
                    consequence={`It changes data in ${environment}.`}
                    confirmLabel="Run again"
                    onConfirm={again}
                >
                    {button}
                </ConfirmPopover>
            ) : (
                button
            )}
        </div>
    );
}

function RunDetailSkeleton() {
    return (
        <div className="flex flex-col gap-4" aria-busy="true">
            <Skeleton className="h-6 w-2/3" />
            <Skeleton className="h-6 w-56" />
            <Skeleton className="h-72 w-full rounded-xl" />
        </div>
    );
}
