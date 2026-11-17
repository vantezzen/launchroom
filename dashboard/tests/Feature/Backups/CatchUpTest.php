<?php

declare(strict_types=1);

use App\Actions\Backups\RunBackupTick;
use App\Actions\Backups\UpdateBackupSchedule;
use App\Data\Audit\AuditActor;
use App\Data\Backups\BackupScheduleInput;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\TeamRole;
use App\Models\Backups\BackupRun;
use App\Models\Backups\BackupSchedule;
use App\Models\Backups\WalArchive;
use App\Support\Cron\CatchUp;
use Carbon\CarbonImmutable;
use Tests\Support\Backups;
use Tests\Support\Projects;

beforeEach(function (): void {
    Projects::useTestKek();
    Backups::fakeEnvironment();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * A running Postgres with a daily 02:30 schedule that last fired at $lastRun.
 *
 * @return array{BackupSchedule, int}
 */
function dailySchedule(CarbonImmutable $lastRun): array
{
    [$team, , , $instance] = Backups::runningPostgres();
    Backups::destination($team, memberOf($team, TeamRole::Owner));

    $schedule = BackupSchedule::query()->where('service_instance_id', $instance->id)->sole();
    $schedule->forceFill(['cron' => '30 2 * * *', 'last_run_at' => $lastRun, 'created_at' => $lastRun->subDays(30)])->save();

    return [$schedule, $instance->id];
}

function scheduledRuns(int $instanceId): int
{
    return BackupRun::query()->where('service_instance_id', $instanceId)->where('reason', 'scheduled')->count();
}

function tickAt(CarbonImmutable $at): void
{
    CarbonImmutable::setTestNow($at);
    resolve(RunBackupTick::class)->handle($at);
}

test('the owed slot is the latest one after the last run, at most one', function (): void {
    $last = CarbonImmutable::parse('2026-10-18 02:30:05', 'UTC');

    expect(CatchUp::owed('30 2 * * *', $last, CarbonImmutable::parse('2026-10-18 09:00', 'UTC')))->toBeNull()
        ->and(CatchUp::owed('30 2 * * *', $last, CarbonImmutable::parse('2026-10-19 02:30:00', 'UTC'))?->toIso8601String())->toBe('2026-10-19T02:30:00+00:00')
        // Three days down: one run for the newest slot, not three.
        ->and(CatchUp::owed('30 2 * * *', $last, CarbonImmutable::parse('2026-10-21 11:12', 'UTC'))?->toIso8601String())->toBe('2026-10-21T02:30:00+00:00')
        ->and(CatchUp::owed('not a cron', $last, CarbonImmutable::parse('2026-10-21 11:12', 'UTC')))->toBeNull();
});

test('a slot missed while the scheduler was down runs once on the next tick', function (): void {
    [$schedule, $instanceId] = dailySchedule(CarbonImmutable::parse('2026-10-18 02:30:05', 'UTC'));

    // Down from 01:00 to 09:40: the 02:30 tick never happened.
    tickAt(CarbonImmutable::parse('2026-10-19 09:40:10', 'UTC'));
    expect(scheduledRuns($instanceId))->toBe(1);

    // No burst: the following ticks owe nothing until tomorrow's slot.
    tickAt(CarbonImmutable::parse('2026-10-19 09:41:10', 'UTC'));
    tickAt(CarbonImmutable::parse('2026-10-19 23:59:10', 'UTC'));
    expect(scheduledRuns($instanceId))->toBe(1);

    // That backup finished (one is in flight per database at most).
    BackupRun::query()->where('service_instance_id', $instanceId)->each(static fn (BackupRun $run) => $run->transitionTo(BackupRunStatus::Failed, 'test'));

    tickAt(CarbonImmutable::parse('2026-10-20 02:30:01', 'UTC'));
    expect(scheduledRuns($instanceId))->toBe(2)
        ->and($schedule->fresh()?->last_run_at?->toIso8601String())->toBe('2026-10-20T02:30:01+00:00');
});

test('three days down still owe a single backup', function (): void {
    [, $instanceId] = dailySchedule(CarbonImmutable::parse('2026-10-18 02:30:05', 'UTC'));

    tickAt(CarbonImmutable::parse('2026-10-21 12:00:00', 'UTC'));
    tickAt(CarbonImmutable::parse('2026-10-21 12:01:00', 'UTC'));

    expect(scheduledRuns($instanceId))->toBe(1);
});

test('a schedule that never ran counts from its creation', function (): void {
    [$schedule, $instanceId] = dailySchedule(CarbonImmutable::parse('2026-10-18 02:30:05', 'UTC'));
    $schedule->forceFill(['last_run_at' => null, 'created_at' => CarbonImmutable::parse('2026-10-19 08:00', 'UTC')])->save();

    // Created after today's slot: nothing owed until tomorrow.
    tickAt(CarbonImmutable::parse('2026-10-19 08:01', 'UTC'));
    expect(scheduledRuns($instanceId))->toBe(0);

    tickAt(CarbonImmutable::parse('2026-10-20 05:00', 'UTC'));
    expect(scheduledRuns($instanceId))->toBe(1);
});

test('a new cron or turning a schedule back on counts from then', function (): void {
    [$schedule, $instanceId] = dailySchedule(CarbonImmutable::parse('2026-10-18 02:30:05', 'UTC'));
    $owner = memberOf($schedule->team()->sole(), TeamRole::Owner);

    // At 10:00 the cron moves to 05:00: today's 05:00 passed before the change.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-19 10:00', 'UTC'));
    resolve(UpdateBackupSchedule::class)->handle($schedule, BackupScheduleInput::from([
        'cron' => '0 5 * * *', 'enabled' => true, 'retention' => $schedule->retention->toArray(),
    ]), AuditActor::user($owner));

    tickAt(CarbonImmutable::parse('2026-10-19 10:01', 'UTC'));
    expect(scheduledRuns($instanceId))->toBe(0);

    tickAt(CarbonImmutable::parse('2026-10-20 05:00:02', 'UTC'));
    expect(scheduledRuns($instanceId))->toBe(1);
});

test('a missed base-backup slot runs once', function (): void {
    $archive = new WalArchive;
    $archive->forceFill([
        'enabled' => true,
        'base_cron' => '15 8 * * *',
        'enabled_at' => CarbonImmutable::parse('2026-10-01 00:00', 'UTC'),
        'last_base_run_at' => CarbonImmutable::parse('2026-10-18 08:15:03', 'UTC'),
    ]);

    expect($archive->owedBaseBackup(CarbonImmutable::parse('2026-10-19 08:14', 'UTC')))->toBeNull()
        ->and($archive->owedBaseBackup(CarbonImmutable::parse('2026-10-19 13:00', 'UTC'))?->toIso8601String())->toBe('2026-10-19T08:15:00+00:00');

    $archive->last_base_run_at = CarbonImmutable::parse('2026-10-19 13:00:01', 'UTC');
    expect($archive->owedBaseBackup(CarbonImmutable::parse('2026-10-19 13:01', 'UTC')))->toBeNull();

    // Never ran: counts from when archiving was turned on.
    $archive->last_base_run_at = null;
    $archive->enabled_at = CarbonImmutable::parse('2026-10-19 09:00', 'UTC');
    expect($archive->owedBaseBackup(CarbonImmutable::parse('2026-10-19 13:01', 'UTC')))->toBeNull();
});
