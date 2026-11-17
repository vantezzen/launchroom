import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { Fragment } from 'react';
import { CopyButton } from '@/components/copy-button';
import { StatusIcon, healthCheckState } from '@/components/status';
import { TimeAgo } from '@/components/time-ago';

type Notice = App.Data.Servers.ServerNoticeData;

/**
 * A sentence with `commands` in backticks: the commands are shown as code
 * (the dashboard writes them, so they are safe to show as they are).
 */
export function CommandText({ text }: { text: string }) {
    const parts = text.split('`');

    return (
        <>
            {parts.map((part, index) =>
                index % 2 === 1 ? (
                    <code
                        key={index}
                        className="rounded bg-recessed px-1 py-0.5 type-mono-small text-foreground"
                    >
                        {part}
                    </code>
                ) : (
                    <Fragment key={index}>{part}</Fragment>
                ),
            )}
        </>
    );
}

function Trace({ text }: { text: string }) {
    return (
        <details className="group type-small">
            <summary className="inline-flex w-fit cursor-pointer list-none items-center gap-1 rounded-md text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-signal focus-visible:outline-none [&::-webkit-details-marker]:hidden">
                <ChevronRight
                    aria-hidden="true"
                    className="size-3.5 transition-transform duration-fast group-open:rotate-90"
                />
                Crash report
            </summary>
            <div className="mt-2 flex items-start gap-2 rounded-md border bg-recessed px-3 py-2">
                <pre className="max-h-72 min-w-0 flex-1 overflow-auto type-mono-small whitespace-pre-wrap text-foreground">
                    {text}
                </pre>
                <CopyButton value={text} label="crash report" size="xs" />
            </div>
        </details>
    );
}

/**
 * What happened to the server, in sentences (G3, G4, G13): why the
 * dashboard refuses it, Docker or the firewall stopping apps, launchroom
 * crashing or the machine restarting. Problems first; past events quietly.
 */
export function ServerNotices({
    notices,
    diskHref,
}: {
    notices: Notice[];
    /** Where "the disk is full" points (the Disk section). */
    diskHref?: string;
}) {
    if (notices.length === 0) {
        return null;
    }

    return (
        <ul className="flex flex-col gap-2" aria-label="What happened">
            {notices.map((notice) => {
                const { state } = healthCheckState[notice.state];
                const quiet = notice.state === 'ok';

                return (
                    <li
                        key={notice.key}
                        className="flex items-start gap-3.5 rounded-xl border bg-card px-4 py-3.5"
                    >
                        <span
                            aria-hidden="true"
                            className="flex size-7 shrink-0 items-center justify-center rounded-lg bg-recessed"
                        >
                            <StatusIcon state={quiet ? 'idle' : state} />
                        </span>
                        <div className="flex min-w-0 flex-1 flex-col gap-1">
                            <div className="flex flex-wrap items-baseline justify-between gap-x-3">
                                <span className="type-body font-medium">
                                    {notice.title}
                                </span>
                                {notice.at && (
                                    <span className="type-small text-muted-foreground">
                                        <TimeAgo value={notice.at} />
                                    </span>
                                )}
                            </div>
                            <p className="type-small text-muted-foreground">
                                <CommandText text={notice.detail} />
                            </p>
                            {notice.anchor === 'disk' && diskHref && (
                                <Link
                                    href={diskHref}
                                    className="w-fit type-small text-signal underline-offset-4 hover:underline"
                                >
                                    See what takes the space
                                </Link>
                            )}
                            {notice.trace && <Trace text={notice.trace} />}
                        </div>
                    </li>
                );
            })}
        </ul>
    );
}
