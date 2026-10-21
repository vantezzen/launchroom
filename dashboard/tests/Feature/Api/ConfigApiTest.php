<?php

declare(strict_types=1);

use App\Enums\ApiAbility;
use App\Enums\TeamRole;
use App\Enums\VariableType;
use App\Models\AuditEvent;
use Tests\Support\Api;
use Tests\Support\DeploymentUi;
use Tests\Support\Projects;

beforeEach(fn () => Projects::useTestKek());

test('export needs env:read, never contains secret values and is audited', function (): void {
    $world = DeploymentUi::make();
    $world->variable('LOG_LEVEL', 'info');
    $world->variable('API_SECRET', 'hunter2-very-secret', VariableType::Secret);
    $uri = "environments/{$world->production->public_id}/config";

    Api::get($uri, Api::token($world->member(TeamRole::Owner), $world->team, [ApiAbility::Read]))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'missing_ability');

    $response = Api::get($uri, Api::token($world->member(TeamRole::Viewer), $world->team, [ApiAbility::Read, ApiAbility::EnvRead]))->assertOk();
    $file = json_decode((string) $response->json('data.content'), true);

    expect($response->json('data.path'))->toBe('launchroom.json')
        ->and($file['services'][$world->web->slug]['variables'])->toMatchArray(['LOG_LEVEL' => 'info', 'API_SECRET' => ['secret' => true]])
        ->and((string) $response->getContent())->not->toContain('hunter2')
        ->and(AuditEvent::query()->where('action', 'config_file.exported')->count())->toBe(1);

    Api::get($uri.'?revision=99', Api::token($world->member(TeamRole::Owner), $world->team))->assertNotFound();
});

test('validate reports problems with positions and stores nothing', function (): void {
    $world = DeploymentUi::make();
    $plain = Api::token($world->member(TeamRole::Viewer), $world->team, [ApiAbility::Read]);

    Api::call('POST', "environments/{$world->production->public_id}/config/validate", $plain, ['content' => "{\n  \"version\": 1,\n  \"services\": { \"web\": { \"kind\": \"app\", \"buld\": {} } }\n}"])
        ->assertOk()
        ->assertJsonPath('data.valid', false)
        ->assertJsonPath('data.errors.0.line', 3)
        ->assertJsonPath('data.errors.0.column', 41)
        ->assertJsonPath('data.errors.0.hint', 'Did you mean "build"?')
        ->assertJsonPath('data.errors.0.path', 'services.web.buld');

    Api::call('POST', "environments/{$world->production->public_id}/config/validate", $plain, ['content' => '{"version": 1, "services": {}}'])
        ->assertOk()
        ->assertJsonPath('data.valid', true);
});

test('diff lists the changes the file would stage', function (): void {
    $world = DeploymentUi::make();
    $plain = Api::token($world->member(TeamRole::Viewer), $world->team, [ApiAbility::Read]);

    $changes = Api::call('POST', "environments/{$world->production->public_id}/config/diff", $plain, ['content' => json_encode(['version' => 1, 'services' => [
        $world->web->slug => ['kind' => $world->web->kind->value, 'scaling' => ['replicas' => 3]],
    ]])])->assertOk()->assertJsonPath('data.valid', true)->json('data.changes');

    expect(collect($changes)->pluck('path')->all())->toContain("services.{$world->web->public_id}.scaling.replicas");
});

test('another team\'s environment is not found', function (): void {
    $world = DeploymentUi::make();
    $stranger = DeploymentUi::make();

    Api::call('POST', "environments/{$world->production->public_id}/config/validate", Api::token($stranger->member(TeamRole::Owner), $stranger->team), ['content' => '{}'])
        ->assertNotFound();
});
