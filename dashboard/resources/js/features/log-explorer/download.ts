import { stripAnsi } from '@/features/logs/ansi';
import type { LogEntry } from './types';

/**
 * One line of a text download, like the server's file (docs/08 §12):
 * `<time> <LEVEL> [<project>/<environment>] <service>[#replica] <message>`,
 * escape sequences removed, one line per entry.
 */
export function textLine(entry: LogEntry): string {
    const where = [entry.project, entry.environment].filter(Boolean).join('/');
    const source = entry.replica
        ? `${entry.service}#${entry.replica}`
        : entry.service;

    return [
        entry.time,
        entry.level.toUpperCase(),
        where,
        source,
        stripAnsi(entry.message).replace(/\r?\n|\r/g, ' '),
    ]
        .filter((part) => part !== '')
        .join(' ');
}

/** The file for "Lines on screen": text or NDJSON, oldest first. */
export function linesFile(
    entries: LogEntry[],
    format: 'text' | 'ndjson',
): string {
    return entries
        .map((entry) =>
            format === 'ndjson' ? JSON.stringify(entry) : textLine(entry),
        )
        .join('\n')
        .concat(entries.length > 0 ? '\n' : '');
}

/** Saves the given lines in the browser (no request). */
export function downloadLines(
    entries: LogEntry[],
    format: 'text' | 'ndjson',
): void {
    const stamp = new Date().toISOString().replace(/[-:]/g, '').slice(0, 15);
    const url = URL.createObjectURL(
        new Blob([linesFile(entries, format)], {
            type:
                format === 'ndjson'
                    ? 'application/x-ndjson;charset=utf-8'
                    : 'text/plain;charset=utf-8',
        }),
    );
    const link = document.createElement('a');
    link.href = url;
    link.download = `launchroom-logs-${stamp}.${format === 'ndjson' ? 'ndjson' : 'log'}`;
    link.click();
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
}
