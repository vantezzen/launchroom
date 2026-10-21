import { stripAnsi } from '../ansi';
import type { LogEntry, LogLevel } from './types';

/** Keys the query bar understands (design-system §5.5). */
export const QUERY_KEYS = [
    'service',
    'level',
    'deployment',
    'server',
    'stream',
    'environment',
    'project',
] as const;
export type QueryKey = (typeof QUERY_KEYS)[number];

export type QueryFilter = { key: QueryKey; value: string; negated: boolean };

export type LogQuery = {
    filters: QueryFilter[];
    /** Free-text terms; quoted phrases stay one term. Lowercase. */
    text: string[];
};

const LEVEL_ALIASES: Record<string, LogLevel> = {
    err: 'error',
    error: 'error',
    fatal: 'error',
    warn: 'warning',
    warning: 'warning',
    info: 'info',
    debug: 'debug',
};

function tokenize(input: string): string[] {
    const tokens: string[] = [];
    const pattern = /(-?[a-z]+:"[^"]*"?|-?[a-z]+:\S+|"[^"]*"?|\S+)/gi;

    for (const match of input.matchAll(pattern)) {
        tokens.push(match[0]);
    }

    return tokens;
}

function unquote(value: string): string {
    return value.replace(/^"/, '').replace(/"$/, '');
}

/**
 * Parses `service:api -level:debug "connection refused" timeout` into
 * filters and text terms. Unknown keys and empty values stay text, so the
 * parser never loses what the user typed.
 */
export function parseLogQuery(input: string): LogQuery {
    const filters: QueryFilter[] = [];
    const text: string[] = [];

    for (const token of tokenize(input)) {
        const match = /^(-?)([a-z]+):(.+)$/i.exec(token);
        const key = match?.[2].toLowerCase();

        if (match && key && (QUERY_KEYS as readonly string[]).includes(key)) {
            let value = unquote(match[3]).trim();

            if (value !== '') {
                if (key === 'level') {
                    value =
                        LEVEL_ALIASES[value.toLowerCase()] ??
                        value.toLowerCase();
                }

                filters.push({
                    key: key as QueryKey,
                    value,
                    negated: match[1] === '-',
                });
                continue;
            }
        }

        const term = unquote(token).trim().toLowerCase();

        if (term !== '') {
            text.push(term);
        }
    }

    return { filters, text };
}

/** Inverse of `parseLogQuery` (normalised): used when chips or the rail change the query. */
export function serializeLogQuery(query: LogQuery): string {
    const quote = (value: string) => (/\s/.test(value) ? `"${value}"` : value);

    return [
        ...query.filters.map(
            (filter) =>
                `${filter.negated ? '-' : ''}${filter.key}:${quote(filter.value)}`,
        ),
        ...query.text.map(quote),
    ].join(' ');
}

/** Adds or removes `key:value` (non-negated) in the query string. */
export function toggleFilter(
    input: string,
    key: QueryKey,
    value: string,
): string {
    const query = parseLogQuery(input);
    const exists = query.filters.some(
        (filter) =>
            filter.key === key && filter.value === value && !filter.negated,
    );

    return serializeLogQuery({
        ...query,
        filters: exists
            ? query.filters.filter(
                  (filter) =>
                      !(
                          filter.key === key &&
                          filter.value === value &&
                          !filter.negated
                      ),
              )
            : [...query.filters, { key, value, negated: false }],
    });
}

function field(entry: LogEntry, key: QueryKey): string {
    return String(entry[key] ?? '').toLowerCase();
}

/**
 * Client-side matcher (for live tails and tests; the server applies the same
 * semantics for stored logs): filters with the same key are OR'd, different
 * keys AND'd, negations exclude, every text term must appear in the message.
 */
export function matchesQuery(entry: LogEntry, query: LogQuery): boolean {
    const byKey = new Map<QueryKey, QueryFilter[]>();

    for (const filter of query.filters) {
        byKey.set(filter.key, [...(byKey.get(filter.key) ?? []), filter]);
    }

    for (const [key, filters] of byKey) {
        const value = field(entry, key);
        const positive = filters.filter((filter) => !filter.negated);

        if (
            filters.some(
                (filter) =>
                    filter.negated && value === filter.value.toLowerCase(),
            )
        ) {
            return false;
        }

        if (
            positive.length > 0 &&
            !positive.some((filter) => value === filter.value.toLowerCase())
        ) {
            return false;
        }
    }

    if (query.text.length > 0) {
        const message = stripAnsi(entry.message).toLowerCase();

        return query.text.every((term) => message.includes(term));
    }

    return true;
}
