import { expect, test } from 'vite-plus/test';
import {
    destinationOverdue,
    intervalHours,
    protectionSummary,
    sortByStanding,
    standing,
} from './protection';

const now = Date.parse('2026-11-16T12:00:00Z');

const schedule = (
    id: string,
    status: App.Enums.Backups.BackupHealth,
    nextRunAt: string | null = '2026-11-17T06:07:00Z',
    enabled = true,
) =>
    ({
        id,
        cron: '7 6 * * *',
        enabled,
        nextRunAt,
        health: { status, lastSuccessfulAt: null, nextRunAt, lastError: null },
    }) as unknown as App.Data.Backups.BackupScheduleData;

test('interval of a cron schedule', () => {
    expect(intervalHours('0 * * * *')).toBe(1);
    expect(intervalHours('0 */6 * * *')).toBe(6);
    expect(intervalHours('7 6 * * *')).toBe(24);
    expect(intervalHours('7 6 * * 1')).toBe(168);
    expect(intervalHours('nonsense')).toBe(24);
});

test('standing tells waiting from missed', () => {
    expect(standing(schedule('a', 'none'), now)).toBe('waiting');
    expect(standing(schedule('a', 'none', '2026-11-15T06:07:00Z'), now)).toBe(
        'missed',
    );
    expect(standing(schedule('a', 'none', null, false), now)).toBe('paused');
    expect(standing(schedule('a', 'ok'), now)).toBe('healthy');
    expect(standing(schedule('a', 'stale'), now)).toBe('overdue');
});

test('problems first, then summary in words', () => {
    const list = [
        schedule('ok', 'ok'),
        schedule('wait', 'none'),
        schedule('fail', 'failing'),
        schedule('stale', 'stale'),
    ];

    expect(sortByStanding(list, now).map((s) => s.id)).toEqual([
        'fail',
        'stale',
        'wait',
        'ok',
    ]);
    expect(protectionSummary(list, now)).toBe(
        '1 backed up, 1 waiting for its first backup, 1 overdue, 1 failing',
    );
    expect(protectionSummary([], now)).toBe('Nothing scheduled yet');
});

test('a destination is overdue past its slowest schedule', () => {
    const daily = [schedule('a', 'ok')];

    expect(destinationOverdue('2026-11-16T00:00:00Z', daily, now)).toBe(false);
    expect(destinationOverdue('2026-11-14T00:00:00Z', daily, now)).toBe(true);
    expect(destinationOverdue(null, daily, now)).toBe(false);
});
