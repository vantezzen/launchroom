import Anser from 'anser';

export type AnsiSegment = {
    text: string;
    className: string;
};

const colours: [string, string][] = [
    ['red', 'text-ansi-red'],
    ['green', 'text-ansi-green'],
    ['yellow', 'text-ansi-yellow'],
    ['blue', 'text-ansi-blue'],
    ['magenta', 'text-ansi-magenta'],
    ['cyan', 'text-ansi-cyan'],
    // "Bright black" is the conventional grey for de-emphasised output.
    ['bright-black', 'text-ansi-gray'],
];

function colourClass(fg: string | null | undefined): string {
    if (!fg) {
        return '';
    }

    for (const [name, className] of colours) {
        if (fg === `ansi-${name}` || fg === `ansi-bright-${name}`) {
            return className;
        }
    }

    return '';
}

const decorationClasses: Record<string, string> = {
    bold: 'font-semibold',
    dim: 'opacity-70',
    italic: 'italic',
    underline: 'underline',
    strikethrough: 'line-through',
};

/**
 * Splits a log line into text segments with palette classes (design-system
 * §6: ANSI colours toned down to the palette). Only foreground colours and a
 * few decorations survive; backgrounds, 256/true colours and cursor codes are
 * dropped. Output is rendered as text nodes, never as HTML.
 */
export function parseAnsi(line: string): AnsiSegment[] {
    if (!line.includes('\u001b')) {
        return [{ text: line, className: '' }];
    }

    return Anser.ansiToJson(line, {
        use_classes: true,
        remove_empty: true,
    }).map((entry) => ({
        text: entry.content,
        className: [
            colourClass(entry.fg),
            ...entry.decorations.map(
                (decoration) => decorationClasses[decoration] ?? '',
            ),
        ]
            .filter(Boolean)
            .join(' '),
    }));
}

/**
 * Plain text of a line (search, copy, error detection).
 */
export function stripAnsi(line: string): string {
    return line.includes('\u001b') ? Anser.ansiToText(line) : line;
}
