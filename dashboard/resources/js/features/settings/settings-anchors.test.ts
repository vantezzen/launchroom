import { describe, expect, test } from 'vite-plus/test';

// The search indexes name the row a result scrolls to by its `setting` prop;
// nothing else ties the two together.
const indexes = import.meta.glob<string>(
    ['./settings-index.ts', '../service-settings/settings-index.ts'],
    { query: '?raw', import: 'default', eager: true },
);
const components = import.meta.glob<string>(['../../**/*.tsx'], {
    query: '?raw',
    import: 'default',
    eager: true,
});
const rendered = Object.values(components).join('\n');

describe('settings search anchors', () => {
    test('every anchor is a setting some component renders', () => {
        const missing: string[] = [];

        for (const [path, source] of Object.entries(indexes)) {
            for (const [, line] of source.matchAll(/anchor:([^\n]*)/g)) {
                for (const [, anchor] of line.matchAll(/'([a-z0-9-]+)'/g)) {
                    if (
                        !rendered.includes(`"${anchor}"`) &&
                        !rendered.includes(`'${anchor}'`)
                    ) {
                        missing.push(`${path}: ${anchor}`);
                    }
                }
            }
        }

        expect(Object.keys(indexes)).toHaveLength(2);
        expect(missing).toEqual([]);
    });
});
