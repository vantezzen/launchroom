import { readFileSync } from 'node:fs';
import { expect, test } from 'vite-plus/test';
import { slugFrom } from './slug';

const vectors = JSON.parse(
    readFileSync(
        new URL('../../../tests/Fixtures/slug-vectors.json', import.meta.url),
        'utf8',
    ),
) as { name: string; fallback: string; slug: string }[];

test.each(vectors)('slugFrom($name) matches Slug::from', (vector) => {
    expect(slugFrom(vector.name, vector.fallback)).toBe(vector.slug);
});
