import { source } from '@/lib/source';
import { createFromSource } from 'fumadocs-core/search/server';

// fumadocs' search route: the server keeps the Orama index and answers
// /api/search?query=…, which fumadocs-ui's default search dialog calls.
export const { GET } = createFromSource(source, {
    // https://docs.orama.com/docs/orama-js/supported-languages
    language: 'english',
});
