<?php

declare(strict_types=1);

use App\Enums\ApiAbility;
use App\Enums\TeamRole;
use App\Models\ServiceInstance;
use Tests\Support\Api;
use Tests\Support\DeploymentUi;
use Tests\Support\Projects;

/*
| A service whose removal is staged keeps running until the change is applied
| and still shows on the canvas (docs/06 §4, docs/08 §2). It resolves for
| every route like any other service; changes to it answer 409 on the web and
| the API alike (App\Http\Routing\RouteScope).
*/

beforeEach(function (): void {
    Projects::useTestKek();
    $this->world = DeploymentUi::make();
    $this->developer = $this->world->member(TeamRole::Developer);
    ServiceInstance::query()->where('service_id', $this->world->web->id)->sole()->forceFill(['removed_at' => now()])->save();
});

test('a staged-removed service resolves for reads on the web and the API', function (): void {
    $args = $this->world->serviceArgs();

    $this->actingAs($this->developer)->get(route('deployments.service', $args))->assertOk();

    $token = Api::token($this->developer, $this->world->team, [ApiAbility::Read]);
    Api::get("environments/{$this->world->production->public_id}/services/{$this->world->web->public_id}", $token)->assertOk();
});

test('a staged-removed service still shows its variables, read-only', function (): void {
    $instance = ServiceInstance::query()->where('service_id', $this->world->web->id)->sole();
    $instance->forceFill(['removed_at' => null])->save();
    $this->world->variable('APP_NAME', 'Shop');
    $instance->forceFill(['removed_at' => now()])->save();
    $args = $this->world->serviceArgs();

    $response = $this->actingAs($this->developer)->getJson(route('variables.index', $args))->assertOk();

    expect(collect($response->json('variables'))->pluck('key'))->toContain('APP_NAME')
        ->and($response->json('canWrite'))->toBeFalse();
    $this->actingAs($this->developer)->postJson(route('variables.store', $args), ['key' => 'X', 'value' => '1', 'type' => 'config', 'targets' => [['environmentId' => $this->world->production->public_id]]])->assertStatus(409);
});

test('changes to a staged-removed service are refused with 409 on the web and the API', function (): void {
    $args = $this->world->serviceArgs();

    foreach (['services.lifecycle.restart', 'services.lifecycle.stop', 'services.lifecycle.start', 'services.lifecycle.deploy', 'deployments.service.redeploy'] as $name) {
        $this->actingAs($this->developer)->post(route($name, $args))->assertStatus(409);
    }

    $token = Api::token($this->developer, $this->world->team, [ApiAbility::Read, ApiAbility::Deploy]);
    foreach (['restart', 'stop', 'start', 'deploy'] as $action) {
        Api::call('POST', "environments/{$this->world->production->public_id}/services/{$this->world->web->public_id}/{$action}", $token)->assertStatus(409);
    }
});
