import { readFileSync } from 'node:fs';
import { describe, expect, test } from 'vite-plus/test';
import {
    fromBuilder,
    isValidTimeZone,
    nextRuns,
    parseCron,
    toBuilder,
} from './cron';

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

describe('the schedule builder', () => {
    test('reads the shapes it can show and writes them back', () => {
        for (const cron of [
            '*/5 * * * *',
            '* * * * *',
            '0 * * * *',
            '15 */6 * * *',
            '30 3 * * *',
            '0 9 * * 1-5',
            '15 6 * * 2,4',
            '0 10 * * 0,6',
            '0 0 1 * *',
        ]) {
            const builder = toBuilder(cron);

            expect(builder, cron).not.toBeNull();
            expect(fromBuilder(builder!)).toBe(cron);
        }
    });

    test('reads weekdays as a list', () => {
        expect(toBuilder('15 6 * * 2,4')).toEqual({
            every: 'days',
            hour: 6,
            minute: 15,
            weekdays: [2, 4],
        });
        expect(toBuilder('0 9 * * 0-6')).toEqual({
            every: 'days',
            hour: 9,
            minute: 0,
            weekdays: [],
        });
    });

    test('leaves the rest to the cron field', () => {
        expect(toBuilder('0 9-17 * * *')).toBeNull();
        expect(toBuilder('0 0 1 1 *')).toBeNull();
        expect(toBuilder('0,30 * * * *')).toBeNull();
        expect(toBuilder('0 0 1 * 1')).toBeNull();
    });

    test('writes weekday runs as ranges', () => {
        expect(
            fromBuilder({
                every: 'days',
                hour: 9,
                minute: 0,
                weekdays: [1, 2, 3, 5],
            }),
        ).toBe('0 9 * * 1-3,5');
    });
});
