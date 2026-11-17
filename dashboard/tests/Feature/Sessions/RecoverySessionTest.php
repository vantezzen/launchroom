<?php

declare(strict_types=1);

use App\Data\Deployments\WorkloadIssueData;
use App\Enums\AuditAction;
use App\Enums\DaemonTaskPurpose;
use App\Enums\TeamRole;
use App\Enums\WorkloadIssueKind;
use App\Enums\WorkloadPhase;
use App\Models\AuditEvent;
use App\Models\DaemonTask;
use App\Models\Placement;
use App\Models\Sessions\InteractiveSession;
use App\Services\Sessions\SessionTargets;
use Tests\Support\Sessions;

// Recovery shells on the Console tab (OD9, docs/03 §11): a copy of a
// replica whose container won't stay up, volumes read-only unless allowed.

beforeEach(function (): void {
    $world = Sessions::world();
    $this->team = $world['team'];
    $this->server = $world['server'];
    $this->instance = $world['instance'];
    $this->server->forceFill(['daemon_features' => ['consoleSessions', 'dataSessions', 'recoverySessions']])->save();
    $this->placement = Placement::query()->where('service_instance_id', $this->instance->id)->where('deployment_id', $this->instance->active_deployment_id)->firstOrFail();
    $this->owner = memberOf($this->team, TeamRole::Owner);
});

function crash(Placement $placement, WorkloadPhase $phase, int $restarts = 0): void
{
    $placement->forceFill([
        'phase' => $phase,
        'restart_count' => $restarts,
        'issue' => $restarts > 0 ? new WorkloadIssueData(kind: WorkloadIssueKind::CrashLoop, since: now()->toIso8601String(), exitCode: 1, restarts: $restarts) : null,
    ])->save();
}

test('a running service offers no recovery shell and refuses one', function (): void {
    expect($this->actingAs($this->owner)->getJson(Sessions::url($this->instance, 'console'))->json('recovery'))->toBeNull();

    $this->actingAs($this->owner)->postJson(Sessions::url($this->instance, 'recovery'))
        ->assertConflict()
        ->assertJsonFragment(['message' => 'Every replica of this service is running. Open a console instead.']);
});

test('the console tab offers a recovery shell while the container won’t stay up', function (): void {
    crash($this->placement, WorkloadPhase::Exited);

    $recovery = $this->actingAs($this->owner)->getJson(Sessions::url($this->instance, 'console'))->assertOk()->json('recovery');

    expect($recovery['available'])->toBeTrue()
        ->and($recovery['unavailableReason'])->toBeNull()
        ->and($recovery['replicas'])->toHaveCount(1)
        ->and($recovery['replicas'][0]['workload'])->toBe($this->placement->workload_id)
        ->and($recovery['changesNeedStop'])->toBeFalse();
});

test('opening a recovery shell is read-only by default, pinned and audited', function (): void {
    crash($this->placement, WorkloadPhase::Exited);

    $opened = Sessions::open($this, $this->owner, $this->instance, 'recovery', ['shell' => '/bin/sh', 'cols' => 100, 'rows' => 30]);
    $session = InteractiveSession::query()->where('public_id', $opened['id'])->sole();
    $task = DaemonTask::query()->findOrFail($session->daemon_task_id);

    expect($opened['kind'])->toBe('recovery')
        ->and($task->server_id)->toBe($this->server->id)
        ->and($task->purpose)->toBe(DaemonTaskPurpose::RecoverySession)
        ->and((array) $task->spec)->toMatchArray(['kind' => 'recovery', 'workload' => $this->placement->workload_id, 'shell' => '/bin/sh', 'cols' => 100, 'rows' => 30])
        ->and((array) $task->spec)->not->toHaveKey('allowChanges');

    $audit = AuditEvent::query()->where('action', AuditAction::RecoverySessionStarted->value)->sole();
    expect($audit->meta)->toMatchArray(['session' => $session->public_id, 'allowChanges' => false]);
});

test('allowing changes is sent to the server and recorded in the audit log', function (): void {
    crash($this->placement, WorkloadPhase::Exited);

    $opened = Sessions::open($this, $this->owner, $this->instance, 'recovery', ['allowChanges' => true]);
    $task = DaemonTask::query()->findOrFail(InteractiveSession::query()->where('public_id', $opened['id'])->sole()->daemon_task_id);

    expect((array) $task->spec)->toMatchArray(['allowChanges' => true])
        ->and(AuditEvent::query()->where('action', AuditAction::RecoverySessionStarted->value)->sole()->meta)->toMatchArray(['allowChanges' => true]);
});

test('changes are refused while a container of a service with data still runs between restarts', function (): void {
    crash($this->placement, WorkloadPhase::Unhealthy, restarts: 4);

    expect($this->actingAs($this->owner)->getJson(Sessions::url($this->instance, 'console'))->json('recovery.changesNeedStop'))->toBeTrue();

    $this->actingAs($this->owner)->postJson(Sessions::url($this->instance, 'recovery'), ['allowChanges' => true])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('allowChanges');

    Sessions::open($this, $this->owner, $this->instance, 'recovery');
});

test('a server without recovery shells says so and refuses', function (): void {
    crash($this->placement, WorkloadPhase::Exited);
    $this->server->forceFill(['daemon_features' => ['consoleSessions']])->save();

    $recovery = $this->actingAs($this->owner)->getJson(Sessions::url($this->instance, 'console'))->json('recovery');
    expect($recovery['available'])->toBeFalse()
        ->and($recovery['unavailableReason'])->toContain($this->server->name)
        ->and($recovery['replicas'])->toBe([]);

    $this->actingAs($this->owner)->postJson(Sessions::url($this->instance, 'recovery'))->assertConflict();
});

test('developers without exec access can’t open recovery shells', function (): void {
    crash($this->placement, WorkloadPhase::Exited);
    $developer = memberOf($this->team, TeamRole::Developer);

    $this->actingAs($developer)->postJson(Sessions::url($this->instance, 'recovery'))->assertForbidden();
});

test('a recovery session stays allowed while its replica is exited', function (): void {
    crash($this->placement, WorkloadPhase::Exited);
    $opened = Sessions::open($this, $this->owner, $this->instance, 'recovery');
    $session = InteractiveSession::query()->where('public_id', $opened['id'])->sole();

    expect(resolve(SessionTargets::class)->stillPlaced($this->instance, $session->workload_id, $this->server->id, $session->kind))->toBeTrue()
        ->and(resolve(SessionTargets::class)->stillPlaced($this->instance, $session->workload_id, $this->server->id))->toBeFalse();
});
