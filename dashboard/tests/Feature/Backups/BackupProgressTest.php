<?php

declare(strict_types=1);

use App\Actions\Backups\StartServiceBackup;
use App\Data\Audit\AuditActor;
use App\Enums\Backups\BackupReason;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\Backups\BackupSubject;
use App\Enums\LogSourceKind;
use App\Enums\TeamRole;
use App\Models\Backups\BackupRun;
use App\Models\DaemonTask;
use App\Models\LogChunk;
use App\Queries\Backups\BackupProgress;
use App\Support\Logs\LogChunkRow;
use Tests\Support\Backups;
use Tests\Support\Projects;

/*
| Progress of a backup in flight (the Backups page's running row):
| launchroomd's `::progress::` task log lines, and the phase older servers'
| human-readable lines imply.
*/

beforeEach(function (): void {
    Projects::useTestKek();
    Backups::fakeEnvironment();
});

test('the newest backup progress line wins; other ops and versions are ignored; old servers give the phase', function (): void {
    $line = static fn (string $text): array => ['time' => now()->toIso8601ZuluString(), 'stream' => 'system', 'text' => $text];

    expect(BackupProgress::parse([
        $line('::progress:: {"v":1,"op":"backup","phase":"preparing","elapsedMs":4}'),
        $line('::progress:: {"v":1,"op":"backup","phase":"streaming","bytes":1288490188,"elapsedMs":46012}'),
        $line('::progress:: {"v":1,"op":"maintenance","phase":"forgetting","elapsedMs":838}'),
        $line('::progress:: {"v":2,"op":"backup","phase":"streaming","bytes":1}'),
        $line('::progress:: not json'),
    ]))->toBe(['v' => 1, 'op' => 'backup', 'phase' => 'streaming', 'bytes' => 1288490188, 'elapsedMs' => 46012])
        ->and(BackupProgress::parse([$line('Waiting for another backup or restore on this server to finish')]))->toBe(['phase' => 'waiting'])
        ->and(BackupProgress::parse([$line('Streaming the database into the repository')]))->toBe(['phase' => 'streaming'])
        ->and(BackupProgress::parse([$line('hello')]))->toBeNull();
});

test('a running row shows bytes read of about the last size, and its usual duration', function (): void {
    [$team, $server, , $instance] = Backups::runningPostgres();
    $owner = memberOf($team, TeamRole::Owner);
    $destination = Backups::destination($team, $owner);

    $previous = BackupRun::query()->create(['team_id' => $team->id, 'destination_id' => $destination->id, 'subject' => BackupSubject::Database, 'service_instance_id' => $instance->id, 'reason' => BackupReason::Scheduled]);
    $previous->forceFill(['status' => BackupRunStatus::Succeeded, 'size_bytes' => 1_500_000_000, 'duration_ms' => 90_000, 'finished_at' => now()->subDay()])->save();

    [$run] = resolve(StartServiceBackup::class)->handle($instance, BackupReason::Manual, null, AuditActor::system());
    $run->transitionTo(BackupRunStatus::Running);
    $task = DaemonTask::query()->findOrFail($run->daemon_task_id);

    LogChunk::query()->insert(LogChunkRow::build($team->id, $server->id, LogSourceKind::Task, $task->public_id, 1, [
        ['time' => now()->toIso8601ZuluString(), 'stream' => 'system', 'text' => '::progress:: {"v":1,"op":"backup","phase":"streaming","bytes":1200000000,"elapsedMs":46012}'],
    ], false, null, $task->id));

    $this->actingAs($owner)->get(route('backups.index'))
        ->assertInertia(fn ($page) => $page
            ->where('page.groups.1.items.0.state', 'running')
            ->where('page.groups.1.items.0.run.phase', 'streaming')
            ->where('page.groups.1.items.0.run.bytesDone', 1_200_000_000)
            ->where('page.groups.1.items.0.run.bytesExpected', 1_500_000_000)
            ->where('page.groups.1.items.0.run.expectedIsEstimate', true)
            ->where('page.groups.1.items.0.run.percent', null)
            ->where('page.groups.1.items.0.run.elapsedMs', 46012)
            ->where('page.groups.1.items.0.run.usualDurationMs', 90_000));

    // The service's Backups tab gets the same progress, and its button can't start a second run.
    $this->actingAs($owner)->getJson(route('backups.services.show', $instance))
        ->assertOk()
        ->assertJsonPath('inFlight.0.bytesDone', 1_200_000_000)
        ->assertJsonPath('backUp.disabled.code', 'running');
});
