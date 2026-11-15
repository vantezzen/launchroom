import { readFileSync } from 'node:fs';
import { expect, test } from 'vite-plus/test';
import { parseRepositoryUrl } from './repository-url';

const vectors = JSON.parse(
    readFileSync(
        new URL(
            '../../../../tests/Fixtures/repository-url-vectors.json',
            import.meta.url,
        ),
        'utf8',
    ),
) as {
    input: string;
    fullName: string | null;
    provider: string | null;
    error: string | null;
}[];

test.each(vectors)(
    'parseRepositoryUrl($input) matches PublicRepositoryUrl::parse',
    (vector) => {
        const parsed = parseRepositoryUrl(vector.input);

        if (vector.error !== null) {
            expect(parsed).toMatchObject({ ok: false, error: vector.error });

            return;
        }

        expect(parsed).toMatchObject({
            ok: true,
            fullName: vector.fullName,
            provider: vector.provider,
        });
    },
);
