<?php

declare(strict_types=1);

use App\Actions\Domains\AddDomain;
use App\Data\Audit\AuditActor;
use App\Enums\ApiAbility;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\ServiceInstance;
use Tests\Support\Api;
use Tests\Support\DeploymentUi;
use Tests\Support\Projects;

beforeEach(fn () => Projects::useTestKek());

test('GET /me describes the token, its user and team', function (): void {
    $world = DeploymentUi::make(false);
    $developer = $world->member(TeamRole::Developer);
    $plain = Api::token($developer, $world->team, [ApiAbility::Read, ApiAbility::Deploy]);

    Api::get('me', $plain)
        ->assertOk()
        ->assertJsonPath('data.user.email', $developer->email)
        ->assertJsonPath('data.team.role', 'developer')
        ->assertJsonPath('data.token.abilities', ['read', 'deploy'])
        ->assertJsonPath('data.token.client', 'dashboard')
        ->assertJsonMissingPath('data.token.token');
});

test('projects, environments and services', function (): void {
    $world = DeploymentUi::make();
    $plain = Api::token($world->member(TeamRole::Viewer), $world->team, [ApiAbility::Read]);
    $env = $world->production->public_id;

    Api::get('projects', $plain)
        ->assertOk()
        ->assertJsonPath('data.0.name', 'shop')
        ->assertJsonPath('data.0.environments.0.kind', 'production')
        ->assertJsonPath('meta.nextCursor', null);

    Api::get("projects/{$world->project->public_id}/environments", $plain)->assertOk()->assertJsonPath('data.0.id', $env);
    Api::get("environments/{$env}", $plain)->assertOk()->assertJsonPath('data.revision', 1);

    $services = Api::get("environments/{$env}/services", $plain)->assertOk()->json('data');
    expect(array_column($services, 'name'))->toBe(['postgres', 'web'])
        ->and($services[1]['status'])->toBe('live')
        ->and($services[1]['activeDeployment']['status'])->toBe('active')
        ->and($services[1]['activeDeployment']['finished'])->toBeTrue();

    Api::get("environments/{$env}/services/{$world->web->public_id}", $plain)->assertOk()->assertJsonPath('data.slug', 'web');
});

test('nested ids must belong together', function (): void {
    $world = DeploymentUi::make(false);
    $other = Projects::create($world->team, 'other');
    $foreign = Projects::image($other, 'api');
    $plain = Api::token($world->member(TeamRole::Owner), $world->team);

    Api::get("environments/{$world->production->public_id}/services/{$foreign->public_id}", $plain)->assertNotFound();
});

test('malformed ids are 404 before any query', function (): void {
    $world = DeploymentUi::make(false);
    $plain = Api::token($world->member(TeamRole::Owner), $world->team);

    Api::get('projects/1', $plain)->assertNotFound()->assertJsonPath('error.code', 'not_found');
    Api::get('projects/prj_nope', $plain)->assertNotFound();
});

test('deployments are listed newest first with a cursor', function (): void {
    $world = DeploymentUi::make();
    $plain = Api::token($world->member(TeamRole::Owner), $world->team);
    $env = $world->production->public_id;
    Api::call('POST', "environments/{$env}/deployments", $plain, ['service' => $world->web->public_id, 'redeploy' => true])->assertStatus(202);

    $first = Api::get("environments/{$env}/deployments?limit=1&service={$world->web->public_id}", $plain)->assertOk();
    expect($first->json('data'))->toHaveCount(1)
        ->and($first->json('meta.nextCursor'))->toBeString();

    $second = Api::get("environments/{$env}/deployments?limit=1&service={$world->web->public_id}&cursor=".$first->json('meta.nextCursor'), $plain)->assertOk();
    expect($second->json('data.0.id'))->not->toBe($first->json('data.0.id'));

    Api::get("environments/{$env}/deployments?cursor=bogus!", $plain)->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
});

test('redeploy, cancel and the deployment status', function (): void {
    $world = DeploymentUi::make();
    $plain = Api::token($world->member(TeamRole::Developer), $world->team, [ApiAbility::Read, ApiAbility::Deploy]);
    $env = $world->production->public_id;

    $created = Api::call('POST', "environments/{$env}/deployments", $plain, ['service' => $world->web->public_id, 'redeploy' => true])
        ->assertStatus(202)
        ->assertJsonPath('data.serviceId', $world->web->public_id)
        ->json('data');

    Api::get("deployments/{$created['id']}", $plain)->assertOk()->assertJsonPath('data.id', $created['id']);

    expect(AuditEvent::query()->where('action', 'deployment.redeployed')->latest('id')->first()?->meta['via_token'] ?? null)->toStartWith('tok_');
});

test('image services refuse a git ref deploy with a rule violation', function (): void {
    $world = DeploymentUi::make();
    $plain = Api::token($world->member(TeamRole::Owner), $world->team);

    Api::call('POST', "environments/{$world->production->public_id}/deployments", $plain, ['service' => $world->web->public_id, 'sha' => str_repeat('a', 40)])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'rule_violation');
});

test('deploying staged changes through the API', function (): void {
    $world = DeploymentUi::make();
    $world->variable('FEATURE', 'on');
    $plain = Api::token($world->member(TeamRole::Owner), $world->team);

    Api::call('POST', "environments/{$world->production->public_id}/deploy", $plain, ['message' => 'from CI'])
        ->assertStatus(202)
        ->assertJsonPath('data.revision', 2);
});

test('servers and domains', function (): void {
    $world = DeploymentUi::make();
    $plain = Api::token($world->member(TeamRole::Owner), $world->team);
    $env = $world->production->public_id;
    $instance = ServiceInstance::query()->where('environment_id', $world->production->id)->where('service_id', $world->web->id)->sole();
    resolve(AddDomain::class)->handle($instance, 'shop.example.com', AuditActor::system());

    Api::get('servers', $plain)->assertOk()->assertJsonPath('data.0.id', $world->server->public_id)->assertJsonMissingPath('data.0.signingPublicKey');
    Api::get("servers/{$world->server->public_id}", $plain)->assertOk()->assertJsonPath('data.status', 'online');

    $domains = Api::get("environments/{$env}/services/{$world->web->public_id}/domains", $plain)->assertOk()->json('data');
    expect(array_column($domains, 'hostname'))->toContain('shop.example.com');

    $created = Api::call('POST', "environments/{$env}/services/{$world->web->public_id}/domains", $plain, ['hostname' => 'www.example.org'])
        ->assertCreated()
        ->assertJsonPath('data.kind', 'custom')
        ->json('data');

    Api::call('DELETE', "environments/{$env}/domains/{$created['id']}", $plain)->assertNoContent();
    Api::call('POST', "environments/{$env}/services/{$world->web->public_id}/domains", $plain, ['hostname' => 'bad host!'])->assertUnprocessable();
});
