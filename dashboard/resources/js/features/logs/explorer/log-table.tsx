import { useVirtualizer } from '@tanstack/react-virtual';
import { memo, useCallback, useEffect, useLayoutEffect, useRef } from 'react';
import type { KeyboardEvent, ReactNode } from 'react';
import { cn } from '@/lib/utils';
import { AnsiText } from '../ansi-text';
import { JumpToLatest, useFollowScroll } from '../use-follow-scroll';
import { LevelBadge } from './level-badge';
import type { LogEntry } from './types';

const ROW = 28;

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

    return (
        <div
            id={`log-row-${entry.id}`}
            role="row"
            aria-rowindex={index + 1}
            aria-selected={selected}
            onClick={() => onSelect(index)}
            className={cn(
                'absolute top-0 left-0 flex w-full cursor-default items-center gap-3 border-l-2 border-transparent px-4 type-mono-small hover:bg-(--hover)/70',
                selected && 'border-foreground bg-(--hover)',
                entry.level === 'error' &&
                    !selected &&
                    'max-sm:border-(--status-error)',
            )}
            style={{ transform: `translateY(${start}px)`, height: ROW }}
        >
            <span
                role="gridcell"
                className="w-40 shrink-0 text-muted-foreground tabular-nums max-sm:w-20"
            >
                <span className="max-sm:hidden">{time.day} </span>
                <span className="text-foreground">{time.clock}</span>
                <span className="max-sm:hidden">.{time.ms}</span>
            </span>
            <span role="gridcell" className="w-16 shrink-0 max-sm:sr-only">
                <LevelBadge level={entry.level} />
            </span>
            {!hideService && (
                <span
                    role="gridcell"
                    className={cn(
                        'shrink-0 truncate max-md:hidden',
                        context ? 'w-48' : 'w-28',
                    )}
                    title={
                        context
                            ? `${contextOf(entry, context)} / ${entry.service}`
                            : undefined
                    }
                >
                    {context && (
                        <span className="text-muted-foreground">
                            {contextOf(entry, context)} /{' '}
                        </span>
                    )}
                    {entry.service}
                    {entry.replica && (
                        <span className="text-muted-foreground">
                            {' '}
                            ·{entry.replica}
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
                <AnsiText text={entry.message} highlight={highlight} />
            </span>
        </div>
    );
});

/** Where a line is from in wide searches: its environment, or project · environment, before the service. */
export type LogContext = 'environment' | 'project' | null;

function contextOf(entry: LogEntry, context: LogContext): string {
    if (context === 'project') {
        return [entry.project, entry.environment].filter(Boolean).join(' · ');
    }

    return entry.environment ?? '';
}

export type LogTableProps = {
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
 * level chip, service + replica, message as ANSI-toned text (never HTML).
 * One tab stop: ↑/↓ move the selection, Enter opens it, Home/End jump.
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
            virtualizer.scrollToIndex(next, { align: 'auto' });
        }
    };

    return (
        <div className={cn('flex min-h-0 flex-col', className)}>
            <div
                aria-hidden="true"
                className="flex h-8 shrink-0 items-center gap-3 border-b px-4 type-small text-muted-foreground"
            >
                <span className="w-40 shrink-0 max-sm:w-20">Time</span>
                <span className="w-16 shrink-0 max-sm:sr-only">Level</span>
                {!hideService && (
                    <span
                        className={cn(
                            'shrink-0 max-md:hidden',
                            context ? 'w-48' : 'w-28',
                        )}
                    >
                        {context ? 'Where' : 'Service'}
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
