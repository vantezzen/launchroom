<?php

declare(strict_types=1);

use App\Actions\Backups\RunBackupTick;
use App\Enums\AuditAction;
use App\Enums\Backups\BackupReason;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\Backups\BackupSubject;
use App\Enums\Backups\DeletionRequestStatus;
use App\Enums\TeamRole;
use App\Events\NodeStateInvalidated;
use App\Models\AuditEvent;
use App\Models\Backups\BackupRun;
use App\Models\Backups\BackupSchedule;
use App\Models\Backups\DeletionRequest;
use App\Models\Placement;
use App\Models\Project;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Models\Volume;
use App\Models\VolumeRetirement;
use Illuminate\Support\Facades\Event;
use Tests\Support\Backups;
use Tests\Support\Projects;

beforeEach(function (): void {
    Projects::useTestKek();
    Backups::fakeEnvironment();
    [$this->team, $this->server, , $this->database] = Backups::runningPostgres();
    $this->project = $this->database->environment->project;
    $this->developer = memberOf($this->team, TeamRole::Developer);
    $this->viewer = memberOf($this->team, TeamRole::Viewer);
    $this->url = route('projects.destroy', $this->project);
    $this->delete = fn (array $data = []) => $this->actingAs($this->developer)->withSession(passwordConfirmed())
        ->delete($this->url, ['confirmName' => $this->project->name, ...$data]);
});

test('the danger zone shows what deleting would remove', function (): void {
    $this->actingAs($this->developer)->get(route('projects.settings', [$this->project, 'danger']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('projects/settings')
            ->where('section', 'danger')
            ->where('canDelete', true)
            ->where('deletion', ['servicesWithBackups' => 0, 'inFlight' => false, 'services' => 1, 'environments' => 1, 'pending' => null, 'canSkipFinalBackup' => false]));

    $this->actingAs($this->viewer)->get(route('projects.settings', [$this->project, 'danger']))
        ->assertInertia(fn ($page) => $page->where('canDelete', false)->where('deletion', null));
});

test('deleting needs the delete permission and the typed name', function (): void {
    $this->actingAs($this->developer)->delete($this->url, ['confirmName' => 'wrong'])->assertSessionHasErrors('confirmName');
    $this->actingAs($this->viewer)->withSession(passwordConfirmed())->delete($this->url, ['confirmName' => $this->project->name])->assertForbidden();
    ($this->delete)(['confirmName' => 'shop'])->assertSessionHasErrors('confirmName');
    ($this->delete)(['confirmName' => ''])->assertSessionHasErrors('confirmName');

    $outsider = memberOf(Team::factory()->create(), TeamRole::Owner);
    $this->actingAs($outsider)->withSession(passwordConfirmed())->delete($this->url, ['confirmName' => $this->project->name])->assertNotFound();

    expect(Project::query()->whereKey($this->project->id)->exists())->toBeTrue();
});

test('a database with backups gets a final backup before the project goes (docs/14 §9.13)', function (): void {
    $destination = Backups::destination($this->team, $this->developer, makeDefault: true);
    // A backup already in flight is the final backup (one backup per database at a time).
    $run = BackupRun::query()->create([
        'team_id' => $this->team->id,
        'destination_id' => $destination->id,
        'subject' => BackupSubject::Database,
        'service_instance_id' => $this->database->id,
        'reason' => BackupReason::Manual,
    ]);

    ($this->delete)()->assertRedirect()->assertSessionHasNoErrors();

    $request = DeletionRequest::query()->sole();
    expect(Project::query()->whereKey($this->project->id)->exists())->toBeTrue()
        ->and($request->status)->toBe(DeletionRequestStatus::BackingUp)
        ->and($request->backup_run_ids)->toBe([$run->id]);

    $this->actingAs($this->developer)->get(route('projects.settings', [$this->project, 'danger']))
        ->assertInertia(fn ($page) => $page->where('deletion.pending.status', 'backing_up')->where('deletion.servicesWithBackups', 1));

    resolve(RunBackupTick::class)->handle();
    expect(Project::query()->whereKey($this->project->id)->exists())->toBeTrue();

    $run->transitionTo(BackupRunStatus::Running);
    $run->forceFill(['snapshot_id' => str_repeat('cd', 32)]);
    $run->transitionTo(BackupRunStatus::Succeeded);
    resolve(RunBackupTick::class)->handle();

    expect(Project::query()->whereKey($this->project->id)->exists())->toBeFalse()
        ->and($request->refresh()->status)->toBe(DeletionRequestStatus::Completed)
        ->and(VolumeRetirement::query()->sole()->final_backup_snapshot)->toBe(str_repeat('cd', 32))
        ->and(VolumeRetirement::query()->sole()->confirmed_at)->toBeNull();
});

test('a failed final backup blocks the deletion until an admin deletes without one', function (): void {
    $destination = Backups::destination($this->team, $this->developer, makeDefault: true);

    ($this->delete)()->assertRedirect()->assertSessionHasNoErrors();

    $request = DeletionRequest::query()->sole();
    $final = BackupRun::query()->whereKey($request->backup_run_ids)->sole();
    expect($final->reason)->toBe(BackupReason::PreDelete)
        ->and($final->destination_id)->toBe($destination->id)
        ->and($final->daemon_task_id)->not->toBeNull();

    $final->transitionTo(BackupRunStatus::Failed, 'restic backup failed (exit 1): no space left');
    resolve(RunBackupTick::class)->handle();

    expect($request->refresh()->status)->toBe(DeletionRequestStatus::Blocked)
        ->and($request->error)->toContain('no space left')
        ->and(Project::query()->whereKey($this->project->id)->exists())->toBeTrue();

    // Asking again doesn't delete either.
    ($this->delete)()->assertRedirect();
    expect(Project::query()->whereKey($this->project->id)->exists())->toBeTrue();

    $skip = route('deletions.skip', ['deletion' => $request->public_id]);
    $admin = memberOf($this->team, TeamRole::Admin);
    $this->actingAs($this->developer)->withSession(passwordConfirmed())->post($skip)->assertForbidden();
    $this->flushSession();
    $outsider = memberOf(Team::factory()->create(), TeamRole::Owner);
    $this->actingAs($outsider)->withSession(passwordConfirmed())->post($skip)->assertNotFound();

    $this->actingAs($admin)->withSession(passwordConfirmed())->post($skip)->assertRedirect(route('projects.index'));

    expect(Project::query()->whereKey($this->project->id)->exists())->toBeFalse()
        ->and($request->refresh()->status)->toBe(DeletionRequestStatus::Completed)
        ->and($request->overridden_by)->toBe($admin->id)
        ->and(VolumeRetirement::query()->sole()->confirmed_by)->toBe($admin->id)
        ->and(AuditEvent::query()->where('action', AuditAction::FinalBackupSkipped->value)->sole()->subject_id)->toBe($this->project->public_id);
});

test('a running backup or restore still blocks deleting a project without backups configured', function (): void {
    $destination = Backups::destination($this->team, $this->developer);
    BackupSchedule::query()->delete();
    $run = BackupRun::query()->create([
        'team_id' => $this->team->id, 'destination_id' => $destination->id, 'subject' => BackupSubject::Database,
        'service_instance_id' => $this->database->id, 'reason' => BackupReason::Manual,
    ]);
    $run->transitionTo(BackupRunStatus::Running);
    $run->transitionTo(BackupRunStatus::Failed, 'x');
    BackupRun::query()->create([
        'team_id' => $this->team->id, 'destination_id' => $destination->id, 'subject' => BackupSubject::Database,
        'service_instance_id' => $this->database->id, 'reason' => BackupReason::Manual,
    ]);

    ($this->delete)()->assertSessionHasErrors(['project' => 'A backup or restore of a service in this project is running. Wait for it to finish, then delete the project.']);
    expect(Project::query()->whereKey($this->project->id)->exists())->toBeTrue();
});

test('deleting tears the project down through desired state and is audited', function (): void {
    expect(Placement::query()->where('service_instance_id', $this->database->id)->exists())->toBeTrue();
    $volumes = Volume::query()->count();
    Event::fake([NodeStateInvalidated::class]);

    ($this->delete)()->assertRedirect(route('projects.index'))->assertSessionHasNoErrors();

    expect(Project::query()->whereKey($this->project->id)->exists())->toBeFalse()
        ->and(ServiceInstance::query()->whereKey($this->database->id)->exists())->toBeFalse()
        ->and(Placement::query()->where('server_id', $this->server->id)->exists())->toBeFalse()
        ->and($volumes)->toBeGreaterThan(0);

    Event::assertDispatched(NodeStateInvalidated::class, fn (NodeStateInvalidated $event): bool => in_array($this->server->id, $event->serverIds, true));

    $audit = AuditEvent::query()->where('action', AuditAction::ProjectDeleted->value)->sole();
    expect($audit->subject_id)->toBe($this->project->public_id)
        ->and($audit->meta)->toMatchArray(['name' => $this->project->name, 'services' => 1, 'environments' => 1]);
});

test('the server stops the project’s workloads with the next node state', function (): void {
    ($this->delete)()->assertRedirect();

    $state = Projects::nodeState($this->server->refresh());
    expect($state['spec']['workloads'] ?? [])->toBe([]);
});
