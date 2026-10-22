import { defaultRangeExtractor, useVirtualizer } from '@tanstack/react-virtual';
import type { Range } from '@tanstack/react-virtual';
import {
    Check,
    ChevronDown,
    ChevronRight,
    ChevronUp,
    Copy,
    Download,
    Search,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { StepChip } from '@/components/steps/step-list';
import { StepIcon, STEP_STATUS_LABEL } from '@/components/steps/step-icon';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Toggle } from '@/components/ui/toggle';
import { cn } from '@/lib/utils';
import { AnsiText } from './ansi-text';
import type { LogSection } from './sections';
import { SECTION_LABELS } from './sections';
import type { LogLine, StepRow, TimelineStep } from './step-log';
import {
    buildRows,
    expandedAfterChange,
    failedStepErrors,
    findMatches,
    groupBySection,
    initialExpanded,
    isExpandable,
    logText,
    normalizeLine,
    sectionOf,
    withLoggedSteps,
} from './step-log';
import { JumpToLatest, useFollowScroll } from './use-follow-scroll';

export type { TimelineStep } from './step-log';

const ROW_HEIGHT = 20;
const STEP_HEIGHT = 40;

function time(iso: string): string {
    const date = new Date(iso);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return date.toISOString().slice(11, 23);
}

/**
 * The deployment's Steps (docs/08 §3.5, design-system §6): one virtualized
 * list of step headers (sticky, collapsible: status icon and word, error
 * count, lines, duration) and their log lines (mono-small, line numbers
 * with `#L42` anchors, ANSI toned to the palette, rendered as text).
 * The running step is open and followed; the failed step opens on its first
 * error with every error line marked. Search across all steps (`/`, Enter
 * / Shift+Enter, "Only matches"), expand all, wrap, timestamps, follow,
 * copy and download.
 */
export function LogViewer({
    steps,
    lines: rawLines,
    hasMore,
    loadingMore,
    onLoadMore,
    onLoadAll,
    live,
    following = false,
    fileName = 'deployment.log',
    className,
}: {
    steps: TimelineStep[];
    lines: LogLine[];
    hasMore: boolean;
    loadingMore: boolean;
    onLoadMore: () => void;
    /** Every line, loading pages not fetched yet (copy, download). */
    onLoadAll?: () => Promise<LogLine[]>;
    live: boolean;
    /** Start in follow mode (the deployment is still in progress). */
    following?: boolean;
    fileName?: string;
    className?: string;
}) {
    const scrollRef = useRef<HTMLDivElement>(null);
    const searchRef = useRef<HTMLInputElement>(null);
    const [wrap, setWrap] = useState(false);
    const [timestamps, setTimestamps] = useState(false);
    const [query, setQuery] = useState('');
    const [onlyMatches, setOnlyMatches] = useState(false);
    // -1: matches highlighted, none selected yet (Enter jumps to the first).
    const [matchIndex, setMatchIndex] = useState(-1);
    const [selected, setSelected] = useState<number | null>(null);
    const [expandAll, setExpandAll] = useState(false);
    const [copied, setCopied] = useState(false);
    const [announcement, setAnnouncement] = useState('');
    const [loadingAll, setLoadingAll] = useState(false);
    const [loadError, setLoadError] = useState(false);

    const lines = useMemo(() => rawLines.map(normalizeLine), [rawLines]);
    const bySection = useMemo(() => groupBySection(lines), [lines]);
    const allSteps = useMemo(
        () => withLoggedSteps(steps, bySection, live),
        [steps, bySection, live],
    );
    const errors = useMemo(
        () => failedStepErrors(allSteps, bySection),
        [allSteps, bySection],
    );

    const [expanded, setExpanded] = useState<Set<LogSection>>(() =>
        initialExpanded(allSteps),
    );
    const touched = useRef(new Set<LogSection>());

    const needle = query.trim().toLowerCase();

    useEffect(() => {
        if (!hasMore) {
            setLoadingAll(false);
        }
        if (
            !hasMore ||
            !onLoadAll ||
            loadError ||
            (!following &&
                needle === '' &&
                errors.section === null &&
                !/^#L\d+$/.test(window.location.hash))
        ) {
            return;
        }
        let active = true;
        setLoadingAll(true);
        void onLoadAll()
            .catch(() => {
                if (active) {
                    setLoadError(true);
                }
            })
            .finally(() => {
                if (active) {
                    setLoadingAll(false);
                }
            });
        return () => {
            active = false;
        };
    }, [hasMore, onLoadAll, following, needle, errors.section, loadError]);
    const matches = useMemo(() => findMatches(lines, needle), [lines, needle]);
    const only = useMemo(
        () => (onlyMatches && needle !== '' ? new Set(matches) : null),
        [onlyMatches, needle, matches],
    );

    const openSteps = useMemo(
        () =>
            expandAll
                ? new Set(
                      allSteps
                          .filter((step) => isExpandable(step, bySection))
                          .map((step) => step.section),
                  )
                : expanded,
        [expandAll, expanded, allSteps, bySection],
    );

    const rows = useMemo(
        () =>
            buildRows({
                steps: allSteps,
                bySection,
                expanded: openSteps,
                errors,
                hasMore,
                only,
            }),
        [allSteps, bySection, openSteps, errors, hasMore, only],
    );

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
                virtualizer.scrollToIndex(rowsRef.current.length - 1, {
                    align: 'end',
                }),
        });

    // A step that starts running or fails opens (and is announced); while
    // following, a step that finished fine closes so the running one stays
    // in view.
    const statusKey = allSteps
        .map((step) => `${step.section}:${step.status}`)
        .join(',');
    const previousSteps = useRef(allSteps);
    const seenStatus = useRef(statusKey);
    const followRef = useRef(follow);

    useEffect(() => {
        followRef.current = follow;
    }, [follow]);

    useEffect(() => {
        if (seenStatus.current === statusKey) {
            previousSteps.current = allSteps;

            return;
        }

        seenStatus.current = statusKey;
        const previous = previousSteps.current;
        previousSteps.current = allSteps;
        const before = new Map(
            previous.map((step) => [step.section, step.status]),
        );
        const changed = allSteps.filter(
            (step) =>
                before.has(step.section) &&
                before.get(step.section) !== step.status,
        );

        setExpanded((current) =>
            expandedAfterChange(
                current,
                previous,
                allSteps,
                touched.current,
                followRef.current,
            ),
        );

        if (changed.length > 0) {
            setAnnouncement(
                changed
                    .map(
                        (step) =>
                            `${SECTION_LABELS[step.section]}: ${STEP_STATUS_LABEL[step.status]}`,
                    )
                    .join('. '),
            );
        }
    }, [statusKey, allSteps]);

    const reveal = useCallback(
        (number: number, align: 'center' | 'start' = 'center') => {
            const line = lines.find((item) => item.number === number);

            if (!line) {
                return;
            }

            const section = sectionOf(line);
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
        [lines, setFollow, virtualizer],
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

    // `#L42` anchors, then the first error of a failed step, else the end
    // of a live log.
    const initialised = useRef(false);

    useEffect(() => {
        if (initialised.current || hasMore) {
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

        if (errors.first !== null) {
            reveal(errors.first);
        } else if (live) {
            requestAnimationFrame(() =>
                virtualizer.scrollToIndex(rowsRef.current.length - 1, {
                    align: 'end',
                }),
            );
        }
    }, [errors.first, hasMore, live, reveal, virtualizer]);

    // A live deployment that fails: stop following and show the error.
    const seenFirstError = useRef<number | null>(null);

    useEffect(() => {
        if (hasMore || seenFirstError.current === errors.first) {
            return;
        }

        seenFirstError.current = errors.first;

        if (errors.first !== null) {
            reveal(errors.first);
        }
    }, [errors.first, hasMore, reveal]);

    useEffect(() => {
        const onHash = () => {
            const anchor = /^#L(\d+)$/.exec(window.location.hash);

            if (anchor) {
                setOnlyMatches(false);
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

    const toggleSection = (section: LogSection) => {
        touched.current.add(section);

        if (expandAll) {
            setExpandAll(false);
            setExpanded(
                new Set([...openSteps].filter((item) => item !== section)),
            );

            return;
        }

        setExpanded((current) => {
            const next = new Set(current);

            if (next.has(section)) {
                next.delete(section);
            } else {
                next.add(section);
            }

            return next;
        });
    };

    const fullText = async (): Promise<string> => {
        const all = onLoadAll ? (await onLoadAll()).map(normalizeLine) : lines;

        return logText(
            withLoggedSteps(steps, groupBySection(all), live),
            groupBySection(all),
        );
    };

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(await fullText());
            setCopied(true);
            setAnnouncement('Log copied');
            window.setTimeout(() => setCopied(false), 2000);
        } catch {
            setAnnouncement('Could not copy the log');
        }
    };

    const download = async () => {
        try {
            const url = URL.createObjectURL(
                new Blob([await fullText()], {
                    type: 'text/plain;charset=utf-8',
                }),
            );
            const link = document.createElement('a');
            link.href = url;
            link.download = fileName;
            link.click();
            window.setTimeout(() => URL.revokeObjectURL(url), 1000);
        } catch {
            setAnnouncement('Could not download the full log. Try again.');
        }
    };

    const items = virtualizer.getVirtualItems();
    const currentMatch = matches[matchIndex] ?? null;
    const totalSize = virtualizer.getTotalSize();

    return (
        <div
            className={cn(
                'flex min-h-0 flex-col overflow-hidden rounded-[14px] border bg-card shadow-[0_1px_2px_rgb(0_0_0/0.04)] dark:shadow-none',
                className,
            )}
        >
            <div className="flex flex-wrap items-center gap-2 border-b px-3 py-2">
                <div className="relative flex min-w-0 flex-1 basis-56 items-center sm:max-w-sm">
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
                        placeholder="Search all steps"
                        aria-label="Search all steps"
                        className="h-8 pr-8 pl-8 type-mono-small"
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
                        <Toggle
                            size="sm"
                            pressed={onlyMatches}
                            onPressedChange={setOnlyMatches}
                            className="type-label"
                        >
                            Only matches
                        </Toggle>
                    </div>
                )}
                <div className="ml-auto flex flex-wrap items-center gap-1">
                    <Toggle
                        size="sm"
                        pressed={expandAll}
                        onPressedChange={(pressed) => {
                            setExpandAll(pressed);

                            if (!pressed) {
                                setExpanded(initialExpanded(allSteps));
                            }
                        }}
                        className="type-label"
                    >
                        Expand all
                    </Toggle>
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
                    <Button
                        variant="ghost"
                        size="icon"
                        className="size-8"
                        aria-label={copied ? 'Log copied' : 'Copy log'}
                        title="Copy log"
                        disabled={lines.length === 0}
                        onClick={() => void copy()}
                    >
                        {copied ? <Check /> : <Copy />}
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        className="size-8"
                        aria-label="Download log"
                        title="Download log"
                        disabled={lines.length === 0}
                        onClick={() => void download()}
                    >
                        <Download />
                    </Button>
                </div>
            </div>

            <p className="sr-only" aria-live="polite" role="status">
                {announcement}
            </p>
            {(loadingAll || loadError) && (
                <p
                    className="border-b px-3 py-2 type-small text-muted-foreground"
                    role="status"
                >
                    {loadingAll
                        ? 'Loading the rest of the log…'
                        : 'Could not load the rest of the log.'}
                    {loadError && (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setLoadError(false)}
                        >
                            Try again
                        </Button>
                    )}
                </p>
            )}

            <div className="relative min-h-0">
                <div
                    ref={scrollRef}
                    onScroll={onScroll}
                    tabIndex={0}
                    className="max-h-[70vh] overflow-auto focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-(--signal)"
                    style={{ height: totalSize }}
                    role="log"
                    aria-label="Step logs"
                    aria-live="off"
                >
                    <div
                        className="relative w-full"
                        style={{ height: totalSize }}
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
                                        <StepHeader
                                            row={row}
                                            onToggle={() =>
                                                toggleSection(row.step.section)
                                            }
                                        />
                                    )}
                                    {row.type === 'substep' && (
                                        <div className="flex h-5 items-center bg-(--recessed) pl-[4.75rem] type-mono-small text-muted-foreground">
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
                                            isError={errors.lines.has(
                                                row.line.number,
                                            )}
                                            isFirstError={
                                                row.line.number === errors.first
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
                </div>
                {lines.length === 0 && allSteps.length === 0 && (
                    <p className="p-6 type-body text-muted-foreground">
                        No logs yet.
                    </p>
                )}

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

function StepHeader({
    row,
    onToggle,
}: {
    row: Extract<StepRow, { type: 'step' }>;
    onToggle: () => void;
}) {
    const { step } = row;
    const quiet =
        step.status === 'pending' ||
        step.status === 'skipped' ||
        step.status === 'canceled';
    const content = (
        <>
            <ChevronRight
                aria-hidden="true"
                className={cn(
                    'size-4 shrink-0 text-(--faint) motion-safe:transition-transform motion-safe:duration-[120ms]',
                    row.expanded && 'rotate-90',
                    !row.expandable && 'invisible',
                )}
            />
            <StepIcon status={step.status} size={16} />
            <span
                className={cn(
                    'shrink-0 type-label font-medium',
                    quiet && 'text-muted-foreground',
                )}
            >
                {SECTION_LABELS[step.section]}
            </span>
            <span
                className={cn(
                    'shrink-0 type-small',
                    step.status === 'failed'
                        ? 'text-status-error'
                        : 'text-muted-foreground',
                )}
            >
                {STEP_STATUS_LABEL[step.status]}
            </span>
            {step.message && (
                <span className="hidden min-w-0 truncate type-small text-muted-foreground sm:inline">
                    · {step.message}
                </span>
            )}
            <span className="ml-auto flex shrink-0 items-center gap-3 type-small text-muted-foreground tabular-nums">
                {row.errors > 0 && (
                    <StepChip tone="error">
                        {row.errors} {row.errors === 1 ? 'error' : 'errors'}
                    </StepChip>
                )}
                {row.count > 0 && (
                    <span className="hidden sm:inline">
                        {row.count.toLocaleString()}{' '}
                        {row.count === 1 ? 'line' : 'lines'}
                    </span>
                )}
                {step.duration && <span>{step.duration}</span>}
            </span>
        </>
    );
    const className =
        'flex h-10 w-full items-center gap-2.5 border-b bg-card px-3 text-left';

    if (!row.expandable) {
        return <div className={className}>{content}</div>;
    }

    return (
        <button
            type="button"
            onClick={onToggle}
            aria-expanded={row.expanded}
            className={cn(
                className,
                'hover:bg-(--hover) focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-(--signal)',
            )}
        >
            {content}
        </button>
    );
}

function LogLineRow({
    line,
    wrap,
    timestamps,
    query,
    isError,
    isFirstError,
    isSelected,
    isMatch,
    onSelect,
}: {
    line: LogLine;
    wrap: boolean;
    timestamps: boolean;
    query: string;
    isError: boolean;
    isFirstError: boolean;
    isSelected: boolean;
    isMatch: boolean;
    onSelect: (number: number) => void;
}) {
    return (
        <div
            data-line={line.number}
            aria-current={isFirstError ? 'true' : undefined}
            className={cn(
                'flex border-l-2 border-transparent type-mono-small',
                isError && 'border-(--status-error) bg-(--status-error-soft)',
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
                className={cn(
                    'sticky left-0 w-14 shrink-0 pr-3 text-right text-muted-foreground tabular-nums select-none hover:text-foreground',
                    isError ? 'bg-(--status-error-soft)' : 'bg-card',
                )}
                aria-label={`Line ${line.number}`}
            >
                {line.number}
            </a>
            {isError && (
                <span className="sr-only">
                    {isFirstError ? 'First error: ' : 'Error: '}
                </span>
            )}
            {timestamps && (
                <span className="w-24 shrink-0 pr-3 text-muted-foreground tabular-nums select-none">
                    {time(line.time)}
                </span>
            )}
            <span
                className={cn(
                    'min-w-0 pr-4',
                    wrap ? 'break-all whitespace-pre-wrap' : 'whitespace-pre',
                    isFirstError && 'text-status-error',
                )}
            >
                <AnsiText text={line.text} highlight={query} />
            </span>
        </div>
    );
}
