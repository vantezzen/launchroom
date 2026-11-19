import { expect, test } from 'vite-plus/test';
import {
    batchLabel,
    batchOutcome,
    bytesLine,
    elapsedLine,
    groupSummary,
    heroTitle,
    itemDetail,
    lastNextLine,
    runButtonLabel,
    runLabel,
    runPercent,
    runSteps,
} from './overview-model';
import type {
    BackupBatch,
    BackupItem,
    BackupRunProgress,
    BackupsSummary,
} from './page-types';
import { groupByDay } from './timeline';

const now = new Date('2026-11-18T12:00:00');
const MB = 1024 * 1024;

const summary = (extra: Partial<BackupsSummary>): BackupsSummary => ({
    state: 'ok',
    total: 3,
    ok: 3,
    running: 0,
    queued: 0,
    waiting: 0,
    failed: 0,
    overdue: 0,
    attention: [],
    lastSucceededAt: '2026-11-18T10:00:00',
    nextRunAt: '2026-11-19T03:12:00',
    ...extra,
});

const item = (
    state: BackupItem['state'],
    extra: Partial<BackupItem> = {},
): BackupItem =>
    ({
        id: `database:${state}`,
        kind: 'database',
        name: 'postgres',
        serviceName: null,
        mountPath: null,
        engine: 'postgres',
        engineVersion: '17',
        projectName: 'shop',
        environmentName: 'production',
        state,
        lastSucceededAt: state === 'ok' ? '2026-11-18T09:00:00' : null,
        nextRunAt: '2026-11-19T03:12:00',
        ...extra,
    }) as BackupItem;

const run = (extra: Partial<BackupRunProgress> = {}): BackupRunProgress => ({
    runId: 'bkr_1',
    state: 'running',
    phase: 'streaming',
    waitingFor: null,
    waitingBehind: null,
    bytesDone: 412 * MB,
    bytesExpected: 1536 * MB,
    expectedIsEstimate: true,
    percent: null,
    queuedAt: '2026-11-18T11:58:00',
    startedAt: '2026-11-18T11:58:40',
    elapsedMs: null,
    usualDurationMs: 125_000,
    reason: 'manual',
    ...extra,
});

test('hero: everything backed up, with last and next', () => {
    expect(heroTitle(summary({}), now)).toMatch(
        /^Everything is backed up\. Last backup 2 hours ago, next tonight at .+\.$/,
    );
});

test('hero: what needs you is named', () => {
    expect(
        heroTitle(
            summary({
                state: 'attention',
                attention: [{ id: 'a', name: 'orders-db' }],
            }),
            now,
        ),
    ).toBe('orders-db needs you.');
    expect(
        heroTitle(
            summary({
                state: 'attention',
                attention: [
                    { id: 'a', name: 'a' },
                    { id: 'b', name: 'b' },
                ],
            }),
            now,
        ),
    ).toBe('2 items need you.');
});

test('hero: first backups ahead and running', () => {
    expect(
        heroTitle(
            summary({ state: 'waiting', lastSucceededAt: null, ok: 0 }),
            now,
        ),
    ).toMatch(
        /^Waiting for the first backups\. The first one starts tonight at /,
    );
    expect(
        heroTitle(summary({ state: 'running', running: 3, queued: 2 }), now),
    ).toBe(
        'Backing up 3 items, 2 waiting their turn. You can leave this page.',
    );
});

test('group summary names the worst state', () => {
    expect(groupSummary([item('ok'), item('ok')])).toBe(
        '2 items, all backed up',
    );
    expect(groupSummary([item('ok'), item('failed')])).toBe(
        '2 items, 1 failed',
    );
    expect(groupSummary([item('waiting'), item('ok')])).toBe(
        '2 items, 1 waiting for its first backup',
    );
});

test('row lines', () => {
    expect(itemDetail(item('ok'))).toBe('Postgres 17, production');
    expect(
        itemDetail(
            item('ok', {
                kind: 'volume',
                serviceName: 'api',
                mountPath: '/app/storage',
            }),
        ),
    ).toBe('Files of api, production');
    expect(lastNextLine(item('ok'), now)).toMatch(
        /^3 hours ago, next tonight at /,
    );
    expect(lastNextLine(item('failed'), now)).toMatch(
        /^No backup yet, next try tonight at /,
    );
    expect(lastNextLine(item('waiting'), now)).toMatch(
        /^First backup tonight at /,
    );
});

test('progress: bytes against the last size, never a fake total', () => {
    expect(bytesLine(run())).toBe('412 MB of about 1.5 GB');
    expect(bytesLine(run({ expectedIsEstimate: false }))).toBe(
        '412 MB of 1.5 GB',
    );
    expect(bytesLine(run({ bytesExpected: null }))).toBe('412 MB');
    expect(runPercent(run({ bytesExpected: null }))).toBeNull();
    expect(runPercent(run({ percent: 40 }))).toBe(40);
    expect(runPercent(run())).toBeNull();
    expect(runPercent(run({ expectedIsEstimate: false }))).toBeCloseTo(26.8, 1);
});

test('progress: queued rows say what they wait for', () => {
    expect(
        runLabel(
            run({ phase: 'waiting', state: 'queued', waitingFor: 'hel-1' }),
        ),
    ).toBe('Waiting for hel-1');
    expect(runLabel(run({ phase: 'queued', state: 'queued' }))).toBe(
        'Waiting to start',
    );
    expect(runLabel(run())).toBe('Uploading 412 MB of about 1.5 GB');
    expect(runLabel(run({ phase: 'finishing' }))).toBe('Storing the snapshot');
});

test('progress: the busy button stays short; a percentage only with a real total', () => {
    expect(runButtonLabel(run())).toBe('Uploading');
    expect(runButtonLabel(run({ percent: 42.7 }))).toBe('Uploading 42%');
    expect(runButtonLabel(run({ phase: 'waiting', waitingFor: 'hel-1' }))).toBe(
        'Waiting',
    );
    expect(runButtonLabel(run({ phase: 'finishing' }))).toBe('Storing');
});

test('progress: steps follow the phase', () => {
    const steps = runSteps('database', run());

    expect(steps.map((s) => s.status)).toEqual([
        'succeeded',
        'running',
        'pending',
    ]);
    expect(steps[1].detail).toBe('412 MB of about 1.5 GB');
    expect(
        runSteps('volume', run({ phase: 'queued', state: 'queued' })).every(
            (s) => s.status === 'pending',
        ),
    ).toBe(true);
    expect(
        runSteps('volume', run({ phase: 'stored' })).every(
            (s) => s.status === 'succeeded',
        ),
    ).toBe(true);
});

test('progress: elapsed with the usual duration', () => {
    expect(elapsedLine(run(), now.getTime())).toBe('1m 20s, usually 2m 5s');
    expect(elapsedLine(run({ startedAt: null }), now.getTime())).toBeNull();
});

test('batch: label while running, outcome after', () => {
    const batch: BackupBatch = {
        id: 'b',
        startedAt: '2026-11-18T11:50:00',
        finishedAt: null,
        total: 8,
        succeeded: 2,
        failed: 0,
        running: 1,
        queued: 5,
        refused: [],
        failures: [],
    };

    expect(batchLabel(batch)).toBe('Backing up 3 of 8');
    expect(batchOutcome(batch)).toBeNull();
    expect(
        batchOutcome({
            ...batch,
            finishedAt: '2026-11-18T11:56:02',
            succeeded: 8,
        }),
    ).toBe('8 items backed up in 6m 2s.');
    expect(
        batchOutcome({
            ...batch,
            finishedAt: '2026-11-18T11:56:02',
            succeeded: 7,
            failed: 1,
            failures: [{ itemId: 'x', name: 'orders-db', error: null }],
        }),
    ).toBe('7 of 8 backed up. orders-db failed.');
});

test('restore points group by day, newest first', () => {
    const groups = groupByDay(
        [
            { t: '2026-11-18T09:00:00' },
            { t: '2026-11-18T03:00:00' },
            { t: '2026-11-17T03:00:00' },
            { t: '2026-11-12T03:00:00' },
        ],
        (s) => s.t,
        now,
    );

    expect(groups.map((g) => [g.label, g.items.length])).toEqual([
        ['Today', 2],
        ['Yesterday', 1],
        ['Thursday 12 November', 1],
    ]);
});
