import { readFileSync } from 'node:fs';
import { describe, expect, test } from 'vite-plus/test';

// Read from disk: Vitest stubs CSS imports, even `?raw` ones.
const css = readFileSync(new URL('../../css/app.css', import.meta.url), 'utf8');

const sources = import.meta.glob<string>(
    [
        '../**/*.{ts,tsx}',
        '!../**/*.test.ts',
        '!../{routes,actions,wayfinder}/**',
    ],
    { query: '?raw', import: 'default', eager: true },
);

const utility =
    /(?<![\w-])(?:text|bg|border|fill|stroke|ring|outline|decoration|divide|from|via|to|accent|caret|shadow)-status-([a-z]+(?:-[a-z]+)*)/g;

describe('status colour utilities', () => {
    test('every status-* class names a colour the theme declares', () => {
        const unknown = new Set<string>();

        for (const [path, source] of Object.entries(sources)) {
            for (const match of source.matchAll(utility)) {
                if (!css.includes(`--color-status-${match[1]}:`)) {
                    unknown.add(`${path}: ${match[0]}`);
                }
            }
        }

        expect(css).toContain('--color-status-ok:');
        expect([...unknown]).toEqual([]);
    });
});
