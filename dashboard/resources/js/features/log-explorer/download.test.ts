import { expect, test } from 'vite-plus/test';
import { linesFile, textLine } from './download';
import type { LogEntry } from './types';

const entry = (extra: Partial<LogEntry> = {}): LogEntry => ({
    id: '1-0',
    time: '2026-11-16T10:02:16.792Z',
    level: 'error',
    service: 'web',
    message: '\u001b[31mboom\u001b[0m\nnext',
    ...extra,
});

test('a text line has time, level, where, source and the plain message', () => {
    expect(
        textLine(
            entry({ replica: '2', project: 'shop', environment: 'production' }),
        ),
    ).toBe('2026-11-16T10:02:16.792Z ERROR shop/production web#2 boom next');
});

test('the file is one line per entry, oldest first as given', () => {
    const file = linesFile(
        [entry(), entry({ id: '1-1', level: 'info' })],
        'ndjson',
    );

    expect(file.trim().split('\n')).toHaveLength(2);
    expect(JSON.parse(file.split('\n')[1]).level).toBe('info');
});
