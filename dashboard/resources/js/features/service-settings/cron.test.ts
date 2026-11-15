import { readFileSync } from 'node:fs';
import { describe, expect, test } from 'vite-plus/test';
import { isValidTimeZone, nextRuns, parseCron } from './cron';

type Vectors = {
    accept: string[];
    refuse: string[];
    next: { cron: string; timeZone: string; from: string; runs: string[] }[];
    timeZones: { valid: string[]; invalid: string[] };
};

// launchroomd's cron vectors, also read by the Go cronexpr and PHP CronGrammar tests.
const vectors = JSON.parse(
    readFileSync(
        new URL('../../../../../protocol/fixtures/cron.json', import.meta.url),
        'utf8',
    ),
) as Vectors;

describe('parseCron matches launchroomd', () => {
    test.each(vectors.accept)('accepts %j', (cron) => {
        expect(parseCron(cron)).toHaveProperty('cron');
    });

    test.each(vectors.refuse)('refuses %j', (cron) => {
        expect(parseCron(cron)).toHaveProperty('error');
    });
});

test.each(vectors.next)('next runs of $cron in $timeZone', (vector) => {
    const parsed = parseCron(vector.cron);

    if (!('cron' in parsed)) {
        throw new Error(parsed.error);
    }

    const from = Date.parse(vector.from);
    const runs = nextRuns(parsed.cron, vector.timeZone, 5, from);
    // The preview looks five years ahead at most; launchroomd keeps looking.
    const horizon = from + 5 * 366 * 24 * 60 * 60_000;

    expect(runs.map((run) => run.getTime())).toEqual(
        vector.runs.map((run) => Date.parse(run)).filter((at) => at < horizon),
    );
});

test('time zones', () => {
    for (const zone of vectors.timeZones.valid) {
        expect(isValidTimeZone(zone), zone).toBe(true);
    }

    for (const zone of vectors.timeZones.invalid) {
        expect(isValidTimeZone(zone), zone).toBe(false);
    }
});
