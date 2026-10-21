<?php

declare(strict_types=1);

use App\Actions\Environments\CreateEnvironment;
use App\Data\Audit\AuditActor;
use App\Data\Environments\CreateEnvironmentData;
use App\Enums\ApiAbility;
use App\Enums\TeamRole;
use App\Enums\VariableType;
use App\Models\AuditEvent;
use App\Models\Variable;
use Tests\Support\Api;
use Tests\Support\DeploymentUi;
use Tests\Support\Projects;

beforeEach(fn () => Projects::useTestKek());

function variablesUri(DeploymentUi $world): string
{
    return "environments/{$world->production->public_id}/services/{$world->web->public_id}/variables";
}

test('lists never contain secret values', function (): void {
    $world = DeploymentUi::make();
    $world->variable('APP_NAME', 'shop');
    $world->variable('API_SECRET', 'hunter2-very-secret', VariableType::Secret);
    $plain = Api::token($world->member(TeamRole::Owner), $world->team);

    $response = Api::get(variablesUri($world), $plain)->assertOk();
    $rows = collect($response->json('data'))->keyBy('key');

    expect($rows['APP_NAME']['value'])->toBe('shop')
        ->and($rows['API_SECRET']['value'])->toBeNull()
        ->and($rows['API_SECRET']['type'])->toBe('secret')
        ->and((string) $response->getContent())->not->toContain('hunter2');
});

test('create, update (this environment only) and delete', function (): void {
    $world = DeploymentUi::make();
    $plain = Api::token($world->member(TeamRole::Developer), $world->team, [ApiAbility::Read, ApiAbility::EnvRead, ApiAbility::EnvWrite]);
    $staging = resolve(CreateEnvironment::class)->handle($world->project, new CreateEnvironmentData('staging'), null, AuditActor::system());

    $created = Api::call('POST', variablesUri($world), $plain, ['key' => 'LOG_LEVEL', 'value' => 'info'])
        ->assertCreated()
        ->assertJsonPath('data.value', 'info')
        ->assertJsonPath('data.targets.0.environmentId', $world->production->public_id)
        ->json('data');

    // A row that also targets staging is split: staging keeps its value.
    $variable = Variable::query()->where('public_id', $created['id'])->sole();
    $variable->targets()->create(['environment_id' => $staging->id, 'all_previews' => false]);

    Api::call('PATCH', "environments/{$world->production->public_id}/variables/{$created['id']}", $plain, ['value' => 'debug'])->assertOk();

    expect(Variable::query()->where('project_id', $world->project->id)->where('key', 'LOG_LEVEL')->count())->toBe(2);

    $rows = collect(Api::get(variablesUri($world), $plain)->json('data'))->where('key', 'LOG_LEVEL')->values();
    expect($rows)->toHaveCount(1)->and($rows[0]['value'])->toBe('debug');

    Api::call('DELETE', "environments/{$world->production->public_id}/variables/{$rows[0]['id']}", $plain)->assertNoContent();
    Api::call('POST', variablesUri($world), $plain, ['key' => '1BAD', 'value' => 'x'])->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
});

test('a secret value needs secrets:read and the reveal permission, and is audited', function (): void {
    $world = DeploymentUi::make();
    $secret = $world->variable('API_SECRET', 'hunter2', VariableType::Secret);
    $config = $world->variable('APP_NAME', 'shop');
    $owner = $world->member(TeamRole::Owner);
    $uri = fn (Variable $v): string => "environments/{$world->production->public_id}/variables/{$v->public_id}/value";

    $envOnly = Api::token($owner, $world->team, [ApiAbility::Read, ApiAbility::EnvRead]);
    Api::get($uri($config), $envOnly)->assertOk()->assertJsonPath('data.value', 'shop');
    Api::get($uri($secret), $envOnly)->assertForbidden();

    $withSecrets = Api::token($owner, $world->team, [ApiAbility::Read, ApiAbility::EnvRead, ApiAbility::SecretsRead]);
    Api::get($uri($secret), $withSecrets)->assertOk()->assertJsonPath('data.value', 'hunter2')->assertHeader('Cache-Control', 'no-store, private');

    $audit = AuditEvent::query()->where('action', 'variable.secret_revealed')->sole();
    expect($audit->meta['via_token'])->toStartWith('tok_')
        ->and(json_encode($audit->meta))->not->toContain('hunter2');

    // Developers can't reveal secrets, whatever their token says.
    $developer = Api::rawToken($world->member(TeamRole::Developer), $world->team, ['read', 'env:read', 'secrets:read']);
    Api::get($uri($secret), $developer)->assertForbidden();
});

test('the resolved env withholds secrets unless asked and allowed', function (): void {
    $world = DeploymentUi::make();
    $world->variable('APP_NAME', 'shop');
    $world->variable('API_SECRET', 'hunter2', VariableType::Secret);
    $world->deploy();
    $owner = $world->member(TeamRole::Owner);
    $uri = "environments/{$world->production->public_id}/services/{$world->web->public_id}/env";

    $plain = Api::token($owner, $world->team, [ApiAbility::Read, ApiAbility::EnvRead]);
    $response = Api::get($uri.'?secrets=1', $plain)->assertOk();

    expect($response->json('data.variables.APP_NAME'))->toBe('shop')
        ->and($response->json('data.variables'))->not->toHaveKey('API_SECRET')
        ->and($response->json('data.withheld'))->toContain('API_SECRET', 'DATABASE_URL')
        ->and($response->json('data.revision'))->toBe(2)
        ->and((string) $response->getContent())->not->toContain('hunter2');

    $secrets = Api::token($owner, $world->team, [ApiAbility::Read, ApiAbility::EnvRead, ApiAbility::SecretsRead]);
    Api::get($uri, $secrets)->assertOk()->assertJsonMissingPath('data.variables.API_SECRET');
    Api::get($uri.'?secrets=1', $secrets)->assertOk()->assertJsonPath('data.variables.API_SECRET', 'hunter2')->assertJsonPath('data.withheld', []);

    $audits = AuditEvent::query()->where('action', 'variable.env_exported')->orderBy('id')->get();
    expect($audits)->toHaveCount(3)
        ->and($audits[0]->meta['with_secrets'])->toBeFalse()
        ->and($audits[2]->meta['with_secrets'])->toBeTrue();
});
