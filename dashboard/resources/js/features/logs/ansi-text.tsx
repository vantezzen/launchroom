import { memo, useMemo } from 'react';
import type { ReactNode } from 'react';
import { parseAnsi } from './ansi';

/** Wraps case-insensitive matches of `query` (lowercase) in a soft mark. */
export function Highlighted({ text, query }: { text: string; query: string }) {
    if (query === '') {
        return <>{text}</>;
    }

    const parts: ReactNode[] = [];
    const lower = text.toLowerCase();
    let index = 0;

    while (index < text.length) {
        const found = lower.indexOf(query, index);

        if (found === -1) {
            parts.push(text.slice(index));
            break;
        }

        if (found > index) {
            parts.push(text.slice(index, found));
        }

        parts.push(
            <mark
                key={found}
                className="rounded-sm bg-status-progress/25 text-inherit"
            >
                {text.slice(found, found + query.length)}
            </mark>,
        );
        index = found + query.length;
    }

    return <>{parts}</>;
}

/**
 * A log line as text nodes with ANSI colours toned to the palette
 * (design-system §6). Never HTML: escapes and markup in the line stay text.
 * `highlight` (lowercase) marks search matches.
 */
export const AnsiText = memo(function AnsiText({
    text,
    highlight = '',
}: {
    text: string;
    highlight?: string;
}) {
    const segments = useMemo(() => parseAnsi(text), [text]);

    return (
        <>
            {segments.map((segment, index) => (
                <span key={index} className={segment.className || undefined}>
                    <Highlighted text={segment.text} query={highlight} />
                </span>
            ))}
            {text === '' && ' '}
        </>
    );
});
