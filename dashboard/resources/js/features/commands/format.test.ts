import { describe, expect, it } from 'vitest';
import { outcome, ranBy, shortRunDuration, timeoutLabel } from './format';

const run = (
    patch: Partial<App.Data.Commands.CommandRunData>,
): App.Data.Commands.CommandRunData => ({
    id: 'run_1',
    status: 'succeeded',
    finished: true,
    argv: ['ls'],
    name: null,
    exitCode: 0,
    error: null,
    timeoutSeconds: 600,
    outputTruncated: false,
    service: 'svc_1',
    environment: 'env_1',
    deployment: 'dpl_1',
    server: 'fra-1',
    ranBy: 'Ana',
    viaApi: false,
    cancelledBy: null,
    createdAt: '2026-11-20T12:00:00Z',
    startedAt: '2026-11-20T12:00:01Z',
    finishedAt: '2026-11-20T12:00:03Z',
    durationMs: 1840,
    ...patch,
});

describe('command run wording', () => {
    it('says how long it may run', () => {
        expect(timeoutLabel(60)).toBe('1 minute');
        expect(timeoutLabel(600)).toBe('10 minutes');
        expect(timeoutLabel(3600)).toBe('1 hour');
    });

    it('names who ran it', () => {
        expect(ranBy(run({}))).toBe('Ana');
        expect(ranBy(run({ viaApi: true }))).toBe('Ana with an API token');
        expect(ranBy(run({ ranBy: null }))).toBe('A former member');
    });

    it('says how it ended in one sentence', () => {
        expect(outcome(run({}))).toBeNull();
        expect(outcome(run({ status: 'failed', exitCode: 1 }))).toBe(
            'The command exited with code 1.',
        );
        expect(outcome(run({ status: 'timed_out' }))).toBe(
            'It was stopped after 10 minutes, its time limit.',
        );
        expect(
            outcome(run({ status: 'cancelled', cancelledBy: 'Jonas' })),
        ).toBe('Cancelled by Jonas.');
        expect(
            outcome(run({ status: 'error', error: 'fra-1 went offline.' })),
        ).toBe('fra-1 went offline.');
    });

    it('keeps short durations readable', () => {
        expect(shortRunDuration(run({ durationMs: 420 }))).toBe('0.4s');
        expect(shortRunDuration(run({ durationMs: null }))).toBeNull();
    });
});
