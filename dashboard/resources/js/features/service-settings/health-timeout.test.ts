import { readFileSync } from 'node:fs';
import { expect, test } from 'vite-plus/test';
import { autoHealthTimeout } from './health-timeout';

const vectors = JSON.parse(
    readFileSync(
        new URL(
            '../../../../tests/Fixtures/health-timeout-vectors.json',
            import.meta.url,
        ),
        'utf8',
    ),
) as {
    startPeriod: number;
    interval: number;
    timeout: number;
    failures: number;
    healthTimeout: number;
}[];

test.each(vectors)(
    'autoHealthTimeout matches DeploySettings::healthTimeout',
    (vector) => {
        expect(autoHealthTimeout(vector)).toBe(vector.healthTimeout);
    },
);
