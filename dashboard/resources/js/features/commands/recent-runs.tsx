import { ChevronRight, History } from 'lucide-react';
import { useState } from 'react';
import { MetaLine } from '@/components/patterns/meta-line';
import { Section } from '@/components/patterns/section';
import { StatusIcon } from '@/components/status';
import { TimeAgo } from '@/components/time-ago';
import { Button } from '@/components/ui/button';
import { fetchTab } from './api';
import type { Run, ServiceRef } from './api';
import { formatArgv } from './argv';
import { ranBy, runStatus, shortRunDuration } from './format';

/**
 * Recent runs of the service, newest first (docs/08 §22): status icon and
 * word, the argv, who, when, how long and the exit code of a failed one.
 * A row opens the run with its output.
 */
export function RecentRuns({
    runs,
    more,
    serviceRef,
    onOpen,
}: {
    runs: Run[];
    more: string | null;
    serviceRef?: ServiceRef;
    onOpen: (id: string, run?: Run) => void;
}) {
    const [older, setOlder] = useState<{ runs: Run[]; more: string | null }>({
        runs: [],
        more,
    });
    const [loading, setLoading] = useState(false);
    const shown = [
        ...runs,
        ...older.runs.filter((run) => !runs.some((r) => r.id === run.id)),
    ];
    const next = older.runs.length > 0 ? older.more : more;

    const loadMore = async () => {
        if (!serviceRef || next === null) {
            return;
        }

        setLoading(true);

        try {
            const page = await fetchTab(serviceRef, next);
            setOlder((current) => ({
                runs: [...current.runs, ...page.runs],
                more: page.moreRuns,
            }));
        } finally {
            setLoading(false);
        }
    };

    return (
        <Section level={3} title="Recent runs">
            {shown.length === 0 ? (
                <div className="flex items-center gap-3 rounded-xl border border-dashed px-4 py-5">
                    <History
                        aria-hidden="true"
                        className="size-4 shrink-0 text-faint"
                    />
                    <p className="type-body text-muted-foreground">
                        Commands you run show up here with their output.
                    </p>
                </div>
            ) : (
                <ul className="flex flex-col divide-y overflow-hidden rounded-xl border bg-card shadow-card">
                    {shown.map((run) => (
                        <li key={run.id}>
                            <RunRow run={run} onOpen={onOpen} />
                        </li>
                    ))}
                </ul>
            )}
            {next !== null && serviceRef && (
                <Button
                    variant="ghost"
                    size="sm"
                    className="self-start"
                    disabled={loading}
                    onClick={() => void loadMore()}
                >
                    Show more
                </Button>
            )}
        </Section>
    );
}

export function RunRow({
    run,
    onOpen,
}: {
    run: Run;
    onOpen: (id: string, run?: Run) => void;
}) {
    const status = runStatus[run.status];

    return (
        <button
            type="button"
            onClick={() => onOpen(run.id, run)}
            className="group flex w-full items-center gap-3 px-4 py-3 text-left transition-colors duration-fast hover:bg-hover focus-visible:bg-hover focus-visible:outline-none"
        >
            <StatusIcon state={status.state} className="mt-0.5 self-start" />
            <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                <span className="truncate type-mono-small text-foreground">
                    {formatArgv(run.argv)}
                </span>
                <span className="type-small text-muted-foreground">
                    <MetaLine>
                        <span className="text-foreground">{status.label}</span>
                        {run.status === 'failed' && run.exitCode !== null
                            ? `Exit ${run.exitCode}`
                            : null}
                        {ranBy(run)}
                        {shortRunDuration(run)}
                    </MetaLine>
                </span>
            </span>
            <span className="hidden shrink-0 type-small text-muted-foreground sm:inline">
                <TimeAgo value={run.createdAt} />
            </span>
            <ChevronRight
                aria-hidden="true"
                className="size-4 shrink-0 text-faint transition-transform duration-fast group-hover:translate-x-0.5"
            />
        </button>
    );
}
