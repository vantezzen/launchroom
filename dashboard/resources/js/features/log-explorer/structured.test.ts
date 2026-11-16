import { expect, test } from 'vite-plus/test';
import { structuredLine } from './structured';

test('a JSON line shows its msg and pretty-prints the object', () => {
    const raw =
        '{"level":"debug","ts":1794822847.21,"logger":"http.handlers.php","msg":"request handling finished"}';
    const line = structuredLine(raw);

    expect(line.structured).toBe(true);
    expect(line.summary).toBe('request handling finished');
    expect(line.pretty).toBe(
        [
            '{',
            '  "level": "debug",',
            '  "ts": 1794822847.21,',
            '  "logger": "http.handlers.php",',
            '  "msg": "request handling finished"',
            '}',
        ].join('\n'),
    );
});

test('message is used when there is no msg', () => {
    expect(structuredLine(' {"message":"boot","pid":1} ').summary).toBe('boot');
});

test('a JSON object without a text field keeps the raw line in the list', () => {
    const raw = '{"status":200,"msg":""}';
    const line = structuredLine(raw);

    expect(line.structured).toBe(true);
    expect(line.summary).toBe(raw);
});

test.each([
    '[2026-11-16 10:00:00] production.ERROR: boom',
    'GET /health 200',
    '{"unterminated": ',
    '["an","array"]',
    'null',
    '{not json}',
])('plain lines stay as they are: %s', (raw) => {
    expect(structuredLine(raw)).toEqual({
        summary: raw,
        pretty: raw,
        structured: false,
    });
});
