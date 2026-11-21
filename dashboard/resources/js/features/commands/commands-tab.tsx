import { useQuery } from '@tanstack/react-query';
import { SquareTerminal } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { ServiceActivityEvents } from '@/components/channel-events';
import { EmptyState } from '@/components/patterns/empty-state';
import { Skeleton } from '@/components/ui/skeleton';
import { fetchTab, startRun } from './api';
import type { Run, ServiceRef, TabData } from './api';
import { Catalogue } from './catalogue';
import { CommandBar } from './command-bar';
import type { CommandBarHandle } from './command-bar';
import { RecentRuns } from './recent-runs';
import { RunDetail } from './run-detail';

type Command = App.Data.Commands.CommandData;

/** What a Run click asks for: a typed argv or a listed command by name. */
export type RunRequest =
    | { argv: string[]; name?: undefined }
    | { name: string; argv?: undefined };

const RUN_PARAM = 'run';

function runFromUrl(): string | null {
    const id = new URLSearchParams(window.location.search).get(RUN_PARAM);

    return id !== null && /^run_[0-9A-Za-z]{10,40}$/.test(id) ? id : null;
}

/** Keeps `?run=` in the address bar, so a run detail can be linked and reloaded. */
function writeRunToUrl(id: string | null): void {
    const url = new URL(window.location.href);

    if (id === null) {
        url.searchParams.delete(RUN_PARAM);
    } else {
        url.searchParams.set(RUN_PARAM, id);
    }

    window.history.replaceState(window.history.state, '', url);
}

/**
 * The Commands tab of a service (D105, docs/08 §22): one command bar, the
 * framework's commands and the ones launchroom.json names, and the recent
 * runs; a run opens its detail with the streamed output.
 */
export function CommandsTab({
    logo,
    ...ref
}: ServiceRef & {
    /** The service's logo (its framework's mark). */
    logo: string;
}) {
    const query = useQuery({
        queryKey: ['commands', ref.project, ref.environment, ref.service],
        queryFn: ({ signal }) => fetchTab(ref, null, signal),
        staleTime: 0,
    });
    const [selected, setSelected] = useState<{
        id: string;
        run: Run | null;
    } | null>(() => {
        const id = runFromUrl();

        return id === null ? null : { id, run: null };
    });

    const open = useCallback((id: string, run: Run | null = null) => {
        setSelected({ id, run });
        writeRunToUrl(id);
    }, []);
    const close = useCallback(() => {
        setSelected(null);
        writeRunToUrl(null);
    }, []);

    const refetch = query.refetch;
    const run = useCallback(
        async (request: RunRequest, timeoutSeconds: number) => {
            const started = await startRun(ref, { ...request, timeoutSeconds });
            open(started.id, started);
            void refetch();
        },
        // The ref's parts are primitives; the object is new every render.
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [ref.project, ref.environment, ref.service, open, refetch],
    );

    // Leaving the tab forgets the open run, so coming back lands on the list.
    useEffect(() => () => writeRunToUrl(null), []);

    if (query.isPending) {
        return <CommandsTabSkeleton />;
    }

    if (!query.data) {
        return (
            <EmptyState icon={SquareTerminal} title="Commands unavailable">
                {query.error instanceof Error
                    ? query.error.message
                    : 'Try again in a moment.'}
            </EmptyState>
        );
    }

    return (
        <>
            <ServiceActivityEvents
                environment={ref.environment}
                service={ref.service}
                topic="commands"
                onChange={() => void refetch()}
            />
            {selected ? (
                <RunDetail
                    key={selected.id}
                    id={selected.id}
                    initial={selected.run}
                    data={query.data}
                    serviceRef={ref}
                    onBack={close}
                    onRunAgain={run}
                    onChanged={() => void refetch()}
                />
            ) : (
                <CommandsView
                    data={query.data}
                    logo={logo}
                    serviceRef={ref}
                    onRun={run}
                    onOpen={open}
                />
            )}
        </>
    );
}

export function CommandsView({
    data,
    logo,
    serviceRef,
    onRun,
    onOpen,
}: {
    data: TabData;
    logo: string;
    serviceRef?: ServiceRef;
    onRun: (request: RunRequest, timeoutSeconds: number) => Promise<void>;
    onOpen: (id: string, run?: Run) => void;
}) {
    const bar = useRef<CommandBarHandle>(null);
    const [timeout, setTimeoutSeconds] = useState(data.defaultTimeout);
    const [error, setError] = useState<string | null>(null);
    const submit = async (request: RunRequest) => {
        setError(null);

        try {
            await onRun(request, timeout);
        } catch (failure) {
            setError(
                failure instanceof Error
                    ? failure.message
                    : 'The command didn’t start. Try again in a moment.',
            );
            throw failure;
        }
    };
    const placeholder =
        data.catalogue.commands.find(
            (command) => !command.danger && command.source === 'framework',
        )?.argv ?? null;

    return (
        <div className="flex flex-col gap-10">
            <CommandBar
                ref={bar}
                blocked={data.blocked}
                placeholder={placeholder}
                timeout={timeout}
                timeouts={data.timeouts}
                onTimeoutChange={setTimeoutSeconds}
                error={error}
                onRun={(argv) => submit({ argv })}
            />
            <Catalogue
                catalogue={data.catalogue}
                logo={logo}
                environment={data.environment}
                disabled={data.blocked !== null}
                onRun={(command: Command) =>
                    submit({ name: command.name }).catch(() => undefined)
                }
                onFill={(command: Command) => bar.current?.fill(command.argv)}
            />
            <RecentRuns
                runs={data.runs}
                more={data.moreRuns}
                serviceRef={serviceRef}
                onOpen={onOpen}
            />
        </div>
    );
}

function CommandsTabSkeleton() {
    return (
        <div className="flex flex-col gap-10" aria-busy="true">
            <Skeleton className="h-[104px] w-full rounded-xl" />
            <div className="flex flex-col gap-3.5">
                <Skeleton className="h-5 w-40" />
                <Skeleton className="h-48 w-full rounded-xl" />
            </div>
        </div>
    );
}
