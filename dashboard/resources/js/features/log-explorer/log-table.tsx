import { useVirtualizer } from '@tanstack/react-virtual';
import { memo, useCallback, useEffect, useLayoutEffect, useRef } from 'react';
import type { KeyboardEvent, ReactNode } from 'react';
import { useIsMobile } from '@/hooks/use-mobile';
import { cn } from '@/lib/utils';
import { AnsiText } from '@/features/logs/ansi-text';
import {
    JumpToLatest,
    useFollowScroll,
} from '@/features/logs/use-follow-scroll';
import { LevelWord } from './level-badge';
import { structuredLine } from './structured';
import type { LogEntry } from './types';

const ROW = 28;
/** Phones: time and source over two lines of message. */
const ROW_TWO_LINE = 58;
// One-line rows (desktop and panels). Columns follow the table's own width (it sits next to the details or in
// the service panel), not the viewport: the day from 672 px, the
// milliseconds from 448 px, the source from 512 px.
const TIME_COLUMN = 'w-16 shrink-0 @md/table:w-24 @2xl/table:w-40';
// The message takes the rest. In wide searches the source names the
// service, and the environment (or project / environment) joins it only
// where the table has room for it (from 896 px); the tooltip has it always.
const SOURCE_COLUMN = 'w-28 shrink-0 @max-lg/table:hidden';
const SOURCE_WITH_CONTEXT = '@4xl/table:w-48';

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

/** Level as a 2 px left rule (warning and error only); info and debug stay quiet. */
const RULE: Record<LogEntry['level'], string | undefined> = {
    error: 'shadow-[inset_2px_0_0_var(--status-error)]',
    warning: 'shadow-[inset_2px_0_0_var(--status-progress)]',
    info: undefined,
    debug: undefined,
};

function SourceText({
    entry,
    context,
    responsive = false,
}: {
    entry: LogEntry;
    context: LogContext;
    /** One-line rows: the context only where the column is wide. */
    responsive?: boolean;
}) {
    // The service leads: it is what tells lines apart; where it runs
    // follows in grey and is the part that gets cut.
    return (
        <>
            {entry.service}
            {entry.replica && (
                <span className="text-muted-foreground"> #{entry.replica}</span>
            )}
            {context && (
                <span
                    className={cn(
                        'text-muted-foreground',
                        responsive && 'hidden @4xl/table:inline',
                    )}
                >
                    {' '}
                    {contextOf(entry, context)}
                </span>
            )}
        </>
    );
}

const LogRow = memo(function LogRow({
    entry,
    index,
    start,
    height,
    twoLine,
    selected,
    anchor,
    highlight,
    hideService,
    context,
    onSelect,
}: {
    entry: LogEntry;
    index: number;
    start: number;
    height: number;
    twoLine: boolean;
    selected: boolean;
    anchor: boolean;
    highlight: string;
    hideService: boolean;
    context: LogContext;
    onSelect: (index: number) => void;
}) {
    const time = splitTime(entry.time);
    const error = entry.level === 'error';
    const message = (
        <>
            <LevelWord level={entry.level} />
            <AnsiText
                text={structuredLine(entry.message).summary}
                highlight={highlight}
            />
        </>
    );

    return (
        <div
            id={`log-row-${entry.id}`}
            role="row"
            aria-rowindex={index + 1}
            aria-selected={selected}
            onClick={() => onSelect(index)}
            className={cn(
                'absolute top-0 left-0 flex w-full cursor-default gap-3 px-4 type-mono-small',
                twoLine ? 'flex-col justify-center gap-0.5' : 'items-center',
                RULE[entry.level],
                error
                    ? 'bg-status-error-soft/50 hover:bg-status-error-soft'
                    : 'hover:bg-foreground/[0.04]',
                selected && 'bg-foreground/[0.08] hover:bg-foreground/[0.08]',
                anchor &&
                    'bg-signal-soft/70 shadow-[inset_2px_0_0_var(--signal)] hover:bg-signal-soft',
            )}
            style={{ transform: `translateY(${start}px)`, height }}
        >
            {twoLine ? (
                <>
                    <span
                        role="gridcell"
                        className="flex min-w-0 items-center gap-2 text-muted-foreground"
                    >
                        <span className="shrink-0 text-foreground tabular-nums">
                            {time.clock}
                        </span>
                        {!hideService && (
                            <span className="min-w-0 truncate">
                                <SourceText entry={entry} context={context} />
                            </span>
                        )}
                    </span>
                    <span
                        role="gridcell"
                        className={cn(
                            'line-clamp-2 min-w-0 [overflow-wrap:anywhere] whitespace-normal',
                            entry.stream === 'system' &&
                                'text-muted-foreground',
                        )}
                    >
                        {message}
                    </span>
                </>
            ) : (
                <>
                    <span
                        role="gridcell"
                        className={cn(
                            TIME_COLUMN,
                            'text-muted-foreground tabular-nums',
                        )}
                    >
                        <span className="hidden @2xl/table:inline">
                            {time.day}{' '}
                        </span>
                        <span className="text-foreground">{time.clock}</span>
                        <span className="hidden @md/table:inline">
                            .{time.ms}
                        </span>
                    </span>
                    {!hideService && (
                        <span
                            role="gridcell"
                            className={cn(
                                SOURCE_COLUMN,
                                'truncate',
                                context && SOURCE_WITH_CONTEXT,
                            )}
                            title={
                                context
                                    ? `${contextOf(entry, context)} / ${entry.service}`
                                    : undefined
                            }
                        >
                            <SourceText
                                entry={entry}
                                context={context}
                                responsive
                            />
                        </span>
                    )}
                    <span
                        role="gridcell"
                        className={cn(
                            'min-w-0 flex-1 truncate',
                            entry.stream === 'system' &&
                                'text-muted-foreground',
                        )}
                    >
                        {message}
                    </span>
                </>
            )}
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
    /** A line marked with a signal-blue rule ("show surrounding lines"). */
    anchorId?: string | null;
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
 * service + replica, message as ANSI-toned text (never HTML); a JSON line
 * shows its `msg`. Level is a 2 px left rule plus the word before the
 * message for warnings and errors; errors keep a faint red wash. Phones
 * get two-line rows: time and source over the message. One tab stop: ↑/↓ move the selection and keep it
 * in view, Home/End jump. The caller draws the frame around it.
 */
export function LogTable({
    entries,
    selectedId = null,
    anchorId = null,
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

    const twoLine = useIsMobile();
    const row = twoLine ? ROW_TWO_LINE : ROW;
    const scrollRef = useRef<HTMLDivElement>(null);
    const virtualizer = useVirtualizer({
        count: entries.length,
        getScrollElement: () => scrollRef.current,
        estimateSize: () => row,
        overscan: 20,
    });
    useEffect(() => virtualizer.measure(), [row, virtualizer]);

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
            element.scrollTop += added * row;
        }
    }, [entries, follow, live, row]);

    // Keep the selected line in view: after ↑/↓, prev/next in the details
    // and when the message pane opens under the list and makes it shorter.
    useLayoutEffect(() => {
        const element = scrollRef.current;

        if (!element || selectedIndex < 0) {
            return;
        }

        const top = selectedIndex * row;

        if (top < element.scrollTop) {
            element.scrollTop = top;
        } else if (top + row > element.scrollTop + element.clientHeight) {
            element.scrollTop = top + row - element.clientHeight;
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
                className={cn(
                    'flex h-8 shrink-0 items-center gap-3 border-b bg-(--recessed) px-4 type-small text-muted-foreground',
                    twoLine && 'hidden',
                )}
            >
                <span className={TIME_COLUMN}>Time</span>
                {!hideService && (
                    <span
                        className={cn(
                            SOURCE_COLUMN,
                            context && SOURCE_WITH_CONTEXT,
                        )}
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
                                    height={row}
                                    twoLine={twoLine}
                                    selected={entry.id === selectedId}
                                    anchor={entry.id === anchorId}
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
