<?php

declare(strict_types=1);

use App\Enums\ApiAbility;
use App\Enums\AuditAction;
use App\Enums\DeploymentTrigger;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\ServiceInstance;
use Tests\Support\Api;
use Tests\Support\DeploymentUi;
use Tests\Support\Projects;

/*
 * Stop, Start, Restart and Deploy of a service through the API (D68,
 * docs/16 §2.2): the deploy ability, the environment's deploy permission,
 * audited with the token.
 */

beforeEach(fn () => Projects::useTestKek());

function serviceActionUri(DeploymentUi $world, string $action): string
{
    return "environments/{$world->production->public_id}/services/{$world->web->public_id}/{$action}";
}

test('stop, start, restart and deploy a service', function (): void {
    $world = DeploymentUi::make();
    $active = $world->latest($world->web);
    $plain = Api::token($world->member(TeamRole::Developer), $world->team, [ApiAbility::Read, ApiAbility::Deploy]);

    $stopped = Api::call('POST', serviceActionUri($world, 'stop'), $plain)
        ->assertOk()
        ->assertJsonPath('data.service.status', 'stopped')
        ->assertJsonPath('data.deployments', []);
    expect($stopped->json('data.service.stoppedAt'))->not->toBeNull()
        ->and(AuditEvent::query()->where('action', AuditAction::ServiceStopped->value)->latest('id')->first()?->meta['via_token'] ?? null)->toStartWith('tok_');

    Api::get("environments/{$world->production->public_id}/services/{$world->web->public_id}", $plain)->assertJsonPath('data.status', 'stopped');

    // Deploy and restart wait for Start; stopping again is refused.
    Api::call('POST', serviceActionUri($world, 'deploy'), $plain)->assertStatus(422)->assertJsonPath('error.code', 'rule_violation');
    Api::call('POST', serviceActionUri($world, 'restart'), $plain)->assertStatus(422)->assertJsonPath('error.code', 'rule_violation');
    Api::call('POST', serviceActionUri($world, 'stop'), $plain)->assertStatus(422);

    $started = Api::call('POST', serviceActionUri($world, 'start'), $plain, ['processes' => false])
        ->assertStatus(202)
        ->assertJsonPath('data.service.stoppedAt', null)
        ->assertJsonPath('data.deployments.0.serviceId', $world->web->public_id);
    expect($started->json('data.service.status'))->not->toBe('stopped')
        ->and($world->latest($world->web)->trigger)->toBe(DeploymentTrigger::Start)
        ->and($world->latest($world->web)->image)->toBe($active->image);

    Projects::report($world->server);
    Projects::report($world->server);

    Api::call('POST', serviceActionUri($world, 'restart'), $plain)
        ->assertStatus(202)
        ->assertJsonCount(1, 'data.deployments');
    expect($world->latest($world->web)->trigger)->toBe(DeploymentTrigger::Restart);

    Projects::report($world->server);
    Projects::report($world->server);

    $deployed = Api::call('POST', serviceActionUri($world, 'deploy'), $plain)->assertStatus(202);
    expect($deployed->json('data.id'))->toBe($world->latest($world->web)->public_id)
        ->and($world->latest($world->web)->trigger)->toBe(DeploymentTrigger::Manual);
});

test('a viewer\'s token cannot stop a service, and another project\'s service is not found', function (): void {
    $world = DeploymentUi::make();
    $viewer = Api::rawToken($world->member(TeamRole::Viewer), $world->team, ApiAbility::values());

    Api::call('POST', serviceActionUri($world, 'stop'), $viewer)->assertForbidden()->assertJsonPath('error.code', 'forbidden');
    expect(ServiceInstance::query()->where('service_id', $world->web->id)->sole()->stopped_at)->toBeNull();

    $other = Projects::image(Projects::create($world->team, 'other'), 'api');
    $owner = Api::token($world->member(TeamRole::Owner), $world->team);
    Api::call('POST', "environments/{$world->production->public_id}/services/{$other->public_id}/stop", $owner)->assertNotFound();
});
