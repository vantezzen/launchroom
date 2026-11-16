/**
 * Structured (JSON) log lines: many runtimes print one JSON object per line
 * (`{"level":"debug","msg":"request handling finished",…}`). The list shows
 * its `msg`/`message` text, the message pane pretty-prints the object.
 * Search, copy and the level keep using the raw line.
 */
export type StructuredLine = {
    /** What the list shows: the `msg`/`message` field, else the raw line. */
    summary: string;
    /** What the message pane shows: indented JSON for objects, else the raw line. */
    pretty: string;
    /** The line parsed as a JSON object. */
    structured: boolean;
};

const cache = new Map<string, StructuredLine>();
const CACHE_LIMIT = 2_000;

function parseObject(message: string): Record<string, unknown> | null {
    const text = message.trim();

    if (!text.startsWith('{') || !text.endsWith('}')) {
        return null;
    }

    try {
        const value: unknown = JSON.parse(text);

        return value !== null &&
            typeof value === 'object' &&
            !Array.isArray(value)
            ? (value as Record<string, unknown>)
            : null;
    } catch {
        return null;
    }
}

export function structuredLine(message: string): StructuredLine {
    const known = cache.get(message);

    if (known) {
        return known;
    }

    const object = parseObject(message);
    let line: StructuredLine = {
        summary: message,
        pretty: message,
        structured: false,
    };

    if (object) {
        const text = [object.msg, object.message].find(
            (value): value is string =>
                typeof value === 'string' && value.trim() !== '',
        );
        line = {
            summary: text ?? message,
            pretty: JSON.stringify(object, null, 2),
            structured: true,
        };
    }

    if (cache.size >= CACHE_LIMIT) {
        cache.clear();
    }

    cache.set(message, line);

    return line;
}
