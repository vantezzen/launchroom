import { Link } from '@inertiajs/react';
import {
    ChevronDown,
    ChevronUp,
    Copy,
    EyeOff,
    Filter,
    History,
    X,
} from 'lucide-react';
import type { ReactNode } from 'react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import { stripAnsi } from '@/features/logs/ansi';
import { AnsiText } from '@/features/logs/ansi-text';
import { LevelBadge } from './level-badge';
import { structuredLine } from './structured';
import type { QueryKey } from './query';
import type { LogEntry } from './types';

type LogFact = {
    label: string;
    value: ReactNode;
    mono?: boolean;
    /** The query key this fact filters on ("Only this", "Hide this"). */
    filter?: { key: QueryKey; value: string };
};

/** Filter pivots from a fact: add `key:value` or `-key:value` to the query. */
export type LogPivot = {
    apply: (key: QueryKey, value: string, negated: boolean) => void;
    /** Whether the query already holds this filter (the button then removes it). */
    active: (key: QueryKey, value: string, negated: boolean) => boolean;
};
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
                {
                    label: 'Level',
                    value: <LevelBadge level={entry.level} />,
                    filter: { key: 'level', value: entry.level },
                },
                ...(entry.stream
                    ? [
                          {
                              label: 'Stream',
                              value: entry.stream,
                              mono: true,
                              filter: {
                                  key: 'stream' as const,
                                  value: entry.stream,
                              },
                          },
                      ]
                    : []),
            ],
        },
        {
            title: 'Source',
            facts: [
                {
                    label: 'Service',
                    value: entry.service,
                    filter: { key: 'service', value: entry.service },
                },
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
                              filter: {
                                  key: 'server' as const,
                                  value: entry.server,
                              },
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
                              filter: {
                                  key: 'deployment' as const,
                                  value: entry.deployment,
                              },
                          },
                      ]
                    : []),
                ...(entry.environment
                    ? [
                          {
                              label: 'Environment',
                              value: entry.environment,
                              filter: {
                                  key: 'environment' as const,
                                  value: entry.environment,
                              },
                          },
                      ]
                    : []),
                ...(entry.project
                    ? [
                          {
                              label: 'Project',
                              value: entry.project,
                              filter: {
                                  key: 'project' as const,
                                  value: entry.project,
                              },
                          },
                      ]
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

/** The line as JSON for "Copy line as JSON": the facts plus the raw message. */
function lineJson(entry: LogEntry): string {
    return JSON.stringify(
        {
            time: entry.time,
            level: entry.level,
            service: entry.service,
            replica: entry.replica ?? undefined,
            server: entry.server ?? undefined,
            deployment: entry.deployment ?? undefined,
            stream: entry.stream ?? undefined,
            environment: entry.environment ?? undefined,
            project: entry.project ?? undefined,
            message: stripAnsi(entry.message),
        },
        null,
        2,
    );
}

function copy(text: string) {
    void navigator.clipboard?.writeText(text);
}

const pivotButton =
    'inline-flex size-6 items-center justify-center rounded-md text-muted-foreground hover:bg-(--hover) hover:text-foreground focus-visible:outline-2 focus-visible:outline-(--signal) aria-pressed:bg-(--hover) aria-pressed:text-foreground';

/** "Only this" and "Hide this" beside a fact: shown on hover and focus, always on touch. */
function PivotButtons({
    label,
    filter,
    pivot,
}: {
    label: string;
    filter: { key: QueryKey; value: string };
    pivot: LogPivot;
}) {
    const only = pivot.active(filter.key, filter.value, false);
    const hidden = pivot.active(filter.key, filter.value, true);

    return (
        <span className="flex shrink-0 items-center opacity-0 group-focus-within/fact:opacity-100 group-hover/fact:opacity-100 pointer-coarse:opacity-100">
            <button
                type="button"
                className={pivotButton}
                aria-pressed={only}
                aria-label={
                    only
                        ? `Stop showing only this ${label.toLowerCase()}`
                        : `Only this ${label.toLowerCase()}`
                }
                title={only ? 'Remove this filter' : 'Only this'}
                onClick={() => pivot.apply(filter.key, filter.value, false)}
            >
                <Filter aria-hidden="true" className="size-3.5" />
            </button>
            <button
                type="button"
                className={pivotButton}
                aria-pressed={hidden}
                aria-label={
                    hidden
                        ? `Show this ${label.toLowerCase()} again`
                        : `Hide this ${label.toLowerCase()}`
                }
                title={hidden ? 'Show it again' : 'Hide this'}
                onClick={() => pivot.apply(filter.key, filter.value, true)}
            >
                <EyeOff aria-hidden="true" className="size-3.5" />
            </button>
        </span>
    );
}

/**
 * "Log details" for one line (design-system §5.5): a header with
 * "Surrounding lines", previous, next, a copy menu (message, line as
 * JSON, link to the line) and close; then the facts in small titled groups
 * (Line, Source, Deployment), each with "Only this" and "Hide this", and a
 * slot for "similar lines". The body scrolls on its own. The message
 * itself sits in `LogMessagePane` under the list; `messageClassName`
 * decides when this panel shows it too (narrow containers, the phone sheet).
 */
export function LogDetail({
    entry,
    hrefs = {},
    title = 'Log details',
    onClose,
    onPrevious,
    onNext,
    onShowContext,
    pivot,
    lineLink,
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
    /** Show the lines logged around this one. */
    onShowContext?: () => void;
    /** Filter pivots on the facts. */
    pivot?: LogPivot;
    /** Absolute URL that opens the explorer on this line. */
    lineLink?: string | null;
    /** Similar lines sentence … */
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
                    <h3 className="mr-auto truncate type-label">{title}</h3>
                ) : (
                    <span className="mr-auto flex min-w-0 items-center gap-2">
                        <LevelBadge level={entry.level} />
                        <span className="truncate type-label">
                            {entry.service}
                        </span>
                    </span>
                )}
                <span className="flex shrink-0 items-center">
                    {onShowContext && (
                        <button
                            type="button"
                            onClick={onShowContext}
                            className="mr-1 inline-flex h-7 items-center gap-1.5 rounded-md px-2 type-small text-muted-foreground hover:bg-(--hover) hover:text-foreground focus-visible:outline-2 focus-visible:outline-(--signal)"
                        >
                            <History className="size-3.5" aria-hidden="true" />
                            Surrounding lines
                        </button>
                    )}
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
                    <DropdownMenu>
                        <DropdownMenuTrigger
                            className={iconButton}
                            aria-label="Copy"
                        >
                            <Copy className="size-4" aria-hidden="true" />
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem
                                onSelect={() => copy(stripAnsi(entry.message))}
                            >
                                Copy message
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                onSelect={() => copy(lineJson(entry))}
                            >
                                Copy line as JSON
                            </DropdownMenuItem>
                            {lineLink && (
                                <DropdownMenuItem
                                    onSelect={() => copy(lineLink)}
                                >
                                    Copy link to this line
                                </DropdownMenuItem>
                            )}
                        </DropdownMenuContent>
                    </DropdownMenu>
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
                                    className="group/fact flex min-h-9 items-center gap-2 px-3 py-1.5"
                                >
                                    <dt className="mr-auto shrink-0 type-small text-muted-foreground">
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
                                    {pivot && fact.filter && (
                                        <PivotButtons
                                            label={fact.label}
                                            filter={fact.filter}
                                            pivot={pivot}
                                        />
                                    )}
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
