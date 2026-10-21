<?php

declare(strict_types=1);

use App\Actions\Backups\StartControlPlaneBackup;
use App\Data\Audit\AuditActor;
use App\Enums\Backups\BackupReason;
use App\Enums\Backups\BackupRunStatus;
use App\Models\Backups\BackupRun;
use App\Models\InstanceSettings;
use App\Models\User;
use App\Services\Backups\RestoredControlPlaneRuns;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Backups;
use Tests\Support\Projects;

beforeEach(function (): void {
    Projects::useTestKek();
    Backups::fakeEnvironment();
    Queue::fake();
    $owner = User::factory()->create(['is_instance_owner' => true]);
    Backups::destination(null, $owner);
    InstanceSettings::current()->forceFill(['recovery_public_key' => base64_encode(random_bytes(32))])->save();
});

function startControlPlaneRun(): BackupRun
{
    return resolve(StartControlPlaneBackup::class)->handle(BackupReason::Manual, null, AuditActor::system());
}

test('a restore marks the run that was in flight in the backup as succeeded', function (): void {
    // The dump was taken while its own run was running.
    $restored = startControlPlaneRun();
    $restored->transitionTo(BackupRunStatus::Running);

    $settled = RestoredControlPlaneRuns::settle(config()->string('database.default'), $restored->public_id, 'abcdef0123456789');

    expect($settled)->toBe(1)
        ->and($restored->refresh()->status)->toBe(BackupRunStatus::Succeeded)
        ->and($restored->snapshot_id)->toBe('abcdef0123456789')
        // So the next backup is a new run instead of the restored one.
        ->and(startControlPlaneRun()->id)->not->toBe($restored->id);
});

test('a restore fails any other control-plane run that was in flight', function (): void {
    $other = startControlPlaneRun();

    expect(RestoredControlPlaneRuns::settle(config()->string('database.default'), 'bkr_01J000000000000000000000AA', 'abcdef0123456789'))->toBe(1)
        ->and($other->refresh()->status)->toBe(BackupRunStatus::Failed)
        ->and($other->error)->toBe(RestoredControlPlaneRuns::INTERRUPTED);
});

test('a new backup joins a run in flight but gives up one past its timeout', function (): void {
    $running = startControlPlaneRun();
    $running->transitionTo(BackupRunStatus::Running);

    expect(startControlPlaneRun()->id)->toBe($running->id);

    $this->travel(config()->integer('launchroom.backups.task_timeout_seconds') + 601)->seconds();
    $next = startControlPlaneRun();

    expect($next->id)->not->toBe($running->id)
        ->and($next->status)->toBe(BackupRunStatus::Pending)
        ->and($running->refresh()->status)->toBe(BackupRunStatus::Failed)
        ->and($running->error)->toContain('Abandoned');
});
