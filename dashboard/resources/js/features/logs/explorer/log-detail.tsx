import { ChevronDown, ChevronUp, Copy, X } from 'lucide-react';
import type { ReactNode } from 'react';
import { MetaLine } from '@/components/patterns/meta-line';
import { cn } from '@/lib/utils';
import { stripAnsi } from '../ansi';
import { AnsiText } from '../ansi-text';
import { LevelBadge } from './level-badge';
import type { LogEntry } from './types';

export type LogFact = { label: string; value: ReactNode; mono?: boolean };

/**
 * Detail drawer content for one log line (design-system §5.5): level,
 * service and where it ran in the header; the full message wrapped in a
 * recessed box; a facts list; a slot for "similar lines" and actions such as
 * "Show context". The page decides where it lives (right column on desktop,
 * a Sheet on phones).
 */
export function LogDetail({
    entry,
    facts,
    onClose,
    onPrevious,
    onNext,
    footer,
    className,
}: {
    entry: LogEntry;
    facts?: LogFact[];
    onClose?: () => void;
    onPrevious?: () => void;
    onNext?: () => void;
    /** Similar lines sentence, "Show context" … */
    footer?: ReactNode;
    className?: string;
}) {
    const date = new Date(entry.time);
    const defaultFacts: LogFact[] = [
        {
            label: 'Time',
            value: Number.isNaN(date.getTime())
                ? entry.time
                : `${date.toLocaleTimeString('en-GB', { hour12: false })}.${String(date.getMilliseconds()).padStart(3, '0')}`,
            mono: true,
        },
        ...(entry.deployment
            ? [{ label: 'Deployment', value: entry.deployment, mono: true }]
            : []),
        ...(entry.stream
            ? [{ label: 'Stream', value: entry.stream, mono: true }]
            : []),
        ...(entry.server
            ? [{ label: 'Server', value: entry.server, mono: true }]
            : []),
    ];
    const rows = facts ?? defaultFacts;
    const iconButton =
        'inline-flex size-8 items-center justify-center rounded-md text-muted-foreground hover:bg-(--hover) hover:text-foreground focus-visible:outline-2 focus-visible:outline-(--signal) disabled:opacity-40';

    return (
        <section
            aria-label="Log line details"
            className={cn('flex flex-col gap-4 p-4', className)}
        >
            <header className="flex items-center gap-2">
                <LevelBadge level={entry.level} />
                <span className="type-label">{entry.service}</span>
                {(entry.replica || entry.server) && (
                    <MetaLine className="type-small text-muted-foreground">
                        {entry.replica && `replica ${entry.replica}`}
                        {entry.server && `on ${entry.server}`}
                    </MetaLine>
                )}
                <span className="ml-auto flex items-center">
                    {onPrevious && (
                        <button
                            type="button"
                            onClick={onPrevious}
                            className={iconButton}
                            aria-label="Previous line"
                        >
                            <ChevronUp className="size-4" aria-hidden="true" />
                        </button>
                    )}
                    {onNext && (
                        <button
                            type="button"
                            onClick={onNext}
                            className={iconButton}
                            aria-label="Next line"
                        >
                            <ChevronDown
                                className="size-4"
                                aria-hidden="true"
                            />
                        </button>
                    )}
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
            <pre className="max-h-72 overflow-auto rounded-lg border bg-(--recessed) p-3 type-mono-small break-words whitespace-pre-wrap">
                <AnsiText text={entry.message} />
            </pre>
            <dl className="divide-y rounded-lg border">
                {rows.map((fact) => (
                    <div
                        key={fact.label}
                        className="flex items-center justify-between gap-4 px-3 py-2"
                    >
                        <dt className="type-small text-muted-foreground">
                            {fact.label}
                        </dt>
                        <dd
                            className={cn(
                                'truncate text-right',
                                fact.mono ? 'type-mono-small' : 'type-body',
                            )}
                        >
                            {fact.value}
                        </dd>
                    </div>
                ))}
            </dl>
            {footer}
        </section>
    );
}
