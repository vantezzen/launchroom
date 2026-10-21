import { useMemo } from 'react';
import { cn } from '@/lib/utils';
import { diffLines } from './diff';

/**
 * A Dockerfile as a diff (docs/20 §7): line numbers, a `+` / `−` gutter
 * against the previous try (or against nothing on the first), soft tints
 * plus the gutter sign so colour is never the only cue.
 */
export function DockerfileDiff({
    previous,
    next,
}: {
    previous: string | null;
    next: string;
}) {
    const lines = useMemo(() => diffLines(previous, next), [previous, next]);
    const added = lines.filter((line) => line.kind === 'added').length;
    const removed = lines.filter((line) => line.kind === 'removed').length;

    return (
        <figure className="flex flex-col gap-2">
            <div
                className="max-h-[480px] overflow-auto rounded-xl border bg-recessed py-2"
                role="region"
                aria-label="Dockerfile"
                tabIndex={0}
            >
                <table className="w-full border-collapse type-mono-small">
                    <tbody>
                        {lines.map((line, index) => (
                            <tr
                                key={index}
                                className={cn(
                                    line.kind === 'added' &&
                                        previous !== null &&
                                        'bg-status-ok-soft/60',
                                    line.kind === 'removed' &&
                                        'bg-status-error-soft/60 text-muted-foreground',
                                )}
                            >
                                <td className="w-10 pr-2 pl-3 text-right align-top text-faint tabular-nums select-none">
                                    {line.number ?? ''}
                                </td>
                                <td
                                    className={cn(
                                        'w-5 align-top select-none',
                                        line.kind === 'added'
                                            ? 'text-status-ok'
                                            : line.kind === 'removed'
                                              ? 'text-status-error'
                                              : 'text-faint',
                                    )}
                                    aria-hidden="true"
                                >
                                    {line.kind === 'added'
                                        ? '+'
                                        : line.kind === 'removed'
                                          ? '−'
                                          : ''}
                                </td>
                                <td className="pr-4 whitespace-pre">
                                    <span className="sr-only">
                                        {line.kind === 'added'
                                            ? 'Added: '
                                            : line.kind === 'removed'
                                              ? 'Removed: '
                                              : ''}
                                    </span>
                                    {line.text || ' '}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <figcaption className="type-small text-muted-foreground">
                {previous === null
                    ? `${added} lines`
                    : `${added} added · ${removed} removed`}
            </figcaption>
        </figure>
    );
}
