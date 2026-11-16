import { useVirtualizer } from '@tanstack/react-virtual';
import { memo, useCallback, useEffect, useLayoutEffect, useRef } from 'react';
import type { KeyboardEvent, ReactNode } from 'react';
import { cn } from '@/lib/utils';
import { AnsiText } from '@/features/logs/ansi-text';
import {
    JumpToLatest,
    useFollowScroll,
} from '@/features/logs/use-follow-scroll';
import { LevelBadge } from './level-badge';
import { structuredLine } from './structured';
import type { LogEntry } from './types';

const ROW = 28;
// Columns follow the table's own width (it sits next to the details or in
// the service panel), not the viewport: the day from 672 px, the
// milliseconds from 448 px, the source from 512 px.
const TIME_COLUMN = 'w-16 shrink-0 @md/table:w-24 @2xl/table:w-40';
const SOURCE_COLUMN = 'shrink-0 @max-lg/table:hidden';

const dayFormat = new Intl.DateTimeFormat('en-GB', {
    month: 'short',
    day: '2-digit',
});
const clockFormat = new Intl.DateTimeFormat('en-GB', {
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hour12: false,
});

function splitTime(iso: string): { day: string; clock: string; ms: string } {
    const date = new Date(iso);

    if (Number.isNaN(date.getTime())) {
        return { day: '', clock: iso, ms: '' };
    }

    return {
        day: dayFormat.format(date).toUpperCase(),
        clock: clockFormat.format(date),
        ms: String(date.getMilliseconds()).padStart(3, '0').slice(0, 2),
    };
}

const LogRow = memo(function LogRow({
    entry,
    index,
    start,
    selected,
    highlight,
    hideService,
    context,
    onSelect,
}: {
    entry: LogEntry;
    index: number;
    start: number;
    selected: boolean;
    highlight: string;
    hideService: boolean;
    context: LogContext;
    onSelect: (index: number) => void;
}) {
    const time = splitTime(entry.time);
    const error = entry.level === 'error';

    return (
        <div
            id={`log-row-${entry.id}`}
            role="row"
            aria-rowindex={index + 1}
            aria-selected={selected}
            onClick={() => onSelect(index)}
            className={cn(
                'absolute top-0 left-0 flex w-full cursor-default items-center gap-3 px-4 type-mono-small',
                error
                    ? 'bg-status-error-soft/70 text-status-error hover:bg-status-error-soft'
                    : 'hover:bg-foreground/[0.04]',
                selected && 'bg-foreground/[0.08] hover:bg-foreground/[0.08]',
            )}
            style={{ transform: `translateY(${start}px)`, height: ROW }}
        >
            <span
                role="gridcell"
                className={cn(
                    TIME_COLUMN,
                    'tabular-nums',
                    !error && 'text-muted-foreground',
                )}
            >
                <span className="hidden @2xl/table:inline">{time.day} </span>
                <span className={error ? undefined : 'text-foreground'}>
                    {time.clock}
                </span>
                <span className="hidden @md/table:inline">.{time.ms}</span>
            </span>
            <span role="gridcell" className="w-16 shrink-0">
                <LevelBadge level={entry.level} />
            </span>
            {!hideService && (
                <span
                    role="gridcell"
                    className={cn(
                        SOURCE_COLUMN,
                        'truncate',
                        context ? 'w-48' : 'w-28',
                    )}
                    title={
                        context
                            ? `${contextOf(entry, context)} / ${entry.service}`
                            : undefined
                    }
                >
                    {context && (
                        <span
                            className={
                                error ? undefined : 'text-muted-foreground'
                            }
                        >
                            {contextOf(entry, context)} /{' '}
                        </span>
                    )}
                    {entry.service}
                    {entry.replica && (
                        <span
                            className={
                                error ? undefined : 'text-muted-foreground'
                            }
                        >
                            {' '}
                            #{entry.replica}
                        </span>
                    )}
                </span>
            )}
            <span
                role="gridcell"
                className={cn(
                    'min-w-0 flex-1 truncate',
                    entry.stream === 'system' && 'text-muted-foreground',
                )}
            >
                <AnsiText
                    text={structuredLine(entry.message).summary}
                    highlight={highlight}
                />
            </span>
        </div>
    );
});

/** Where a line is from in wide searches: its environment, or project / environment, before the service. */
type LogContext = 'environment' | 'project' | null;

function contextOf(entry: LogEntry, context: LogContext): string {
    if (context === 'project') {
        return [entry.project, entry.environment].filter(Boolean).join(' / ');
    }

    return entry.environment ?? '';
}

type LogTableProps = {
    entries: LogEntry[];
    selectedId?: string | null;
    onSelect?: (entry: LogEntry) => void;
    /** Lowercase text terms to highlight (from the parsed query). */
    highlight?: string;
    /**
     * New lines arrive at the top (entries are newest first): stay on the
     * newest while the reader is there; scrolled down, keep their place
     * and offer "Jump to latest".
     */
    live?: boolean;
    /** Hide the service column (single-service views such as the panel tab). */
    hideService?: boolean;
    /** Show where each line comes from (project- and team-wide searches). */
    context?: LogContext;
    /** Shown instead of the rows when there are no entries. */
    empty?: ReactNode;
    className?: string;
};

/**
 * Virtualized log table (design-system §5.5): fixed 28 px rows
 * (scrolls 100k lines without layout work), time with the seconds emphasised,
 * level chip, service + replica, message as ANSI-toned text (never HTML);
 * a JSON line shows its `msg`. Error lines are red on a faint red wash and
 * keep their level chip. One tab stop: ↑/↓ move the selection and keep it
 * in view, Home/End jump. The caller draws the frame around it.
 */
export function LogTable({
    entries,
    selectedId = null,
    onSelect,
    highlight = '',
    live = false,
    hideService = false,
    context = null,
    empty = null,
    className,
}: LogTableProps) {
    // TanStack Virtual mutates one instance between renders; the React
    // Compiler would memoise the rows against it and freeze the list.
    'use no memo';

    const scrollRef = useRef<HTMLDivElement>(null);
    const virtualizer = useVirtualizer({
        count: entries.length,
        getScrollElement: () => scrollRef.current,
        estimateSize: () => ROW,
        overscan: 20,
    });

    const entriesRef = useRef(entries);
    const onSelectRef = useRef(onSelect);
    useEffect(() => {
        entriesRef.current = entries;
        onSelectRef.current = onSelect;
    });
    const select = useCallback(
        (index: number) => onSelectRef.current?.(entriesRef.current[index]),
        [],
    );

    const selectedIndex = selectedId
        ? entries.findIndex((entry) => entry.id === selectedId)
        : -1;

    const { follow, atEdge, unseen, onScroll, jumpToLatest } = useFollowScroll({
        scrollRef,
        count: entries.length,
        live,
        edge: 'start',
        scrollToEdge: () => virtualizer.scrollToOffset(0),
    });

    // Paused: lines prepended above keep the reader's rows where they are.
    const firstId = useRef<string | null>(null);
    useLayoutEffect(() => {
        const previous = firstId.current;
        firstId.current = entries[0]?.id ?? null;
        const element = scrollRef.current;

        if (!live || follow || previous === null || !element) {
            return;
        }

        const added = entries.findIndex((entry) => entry.id === previous);

        if (added > 0) {
            element.scrollTop += added * ROW;
        }
    }, [entries, follow, live]);

    // Keep the selected line in view: after ↑/↓, prev/next in the details
    // and when the message pane opens under the list and makes it shorter.
    useLayoutEffect(() => {
        const element = scrollRef.current;

        if (!element || selectedIndex < 0) {
            return;
        }

        const top = selectedIndex * ROW;

        if (top < element.scrollTop) {
            element.scrollTop = top;
        } else if (top + ROW > element.scrollTop + element.clientHeight) {
            element.scrollTop = top + ROW - element.clientHeight;
        }
        // Only when the selection moves, not on every new live line.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [selectedId]);

    if (entries.length === 0) {
        return <>{empty}</>;
    }

    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        const last = entries.length - 1;
        const moves: Record<string, number> = {
            ArrowDown: Math.min(last, selectedIndex + 1),
            ArrowUp: Math.max(0, selectedIndex - 1),
            Home: 0,
            End: last,
            PageDown: Math.min(last, selectedIndex + 20),
            PageUp: Math.max(0, selectedIndex - 20),
        };

        if (event.key in moves && onSelect) {
            event.preventDefault();
            const next = moves[event.key];
            onSelect(entries[next]);
        }
    };

    return (
        <div
            className={cn('@container/table flex min-h-0 flex-col', className)}
        >
            <div
                aria-hidden="true"
                className="flex h-8 shrink-0 items-center gap-3 border-b bg-(--recessed) px-4 type-small text-muted-foreground"
            >
                <span className={TIME_COLUMN}>Time</span>
                <span className="w-16 shrink-0">Level</span>
                {!hideService && (
                    <span
                        className={cn(SOURCE_COLUMN, context ? 'w-48' : 'w-28')}
                    >
                        {context ? 'Where' : 'Source'}
                    </span>
                )}
                <span>Message</span>
            </div>
            <div className="relative flex min-h-0 flex-1 flex-col">
                <div
                    ref={scrollRef}
                    role="grid"
                    aria-label="Log lines"
                    aria-rowcount={entries.length}
                    aria-activedescendant={
                        selectedIndex >= 0
                            ? `log-row-${entries[selectedIndex].id}`
                            : undefined
                    }
                    tabIndex={0}
                    onKeyDown={onKeyDown}
                    onScroll={onScroll}
                    className="min-h-0 flex-1 overflow-auto outline-none focus-visible:ring-2 focus-visible:ring-(--signal) focus-visible:ring-inset"
                >
                    <div
                        className="relative w-full"
                        style={{ height: virtualizer.getTotalSize() }}
                    >
                        {virtualizer.getVirtualItems().map((item) => {
                            const entry = entries[item.index];

                            return (
                                <LogRow
                                    key={entry.id}
                                    entry={entry}
                                    index={item.index}
                                    start={item.start}
                                    selected={entry.id === selectedId}
                                    highlight={highlight}
                                    hideService={hideService}
                                    context={context}
                                    onSelect={select}
                                />
                            );
                        })}
                    </div>
                </div>
                <JumpToLatest
                    visible={live && !atEdge}
                    live={live}
                    unseen={unseen}
                    edge="start"
                    onJump={jumpToLatest}
                />
            </div>
        </div>
    );
}
