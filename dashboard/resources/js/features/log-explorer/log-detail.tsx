import { Link } from '@inertiajs/react';
import { ChevronDown, ChevronUp, Copy, X } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';
import { stripAnsi } from '@/features/logs/ansi';
import { AnsiText } from '@/features/logs/ansi-text';
import { LevelBadge } from './level-badge';
import { structuredLine } from './structured';
import type { LogEntry } from './types';

type LogFact = { label: string; value: ReactNode; mono?: boolean };
type LogFactGroup = { title: string; facts: LogFact[] };

const iconButton =
    'inline-flex size-7 items-center justify-center rounded-md text-muted-foreground hover:bg-(--hover) hover:text-foreground focus-visible:outline-2 focus-visible:outline-(--signal) disabled:opacity-40';
const link = 'text-signal underline-offset-4 hover:underline';

function fullTime(iso: string): string {
    const date = new Date(iso);

    if (Number.isNaN(date.getTime())) {
        return iso;
    }

    const day = date.toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
    const clock = date.toLocaleTimeString('en-GB', { hour12: false });

    return `${day}, ${clock}.${String(date.getMilliseconds()).padStart(3, '0')}`;
}

/** Line, Source, Deployment: the facts a line carries, empty ones left out. */
function factGroups(
    entry: LogEntry,
    hrefs: { deployment?: string | null; server?: string | null },
): LogFactGroup[] {
    const groups: LogFactGroup[] = [
        {
            title: 'Line',
            facts: [
                { label: 'Time', value: fullTime(entry.time), mono: true },
                { label: 'Level', value: <LevelBadge level={entry.level} /> },
                ...(entry.stream
                    ? [{ label: 'Stream', value: entry.stream, mono: true }]
                    : []),
            ],
        },
        {
            title: 'Source',
            facts: [
                { label: 'Service', value: entry.service },
                ...(entry.replica
                    ? [{ label: 'Replica', value: entry.replica, mono: true }]
                    : []),
                ...(entry.server
                    ? [
                          {
                              label: 'Server',
                              value: hrefs.server ? (
                                  <Link href={hrefs.server} className={link}>
                                      {entry.server}
                                  </Link>
                              ) : (
                                  entry.server
                              ),
                          },
                      ]
                    : []),
            ],
        },
        {
            title: 'Deployment',
            facts: [
                ...(entry.deployment
                    ? [
                          {
                              label: 'Deployment',
                              value: hrefs.deployment ? (
                                  <Link
                                      href={hrefs.deployment}
                                      className={link}
                                  >
                                      {entry.deployment}
                                  </Link>
                              ) : (
                                  entry.deployment
                              ),
                              mono: true,
                          },
                      ]
                    : []),
                ...(entry.environment
                    ? [{ label: 'Environment', value: entry.environment }]
                    : []),
                ...(entry.project
                    ? [{ label: 'Project', value: entry.project }]
                    : []),
            ],
        },
    ];

    return groups.filter((group) => group.facts.length > 0);
}

/** The full message, wrapped; JSON lines pretty-printed. */
function MessageText({ entry }: { entry: LogEntry }) {
    return (
        <pre className="type-mono-small [overflow-wrap:anywhere] whitespace-pre-wrap">
            <AnsiText text={structuredLine(entry.message).pretty} />
        </pre>
    );
}

/**
 * The bottom pane under the list (wide explorer): "Message" with a
 * collapse chevron over the selected line's full message, scrolling on its
 * own. Collapsed, only the title row stays.
 */
export function LogMessagePane({
    entry,
    open,
    onToggle,
    className,
}: {
    entry: LogEntry;
    open: boolean;
    onToggle: () => void;
    className?: string;
}) {
    return (
        <section
            aria-label="Message"
            className={cn('flex min-h-0 flex-col border-t', className)}
        >
            <button
                type="button"
                onClick={onToggle}
                aria-expanded={open}
                className="flex h-9 shrink-0 items-center gap-1.5 bg-(--recessed) px-3 text-left type-label hover:bg-(--hover) focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-(--signal)"
            >
                <ChevronDown
                    aria-hidden="true"
                    className={cn(
                        'size-4 text-muted-foreground transition-transform duration-fast',
                        !open && '-rotate-90',
                    )}
                />
                Message
            </button>
            {open && (
                <div className="min-h-0 flex-1 overflow-auto border-t px-4 py-3">
                    <MessageText entry={entry} />
                </div>
            )}
        </section>
    );
}

/**
 * "Log details" for one line (design-system §5.5): a header with previous,
 * next, copy and close; then the facts in small titled groups (Line,
 * Source, Deployment), and a slot for "similar lines" and filter actions.
 * The body scrolls on its own. The message itself sits in `LogMessagePane`
 * under the list; `messageClassName` decides when this panel shows it too
 * (narrow containers, the phone sheet).
 */
export function LogDetail({
    entry,
    hrefs = {},
    title = 'Log details',
    onClose,
    onPrevious,
    onNext,
    footer,
    messageClassName,
    className,
}: {
    entry: LogEntry;
    /** Links for the server and deployment facts. */
    hrefs?: { deployment?: string | null; server?: string | null };
    /** Header title; `null` when the surrounding sheet already names it. */
    title?: string | null;
    onClose?: () => void;
    onPrevious?: () => void;
    onNext?: () => void;
    /** Similar lines sentence, filter actions … */
    footer?: ReactNode;
    /** Classes for the message block (e.g. hide it where a message pane shows it). */
    messageClassName?: string;
    className?: string;
}) {
    return (
        <section
            aria-label="Log details"
            className={cn('flex min-h-0 flex-col', className)}
        >
            <header className="flex h-11 shrink-0 items-center gap-1 border-b pr-2 pl-4">
                {title ? (
                    <h3 className="mr-auto type-label">{title}</h3>
                ) : (
                    <span className="mr-auto flex min-w-0 items-center gap-2">
                        <LevelBadge level={entry.level} />
                        <span className="truncate type-label">
                            {entry.service}
                        </span>
                    </span>
                )}
                <span className="flex items-center">
                    <button
                        type="button"
                        onClick={onPrevious}
                        disabled={!onPrevious}
                        className={iconButton}
                        aria-label="Previous line"
                    >
                        <ChevronUp className="size-4" aria-hidden="true" />
                    </button>
                    <button
                        type="button"
                        onClick={onNext}
                        disabled={!onNext}
                        className={iconButton}
                        aria-label="Next line"
                    >
                        <ChevronDown className="size-4" aria-hidden="true" />
                    </button>
                    <button
                        type="button"
                        onClick={() =>
                            void navigator.clipboard?.writeText(
                                stripAnsi(entry.message),
                            )
                        }
                        className={iconButton}
                        aria-label="Copy message"
                    >
                        <Copy className="size-4" aria-hidden="true" />
                    </button>
                    {onClose && (
                        <button
                            type="button"
                            onClick={onClose}
                            className={iconButton}
                            aria-label="Close details"
                        >
                            <X className="size-4" aria-hidden="true" />
                        </button>
                    )}
                </span>
            </header>
            <div className="flex min-h-0 flex-1 flex-col gap-5 overflow-auto p-4">
                <div className={cn('flex flex-col gap-2', messageClassName)}>
                    <h4 className="type-small font-medium text-muted-foreground">
                        Message
                    </h4>
                    <div className="max-h-80 overflow-auto rounded-lg border bg-(--recessed) p-3">
                        <MessageText entry={entry} />
                    </div>
                </div>
                {factGroups(entry, hrefs).map((group) => (
                    <div key={group.title} className="flex flex-col gap-2">
                        <h4 className="type-small font-medium text-muted-foreground">
                            {group.title}
                        </h4>
                        <dl className="divide-y rounded-lg border">
                            {group.facts.map((fact) => (
                                <div
                                    key={fact.label}
                                    className="flex min-h-9 items-center justify-between gap-4 px-3 py-1.5"
                                >
                                    <dt className="shrink-0 type-small text-muted-foreground">
                                        {fact.label}
                                    </dt>
                                    <dd
                                        className={cn(
                                            'min-w-0 truncate text-right',
                                            fact.mono
                                                ? 'type-mono-small'
                                                : 'type-small',
                                        )}
                                        title={
                                            typeof fact.value === 'string'
                                                ? fact.value
                                                : undefined
                                        }
                                    >
                                        {fact.value}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    </div>
                ))}
                {footer}
            </div>
        </section>
    );
}
