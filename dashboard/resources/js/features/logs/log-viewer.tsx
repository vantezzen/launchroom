import { defaultRangeExtractor, useVirtualizer } from '@tanstack/react-virtual';
import type { Range } from '@tanstack/react-virtual';
import {
    ChevronDown,
    ChevronRight,
    ChevronUp,
    Circle,
    CircleCheck,
    CircleDashed,
    LoaderCircle,
    Search,
    X,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Toggle } from '@/components/ui/toggle';
import { cn } from '@/lib/utils';
import { stripAnsi } from './ansi';
import { JumpToLatest, useFollowScroll } from './use-follow-scroll';
import { AnsiText } from './ansi-text';
import type { LogSection } from './sections';
import {
    firstErrorLine,
    isSection,
    SECTION_LABELS,
    SECTION_ORDER,
} from './sections';

type LogLine = App.Data.Deployments.LogLineData;

export type TimelineStepStatus =
    | 'pending'
    | 'running'
    | 'succeeded'
    | 'failed'
    | 'skipped';

export type TimelineStep = {
    section: LogSection;
    status: TimelineStepStatus;
    duration: string | null;
    message: string | null;
};

type Row =
    | { type: 'step'; step: TimelineStep; count: number; expanded: boolean }
    | { type: 'substep'; name: string; section: LogSection }
    | { type: 'line'; line: LogLine }
    | { type: 'empty'; section: LogSection; text: string }
    | { type: 'more' };

const ROW_HEIGHT = 20;
const STEP_HEIGHT = 36;

function stepIcon(status: TimelineStepStatus): ReactNode {
    switch (status) {
        case 'running':
            return (
                <LoaderCircle
                    aria-hidden="true"
                    className="size-3.5 text-status-progress motion-safe:animate-spin"
                />
            );
        case 'succeeded':
            return (
                <CircleCheck
                    aria-hidden="true"
                    className="size-3.5 text-status-success"
                />
            );
        case 'failed':
            return (
                <X aria-hidden="true" className="size-3.5 text-status-error" />
            );
        case 'skipped':
            return (
                <CircleDashed
                    aria-hidden="true"
                    className="size-3.5 text-status-idle"
                />
            );
        default:
            return (
                <Circle
                    aria-hidden="true"
                    className="size-3.5 text-status-idle"
                />
            );
    }
}

const statusLabels: Record<TimelineStepStatus, string> = {
    pending: 'Pending',
    running: 'Running',
    succeeded: 'Done',
    failed: 'Failed',
    skipped: 'Skipped',
};

function time(iso: string): string {
    const date = new Date(iso);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return date.toISOString().slice(11, 23);
}

/**
 * Deployment step timeline with its logs (docs/08 §3.5, design-system §6):
 * one virtualized list of step headers (sticky, collapsible) and log lines
 * (mono-small, line numbers with `#L42` anchors, ANSI toned to the palette,
 * rendered as text). Search (`/` to focus), wrap, timestamps, follow and
 * jump-to-bottom. The failing step opens on its first error line.
 */
export function LogViewer({
    steps,
    lines,
    hasMore,
    loadingMore,
    onLoadMore,
    live,
    following = false,
    className,
}: {
    steps: TimelineStep[];
    lines: LogLine[];
    hasMore: boolean;
    loadingMore: boolean;
    onLoadMore: () => void;
    live: boolean;
    /** Start in follow mode (the deployment is still in progress). */
    following?: boolean;
    className?: string;
}) {
    const scrollRef = useRef<HTMLDivElement>(null);
    const searchRef = useRef<HTMLInputElement>(null);
    const [wrap, setWrap] = useState(false);
    const [timestamps, setTimestamps] = useState(false);
    const [query, setQuery] = useState('');
    // -1: matches highlighted, none selected yet (Enter jumps to the first).
    const [matchIndex, setMatchIndex] = useState(-1);
    const [selected, setSelected] = useState<number | null>(null);

    const plain = useMemo(() => {
        const cache = new Map<number, string>();

        return (line: LogLine): string => {
            let text = cache.get(line.number);

            if (text === undefined) {
                text = stripAnsi(line.text);
                cache.set(line.number, text);
            }

            return text;
        };
    }, []);

    const bySection = useMemo(() => {
        const map = new Map<LogSection, LogLine[]>();

        for (const line of lines) {
            const section: LogSection = isSection(line.section)
                ? line.section
                : 'rollout';
            const list = map.get(section) ?? [];
            list.push(line);
            map.set(section, list);
        }

        return map;
    }, [lines]);

    // Steps with logs but no timeline entry (runtime logs, unknown steps).
    const allSteps = useMemo(() => {
        const known = new Set(steps.map((step) => step.section));
        const extra = SECTION_ORDER.filter(
            (section) => !known.has(section) && bySection.has(section),
        ).map((section): TimelineStep => ({
            section,
            status: section === 'runtime' && live ? 'running' : 'succeeded',
            duration: null,
            message: null,
        }));

        return [...steps, ...extra].sort(
            (a, b) =>
                SECTION_ORDER.indexOf(a.section) -
                SECTION_ORDER.indexOf(b.section),
        );
    }, [steps, bySection, live]);

    const failed =
        allSteps.find((step) => step.status === 'failed')?.section ?? null;
    const errorLine = useMemo(
        () =>
            failed ? firstErrorLine(bySection.get(failed) ?? [], plain) : null,
        [failed, bySection, plain],
    );

    const [expanded, setExpanded] = useState<Set<LogSection>>(() => {
        const open = new Set<LogSection>();

        for (const step of allSteps) {
            if (step.status === 'failed' || step.status === 'running') {
                open.add(step.section);
            }
        }

        // Finished without failure: everything that has output.
        if (open.size === 0) {
            for (const step of allSteps) {
                if (bySection.has(step.section)) {
                    open.add(step.section);
                }
            }
        }

        return open;
    });

    // A step that starts running opens; one that fails opens too.
    const statusKey = allSteps
        .map((step) => `${step.section}:${step.status}`)
        .join(',');
    const seenStatus = useRef(statusKey);

    useEffect(() => {
        if (seenStatus.current === statusKey) {
            return;
        }

        seenStatus.current = statusKey;
        setExpanded((current) => {
            const next = new Set(current);

            for (const step of allSteps) {
                if (step.status === 'running' || step.status === 'failed') {
                    next.add(step.section);
                }
            }

            return next;
        });
    }, [statusKey, allSteps]);

    const rows = useMemo(() => {
        const result: Row[] = [];

        for (const step of allSteps) {
            const sectionLines = bySection.get(step.section) ?? [];
            const open = expanded.has(step.section);
            result.push({
                type: 'step',
                step,
                count: sectionLines.length,
                expanded: open,
            });

            if (!open) {
                continue;
            }

            if (sectionLines.length === 0) {
                result.push({
                    type: 'empty',
                    section: step.section,
                    text:
                        step.message ??
                        (step.status === 'pending'
                            ? 'Waiting for the previous steps.'
                            : step.status === 'running'
                              ? 'Waiting for output…'
                              : 'No output for this step.'),
                });

                continue;
            }

            let sub: string | null = null;

            for (const line of sectionLines) {
                if (
                    line.step &&
                    line.step !== sub &&
                    line.step !== step.section
                ) {
                    sub = line.step;
                    result.push({
                        type: 'substep',
                        name: line.step,
                        section: step.section,
                    });
                }

                result.push({ type: 'line', line });
            }
        }

        if (hasMore) {
            result.push({ type: 'more' });
        }

        return result;
    }, [allSteps, bySection, expanded, hasMore]);

    const rowsRef = useRef(rows);

    useEffect(() => {
        rowsRef.current = rows;
    }, [rows]);

    const stickyIndexes = useMemo(
        () =>
            rows.flatMap((row, index) => (row.type === 'step' ? [index] : [])),
        [rows],
    );
    const activeSticky = useRef(0);

    const virtualizer = useVirtualizer({
        count: rows.length,
        getScrollElement: () => scrollRef.current,
        estimateSize: (index) =>
            rows[index]?.type === 'step' ? STEP_HEIGHT : ROW_HEIGHT,
        overscan: 30,
        rangeExtractor: useCallback(
            (range: Range) => {
                activeSticky.current =
                    [...stickyIndexes]
                        .reverse()
                        .find((index) => range.startIndex >= index) ?? 0;

                return [
                    ...new Set([
                        activeSticky.current,
                        ...defaultRangeExtractor(range),
                    ]),
                ].sort((a, b) => a - b);
            },
            [stickyIndexes],
        ),
    });

    // Follow new output; scrolling up pauses, back at the bottom resumes.
    const { follow, setFollow, atEdge, unseen, onScroll, jumpToLatest } =
        useFollowScroll({
            scrollRef,
            count: rows.length,
            live,
            initial: following,
            scrollToEdge: () =>
                virtualizer.scrollToIndex(rows.length - 1, { align: 'end' }),
        });

    // Search matches (plain text, case-insensitive) across all lines.
    const needle = query.trim().toLowerCase();
    const matches = useMemo(
        () =>
            needle === ''
                ? []
                : lines
                      .filter((line) =>
                          plain(line).toLowerCase().includes(needle),
                      )
                      .map((line) => line.number),
        [lines, needle, plain],
    );

    const reveal = useCallback(
        (number: number, align: 'center' | 'start' = 'center') => {
            const line = lines.find((item) => item.number === number);

            if (!line) {
                return;
            }

            const section: LogSection = isSection(line.section)
                ? line.section
                : 'rollout';
            setFollow(false);
            setExpanded((current) =>
                current.has(section) ? current : new Set([...current, section]),
            );
            // Rows are rebuilt after the expansion; scroll on the next frame.
            requestAnimationFrame(() =>
                requestAnimationFrame(() => {
                    const index = rowsRef.current.findIndex(
                        (row) =>
                            row.type === 'line' && row.line.number === number,
                    );

                    if (index >= 0) {
                        virtualizer.scrollToIndex(index, { align });
                    }
                }),
            );
        },
        [lines, virtualizer],
    );

    const goToMatch = (direction: 1 | -1) => {
        if (matches.length === 0) {
            return;
        }

        const next =
            matchIndex < 0
                ? direction === 1
                    ? 0
                    : matches.length - 1
                : (matchIndex + direction + matches.length) % matches.length;
        setMatchIndex(next);
        reveal(matches[next]);
    };

    // `#L42` anchors, then the first error of a failed step.
    const initialised = useRef(false);

    useEffect(() => {
        if (initialised.current) {
            return;
        }

        initialised.current = true;
        const anchor = /^#L(\d+)$/.exec(window.location.hash);

        if (anchor) {
            const number = Number(anchor[1]);
            setSelected(number);
            reveal(number);

            return;
        }

        if (errorLine !== null) {
            reveal(errorLine);
        } else if (live) {
            requestAnimationFrame(() =>
                virtualizer.scrollToIndex(rowsRef.current.length - 1, {
                    align: 'end',
                }),
            );
        }
    }, [errorLine, live, reveal, virtualizer]);

    useEffect(() => {
        const onHash = () => {
            const anchor = /^#L(\d+)$/.exec(window.location.hash);

            if (anchor) {
                setSelected(Number(anchor[1]));
                reveal(Number(anchor[1]));
            }
        };

        window.addEventListener('hashchange', onHash);

        return () => window.removeEventListener('hashchange', onHash);
    }, [reveal]);

    // `/` focuses the log search (docs/08 §4).
    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            const target = event.target as HTMLElement | null;

            if (
                event.key !== '/' ||
                event.metaKey ||
                event.ctrlKey ||
                event.altKey ||
                target?.closest(
                    'input, textarea, select, [contenteditable="true"], [role="dialog"]',
                )
            ) {
                return;
            }

            event.preventDefault();
            searchRef.current?.focus();
        };

        document.addEventListener('keydown', onKey);

        return () => document.removeEventListener('keydown', onKey);
    }, []);

    const toggleSection = (section: LogSection) =>
        setExpanded((current) => {
            const next = new Set(current);

            if (next.has(section)) {
                next.delete(section);
            } else {
                next.add(section);
            }

            return next;
        });

    const items = virtualizer.getVirtualItems();
    const currentMatch = matches[matchIndex] ?? null;

    return (
        <div className={cn('flex min-h-0 flex-col', className)}>
            <div className="flex flex-wrap items-center gap-2 border-b px-3 py-2">
                <div className="relative flex min-w-48 flex-1 items-center sm:max-w-sm">
                    <Search
                        aria-hidden="true"
                        className="pointer-events-none absolute left-2.5 size-4 text-muted-foreground"
                    />
                    <Input
                        ref={searchRef}
                        type="search"
                        value={query}
                        onChange={(event) => {
                            setQuery(event.target.value);
                            setMatchIndex(-1);
                        }}
                        onKeyDown={(event) => {
                            if (event.key === 'Enter') {
                                event.preventDefault();
                                goToMatch(event.shiftKey ? -1 : 1);
                            }

                            if (event.key === 'Escape') {
                                setQuery('');
                                searchRef.current?.blur();
                            }
                        }}
                        placeholder="Search logs"
                        aria-label="Search logs"
                        className="h-8 pr-16 pl-8 type-mono-small"
                    />
                    {query === '' && (
                        <kbd className="pointer-events-none absolute right-2 rounded border px-1 type-mono-small text-muted-foreground">
                            /
                        </kbd>
                    )}
                </div>
                {needle !== '' && (
                    <div className="flex items-center gap-1">
                        <span
                            className="type-small text-muted-foreground tabular-nums"
                            aria-live="polite"
                        >
                            {matches.length === 0
                                ? 'No matches'
                                : `${Math.max(matchIndex, 0) + 1} of ${matches.length}`}
                        </span>
                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-8"
                            aria-label="Previous match"
                            onClick={() => goToMatch(-1)}
                        >
                            <ChevronUp />
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-8"
                            aria-label="Next match"
                            onClick={() => goToMatch(1)}
                        >
                            <ChevronDown />
                        </Button>
                    </div>
                )}
                <div className="ml-auto flex items-center gap-1">
                    <Toggle
                        size="sm"
                        pressed={wrap}
                        onPressedChange={setWrap}
                        className="type-label"
                    >
                        Wrap
                    </Toggle>
                    <Toggle
                        size="sm"
                        pressed={timestamps}
                        onPressedChange={setTimestamps}
                        className="type-label"
                    >
                        Timestamps
                    </Toggle>
                    {live && (
                        <Toggle
                            size="sm"
                            pressed={follow}
                            onPressedChange={setFollow}
                            className="type-label"
                        >
                            Follow
                        </Toggle>
                    )}
                </div>
            </div>

            <div className="relative min-h-0 flex-1">
                <div
                    ref={scrollRef}
                    onScroll={onScroll}
                    className="h-full overflow-auto"
                    role="log"
                    aria-label="Deployment logs"
                    aria-live="off"
                >
                    <div
                        className="relative w-full"
                        style={{ height: virtualizer.getTotalSize() }}
                    >
                        {items.map((item) => {
                            const row = rows[item.index];
                            const sticky =
                                row.type === 'step' &&
                                activeSticky.current === item.index;

                            return (
                                <div
                                    key={item.key}
                                    data-index={item.index}
                                    ref={virtualizer.measureElement}
                                    className={cn(
                                        'left-0 w-full',
                                        sticky
                                            ? 'sticky top-0 z-10'
                                            : 'absolute top-0',
                                        !wrap &&
                                            row.type === 'line' &&
                                            'min-w-max',
                                    )}
                                    style={
                                        sticky
                                            ? undefined
                                            : {
                                                  transform: `translateY(${item.start}px)`,
                                              }
                                    }
                                >
                                    {row.type === 'step' && (
                                        <button
                                            type="button"
                                            onClick={() =>
                                                toggleSection(row.step.section)
                                            }
                                            aria-expanded={row.expanded}
                                            className="flex h-9 w-full items-center gap-2 border-b bg-background px-3 text-left hover:bg-muted/60 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
                                        >
                                            {row.expanded ? (
                                                <ChevronDown
                                                    aria-hidden="true"
                                                    className="size-4 text-muted-foreground"
                                                />
                                            ) : (
                                                <ChevronRight
                                                    aria-hidden="true"
                                                    className="size-4 text-muted-foreground"
                                                />
                                            )}
                                            {stepIcon(row.step.status)}
                                            <span className="type-label">
                                                {
                                                    SECTION_LABELS[
                                                        row.step.section
                                                    ]
                                                }
                                            </span>
                                            <span className="sr-only">
                                                {statusLabels[row.step.status]}
                                            </span>
                                            {row.step.message &&
                                                !row.expanded && (
                                                    <span className="min-w-0 truncate type-mono-small text-muted-foreground">
                                                        {row.step.message}
                                                    </span>
                                                )}
                                            <span className="ml-auto flex shrink-0 items-center gap-3 type-small text-muted-foreground tabular-nums">
                                                {row.count > 0 && (
                                                    <span>
                                                        {row.count.toLocaleString()}{' '}
                                                        lines
                                                    </span>
                                                )}
                                                {row.step.duration && (
                                                    <span>
                                                        {row.step.duration}
                                                    </span>
                                                )}
                                            </span>
                                        </button>
                                    )}
                                    {row.type === 'substep' && (
                                        <div className="flex h-5 items-center bg-muted/40 pl-[4.75rem] type-mono-small text-muted-foreground">
                                            {row.name}
                                        </div>
                                    )}
                                    {row.type === 'empty' && (
                                        <div className="py-2 pl-[4.75rem] type-small text-muted-foreground">
                                            {row.text}
                                        </div>
                                    )}
                                    {row.type === 'more' && (
                                        <div className="flex justify-center py-3">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={onLoadMore}
                                                disabled={loadingMore}
                                            >
                                                {loadingMore
                                                    ? 'Loading…'
                                                    : 'Load more lines'}
                                            </Button>
                                        </div>
                                    )}
                                    {row.type === 'line' && (
                                        <LogLineRow
                                            line={row.line}
                                            wrap={wrap}
                                            timestamps={timestamps}
                                            query={needle}
                                            isError={
                                                row.line.number === errorLine
                                            }
                                            isSelected={
                                                row.line.number === selected
                                            }
                                            isMatch={
                                                row.line.number === currentMatch
                                            }
                                            onSelect={(number) => {
                                                setSelected(number);
                                                window.history.replaceState(
                                                    window.history.state,
                                                    '',
                                                    `#L${number}`,
                                                );
                                            }}
                                        />
                                    )}
                                </div>
                            );
                        })}
                    </div>
                    {lines.length === 0 && allSteps.length === 0 && (
                        <p className="p-6 type-body text-muted-foreground">
                            No logs yet.
                        </p>
                    )}
                </div>

                <JumpToLatest
                    visible={!atEdge && rows.length > 0}
                    live={live}
                    unseen={unseen}
                    onJump={jumpToLatest}
                />
            </div>
        </div>
    );
}

function LogLineRow({
    line,
    wrap,
    timestamps,
    query,
    isError,
    isSelected,
    isMatch,
    onSelect,
}: {
    line: LogLine;
    wrap: boolean;
    timestamps: boolean;
    query: string;
    isError: boolean;
    isSelected: boolean;
    isMatch: boolean;
    onSelect: (number: number) => void;
}) {
    return (
        <div
            data-line={line.number}
            className={cn(
                'flex border-l-2 border-transparent type-mono-small',
                isError && 'border-status-error',
                isSelected && 'bg-accent',
                isMatch && 'bg-status-progress/10',
                line.stream === 'system' && 'text-muted-foreground',
            )}
        >
            <a
                href={`#L${line.number}`}
                onClick={(event) => {
                    event.preventDefault();
                    onSelect(line.number);
                }}
                className="sticky left-0 w-14 shrink-0 bg-background pr-3 text-right text-muted-foreground tabular-nums select-none hover:text-foreground"
                aria-label={`Line ${line.number}`}
            >
                {line.number}
            </a>
            {isError && <span className="sr-only">First error: </span>}
            {timestamps && (
                <span className="w-24 shrink-0 pr-3 text-muted-foreground tabular-nums select-none">
                    {time(line.time)}
                </span>
            )}
            <span
                className={cn(
                    'min-w-0 pr-4',
                    wrap ? 'break-all whitespace-pre-wrap' : 'whitespace-pre',
                )}
            >
                <AnsiText text={line.text} highlight={query} />
            </span>
        </div>
    );
}
