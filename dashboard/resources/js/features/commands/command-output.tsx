import { useVirtualizer } from '@tanstack/react-virtual';
import { Check, Copy, Download, WrapText } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Toggle } from '@/components/ui/toggle';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { AnsiText } from '@/features/logs/ansi-text';
import {
    JumpToLatest,
    useFollowScroll,
} from '@/features/logs/use-follow-scroll';
import { plural } from '@/lib/format';
import { cn } from '@/lib/utils';

type Line = App.Data.Commands.CommandOutputLineData;

const ROW_HEIGHT = 20;

/**
 * A command's output in the log viewer's style (docs/08 §22,
 * design-system §6): mono lines with numbers, ANSI colours toned to the
 * palette, stderr marked with a red rule and launchroom's own lines muted.
 * Follows the end while the command runs ("Jump to latest" after
 * scrolling up). Text only, never HTML. Copy and download take every line.
 */
export function CommandOutput({
    lines,
    live,
    title,
    fileName,
    className,
    maxHeightClassName = 'max-h-[min(62vh,620px)]',
}: {
    lines: Line[];
    /** More lines may arrive. */
    live: boolean;
    /** The run's argv, for the downloaded file's first line. */
    title: string;
    fileName: string;
    className?: string;
    /** The output grows with its lines up to this height, then scrolls. */
    maxHeightClassName?: string;
}) {
    const scrollRef = useRef<HTMLDivElement>(null);
    const [wrap, setWrap] = useState(false);
    const [copied, setCopied] = useState(false);
    const virtualizer = useVirtualizer({
        count: lines.length,
        getScrollElement: () => scrollRef.current,
        estimateSize: () => ROW_HEIGHT,
        overscan: 40,
    });
    // Wrapped lines have their own heights.
    useEffect(() => virtualizer.measure(), [virtualizer, wrap]);

    const scrollToEnd = useCallback(() => {
        if (lines.length > 0) {
            virtualizer.scrollToIndex(lines.length - 1, { align: 'end' });
        }
    }, [lines.length, virtualizer]);
    const { atEdge, unseen, onScroll, jumpToLatest } = useFollowScroll({
        scrollRef,
        count: lines.length,
        live,
        initial: true,
        scrollToEdge: scrollToEnd,
    });

    const text = () => lines.map((line) => line.text).join('\n');
    const copy = async () => {
        try {
            await navigator.clipboard.writeText(text());
            setCopied(true);
            window.setTimeout(() => setCopied(false), 1500);
        } catch {
            // No clipboard (plain HTTP): the download still works.
        }
    };
    const download = () => {
        const blob = new Blob([`$ ${title}\n${text()}\n`], {
            type: 'text/plain',
        });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = fileName;
        link.click();
        URL.revokeObjectURL(url);
    };

    return (
        <div
            className={cn(
                'flex min-h-0 flex-col overflow-hidden rounded-xl border bg-card shadow-card',
                className,
            )}
        >
            <div className="flex items-center gap-2 border-b py-1.5 pr-1.5 pl-4">
                <p className="type-label">Output</p>
                <p className="type-small text-muted-foreground tabular-nums">
                    {lines.length > 0 && plural(lines.length, 'line')}
                </p>
                <div className="ml-auto flex items-center gap-0.5">
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <Toggle
                                size="sm"
                                pressed={wrap}
                                onPressedChange={setWrap}
                                aria-label="Wrap long lines"
                            >
                                <WrapText aria-hidden="true" />
                            </Toggle>
                        </TooltipTrigger>
                        <TooltipContent>Wrap long lines</TooltipContent>
                    </Tooltip>
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <Button
                                size="icon-sm"
                                variant="ghost"
                                aria-label="Copy output"
                                disabled={lines.length === 0}
                                onClick={() => void copy()}
                            >
                                {copied ? (
                                    <Check aria-hidden="true" />
                                ) : (
                                    <Copy aria-hidden="true" />
                                )}
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent>
                            {copied ? 'Copied' : 'Copy output'}
                        </TooltipContent>
                    </Tooltip>
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <Button
                                size="icon-sm"
                                variant="ghost"
                                aria-label="Download output"
                                disabled={lines.length === 0}
                                onClick={download}
                            >
                                <Download aria-hidden="true" />
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent>Download output</TooltipContent>
                    </Tooltip>
                </div>
            </div>
            <div className="relative min-h-0 flex-1">
                <div
                    ref={scrollRef}
                    onScroll={onScroll}
                    role="log"
                    aria-live={live ? 'polite' : undefined}
                    aria-label="Command output"
                    className={cn(
                        'min-h-24 overflow-auto py-2',
                        maxHeightClassName,
                    )}
                >
                    {lines.length === 0 ? (
                        <p className="px-4 py-1 type-mono-small text-muted-foreground">
                            {live
                                ? 'Waiting for output…'
                                : 'The command printed nothing.'}
                        </p>
                    ) : (
                        <div
                            className="relative w-max min-w-full"
                            style={{ height: virtualizer.getTotalSize() }}
                        >
                            {virtualizer.getVirtualItems().map((item) => {
                                const line = lines[item.index];

                                return (
                                    <div
                                        key={item.key}
                                        data-index={item.index}
                                        ref={
                                            wrap
                                                ? virtualizer.measureElement
                                                : undefined
                                        }
                                        className="absolute top-0 left-0 w-full"
                                        style={{
                                            transform: `translateY(${item.start}px)`,
                                        }}
                                    >
                                        <OutputLine line={line} wrap={wrap} />
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </div>
                <JumpToLatest
                    visible={!atEdge && lines.length > 0}
                    live={live}
                    unseen={unseen}
                    onJump={jumpToLatest}
                />
            </div>
        </div>
    );
}

function OutputLine({ line, wrap }: { line: Line; wrap: boolean }) {
    const error = line.stream === 'stderr';

    return (
        <div
            className={cn(
                'flex min-h-5 border-l-2 border-transparent type-mono-small leading-5',
                error && 'border-status-error/70',
                line.stream === 'system' && 'text-muted-foreground',
            )}
        >
            <span
                aria-hidden="true"
                className="sticky left-0 w-12 shrink-0 bg-card pr-3 text-right text-faint tabular-nums select-none"
            >
                {line.number}
            </span>
            {error && <span className="sr-only">Error output: </span>}
            <span
                className={cn(
                    'min-w-0 pr-4',
                    wrap ? 'break-all whitespace-pre-wrap' : 'whitespace-pre',
                )}
            >
                <AnsiText text={line.text} />
            </span>
        </div>
    );
}
