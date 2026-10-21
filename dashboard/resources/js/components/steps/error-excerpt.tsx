import { AnsiText } from '@/features/logs/ansi-text';
import { cn } from '@/lib/utils';

export type ExcerptLine = { number: number; text: string };

/**
 * The lines around a failure (design-system §5.4, Laravel error page):
 * line numbers, the error line with a red left rule and soft red background,
 * `context` lines either side (default ±5), and "Show all N lines".
 * Text only: ANSI is toned to the palette, never rendered as HTML.
 */
export function ErrorExcerpt({
    lines,
    errorLine,
    context = 5,
    totalLines,
    onShowAll,
    className,
}: {
    lines: ExcerptLine[];
    /** Line number to highlight. */
    errorLine: number;
    context?: number;
    /** Lines in the full log, for the "Show all" link. */
    totalLines?: number;
    onShowAll?: () => void;
    className?: string;
}) {
    const visible = lines.filter(
        (line) =>
            line.number >= errorLine - context &&
            line.number <= errorLine + context,
    );
    const width = String(visible[visible.length - 1]?.number ?? 0).length;

    return (
        <div className={cn('bg-(--recessed)', className)}>
            <ol
                tabIndex={0}
                aria-label={`Log lines ${visible[0]?.number ?? 0} to ${visible[visible.length - 1]?.number ?? 0}`}
                className="overflow-x-auto py-2 type-mono-small outline-none focus-visible:ring-2 focus-visible:ring-(--signal) focus-visible:ring-inset"
            >
                {visible.map((line) => {
                    const error = line.number === errorLine;

                    return (
                        <li
                            key={line.number}
                            aria-current={error ? 'true' : undefined}
                            className={cn(
                                'flex min-w-max border-l-2 border-transparent pr-4',
                                error &&
                                    'border-(--status-error) bg-(--status-error-soft)',
                            )}
                        >
                            <span
                                className="shrink-0 pr-4 pl-4 text-right text-muted-foreground tabular-nums select-none"
                                style={{ width: `${width + 3}ch` }}
                            >
                                {line.number}
                            </span>
                            <span
                                className={cn(
                                    'whitespace-pre',
                                    error && 'text-status-error',
                                )}
                            >
                                <AnsiText text={line.text} />
                            </span>
                        </li>
                    );
                })}
            </ol>
            {(onShowAll || totalLines) && (
                <div className="flex items-center justify-between gap-4 border-t px-4 py-2 type-small text-muted-foreground">
                    <span>Showing {visible.length} lines around the error</span>
                    {onShowAll && (
                        <button
                            type="button"
                            onClick={onShowAll}
                            className="font-medium text-foreground underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-(--signal)"
                        >
                            Show all{totalLines ? ` ${totalLines} lines` : ''}
                        </button>
                    )}
                </div>
            )}
        </div>
    );
}
