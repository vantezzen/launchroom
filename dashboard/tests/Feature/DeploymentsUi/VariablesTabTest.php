<?php

declare(strict_types=1);

use App\Enums\AuditAction;
use App\Enums\TeamRole;
use App\Enums\VariableType;
use App\Models\AuditEvent;
use App\Models\Variable;
use App\Queries\ServiceVariablesView;
use App\Services\Variables\VariableStore;
use Tests\Support\DeploymentUi;
use Tests\Support\Projects;

beforeEach(fn () => Projects::useTestKek());

test('the tab lists config values, masks secrets everywhere and previews references', function (): void {
    $world = DeploymentUi::make();
    $world->variable('APP_NAME', 'Shop');
    $world->variable('API_TOKEN', 'tok_super_secret_value', VariableType::Secret);
    $world->variable('GREETING', 'Hello from ${{APP_NAME}} at ${{PRIVATE_URL}}');
    $world->variable('BROKEN', '${{postgres.NOPE}}');

    $response = $this->actingAs($world->member(TeamRole::Viewer))
        ->getJson(route('variables.index', $world->serviceArgs()))
        ->assertOk();
    $rows = collect($response->json('variables'))->keyBy('key');

    expect($rows['APP_NAME']['value'])->toBe('Shop')
        ->and($rows['API_TOKEN']['value'])->toBeNull()
        ->and($rows['API_TOKEN']['canReveal'])->toBeFalse()
        ->and($rows['GREETING']['preview'])->toBe('Hello from Shop at http://web:80')
        ->and($rows['BROKEN']['errors'])->toBe(["\${{postgres.NOPE}} refers to a variable that doesn't exist"])
        // Connection-owned DATABASE_URL embeds the generated password: masked.
        ->and($rows['DATABASE_URL']['connection'])->toBe('postgres')
        ->and($rows['DATABASE_URL']['preview'])->toContain('••••••@postgres:5432')
        ->and($rows['DATABASE_URL']['canEdit'])->toBeFalse()
        ->and($response->json('canWrite'))->toBeFalse()
        ->and(collect($response->json('system'))->pluck('key'))->toContain('LAUNCHROOM_SERVICE')
        ->and(collect($response->json('scopes'))->pluck('scope'))->toContain('postgres')
        ->and($response->getContent())->not->toContain('tok_super_secret_value');

    $password = resolve(VariableStore::class)->plaintext($world->project, Variable::query()->where('service_id', $world->postgres->id)->where('key', 'POSTGRES_PASSWORD')->sole());
    expect($response->getContent())->not->toContain($password);
});

test('create, update and delete are staged and need write permission', function (): void {
    $world = DeploymentUi::make();
    $viewer = $world->member(TeamRole::Viewer);
    $developer = $world->member(TeamRole::Developer);
    $payload = ['key' => 'FEATURE_X', 'value' => 'on', 'type' => 'config', 'targets' => [['environmentId' => $world->production->public_id]]];

    $this->actingAs($viewer)->postJson(route('variables.store', $world->serviceArgs()), $payload)->assertForbidden();
    $id = $this->actingAs($developer)->postJson(route('variables.store', $world->serviceArgs()), $payload)->assertCreated()->json('id');

    $this->actingAs($developer)->postJson(route('variables.store', $world->serviceArgs()), [...$payload, 'key' => '1BAD'])->assertUnprocessable();
    $this->actingAs($developer)->postJson(route('variables.store', $world->serviceArgs()), [...$payload, 'key' => 'NEXT_PUBLIC_X', 'type' => 'secret'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.type.0', "NEXT_PUBLIC_X is baked into the browser bundle at build time and visible to anyone, so it can't be a secret.");

    $args = [...$world->envArgs(), 'variable' => $id];
    $this->actingAs($viewer)->patchJson(route('variables.update', $args), ['value' => 'off'])->assertForbidden();
    $this->actingAs($developer)->patchJson(route('variables.update', $args), ['value' => 'off'])->assertNoContent();

    expect(resolve(VariableStore::class)->plaintext($world->project, Variable::query()->where('public_id', $id)->sole()))->toBe('off');

    $this->actingAs($viewer)->deleteJson(route('variables.destroy', $args))->assertForbidden();
    $this->actingAs($developer)->deleteJson(route('variables.destroy', $args))->assertNoContent();
    expect(Variable::query()->where('public_id', $id)->exists())->toBeFalse();

    // Connection-owned rows are managed through the connection.
    $owned = Variable::query()->whereNotNull('connection_id')->firstOrFail();
    $this->actingAs($developer)->deleteJson(route('variables.destroy', [...$world->envArgs(), 'variable' => $owned->public_id]))->assertForbidden();
});

test('a secret can only become config for members who may reveal it', function (): void {
    $world = DeploymentUi::make();
    $secret = $world->variable('API_TOKEN', 'tok', VariableType::Secret);
    $args = [...$world->envArgs(), 'variable' => $secret->public_id];

    $this->actingAs($world->member(TeamRole::Developer))->patchJson(route('variables.update', $args), ['type' => 'config'])->assertForbidden();
    $this->actingAs($world->member(TeamRole::Admin))->patchJson(route('variables.update', $args), ['type' => 'config'])->assertNoContent();
});

test('variables of another project or team are not found', function (): void {
    $world = DeploymentUi::make();
    $other = DeploymentUi::make(deploy: false);
    $foreign = $other->variable('X', '1');

    $this->actingAs($world->member(TeamRole::Owner))
        ->patchJson(route('variables.update', [...$world->envArgs(), 'variable' => $foreign->public_id]), ['value' => '2'])
        ->assertNotFound();
});

test('reveal: permission, password confirmation and audit', function (): void {
    $world = DeploymentUi::make();
    $secret = $world->variable('API_TOKEN', 'tok_super_secret_value', VariableType::Secret);
    $config = $world->variable('APP_NAME', 'Shop');
    $args = [...$world->envArgs(), 'variable' => $secret->public_id];

    $this->actingAs($world->member(TeamRole::Developer))->postJson(route('variables.reveal', $args))->assertForbidden();

    $admin = $world->member(TeamRole::Admin);
    $this->actingAs($admin)->postJson(route('variables.reveal', $args))->assertStatus(423)->assertJsonPath('requiresPassword', true);
    $this->actingAs($admin)->postJson(route('variables.reveal', $args), ['password' => 'wrong'])->assertUnprocessable();

    expect(AuditEvent::query()->where('action', AuditAction::SecretRevealed->value)->count())->toBe(0);

    $this->actingAs($admin)->postJson(route('variables.reveal', $args), ['password' => 'password'])
        ->assertOk()
        ->assertJsonPath('value', 'tok_super_secret_value')
        ->assertHeader('Cache-Control', 'no-store, private');

    // Recently confirmed: no password needed for the next one.
    $this->actingAs($admin)->postJson(route('variables.reveal', $args))->assertOk();

    expect(AuditEvent::query()->where('action', AuditAction::SecretRevealed->value)->count())->toBe(2);

    // Config values need no confirmation (viewers can read them anyway).
    $this->actingAs($world->member(TeamRole::Viewer))
        ->postJson(route('variables.reveal', [...$world->envArgs(), 'variable' => $config->public_id]))
        ->assertOk()
        ->assertJsonPath('value', 'Shop');
});

test('import preview returns keys and effects without values, then imports the chosen ones', function (): void {
    $world = DeploymentUi::make();
    $world->variable('EXISTING', 'old');
    $developer = $world->member(TeamRole::Developer);
    $content = "EXISTING=new\nSTRIPE_SECRET_KEY=sk_live_abcdef123456\nNEXT_PUBLIC_URL=https://x.test\nSKIPPED=1\n";

    $this->actingAs($world->member(TeamRole::Viewer))->postJson(route('variables.import.preview', $world->serviceArgs()), ['content' => $content])->assertForbidden();

    $preview = $this->actingAs($developer)->postJson(route('variables.import.preview', $world->serviceArgs()), ['content' => $content])->assertOk();
    $entries = collect($preview->json('entries'))->keyBy('key');

    expect($entries['EXISTING']['status'])->toBe('update')
        ->and($entries['STRIPE_SECRET_KEY']['suggestedType'])->toBe('secret')
        ->and($entries['NEXT_PUBLIC_URL']['browserInlined'])->toBeTrue()
        ->and($preview->getContent())->not->toContain('sk_live_abcdef123456');

    $this->actingAs($developer)->postJson(route('variables.import', $world->serviceArgs()), [
        'content' => $content,
        'only' => ['EXISTING', 'STRIPE_SECRET_KEY', 'NEXT_PUBLIC_URL'],
        'types' => ['STRIPE_SECRET_KEY' => 'secret'],
    ])->assertOk()->assertJsonPath('updated', ['EXISTING']);

    expect(Variable::query()->where('key', 'SKIPPED')->exists())->toBeFalse()
        ->and(Variable::query()->where('key', 'STRIPE_SECRET_KEY')->sole()->type)->toBe(VariableType::Secret);

    $this->actingAs($developer)->postJson(route('variables.import.preview', $world->serviceArgs()), ['content' => "BAD LINE\n"])
        ->assertOk()
        ->assertJsonCount(1, 'errors');
});

test('raw mode: secrets as placeholders keep their value; edits, removals and splits are staged', function (): void {
    $world = DeploymentUi::make();
    $secret = $world->variable('API_TOKEN', 'tok_super_secret_value', VariableType::Secret);
    $world->variable('APP_NAME', 'Shop');
    $world->variable('REMOVE_ME', 'x');
    $developer = $world->member(TeamRole::Developer);

    $raw = $this->actingAs($developer)->getJson(route('variables.raw', $world->serviceArgs()))->assertOk()->json('content');

    expect($raw)->toContain('API_TOKEN='.ServiceVariablesView::SECRET_PLACEHOLDER)
        ->and($raw)->toContain('APP_NAME=Shop')
        ->and($raw)->not->toContain('tok_super_secret_value');

    $this->actingAs($world->member(TeamRole::Viewer))->getJson(route('variables.raw', $world->serviceArgs()))->assertForbidden();

    $result = $this->actingAs($developer)->putJson(route('variables.raw.update', $world->serviceArgs()), [
        'content' => 'API_TOKEN='.ServiceVariablesView::SECRET_PLACEHOLDER."\nAPP_NAME=\"Shop 2\"\nNEW_ONE=1\n",
    ])->assertOk()->json();

    expect($result['created'])->toBe(['NEW_ONE'])
        ->and($result['updated'])->toBe(['APP_NAME'])
        ->and($result['removed'])->toBe(['REMOVE_ME'])
        ->and(resolve(VariableStore::class)->plaintext($world->project, $secret->refresh()))->toBe('tok_super_secret_value');

    $this->actingAs($developer)->putJson(route('variables.raw.update', $world->serviceArgs()), [
        'content' => 'APP_NAME='.ServiceVariablesView::SECRET_PLACEHOLDER."\n",
    ])->assertUnprocessable();
});

test('shared variables: opt a service in and out', function (): void {
    $world = DeploymentUi::make();
    $shared = $world->sharedVariable('SENTRY_DSN_PUBLIC', 'https://sentry.test/1');
    $args = [...$world->serviceArgs(), 'variable' => $shared->public_id];

    $this->actingAs($world->member(TeamRole::Viewer))->putJson(route('variables.shared.usage', $args), ['used' => true])->assertForbidden();
    $this->actingAs($world->member(TeamRole::Developer))->putJson(route('variables.shared.usage', $args), ['used' => true])->assertNoContent();

    $rows = $this->actingAs($world->member(TeamRole::Viewer))->getJson(route('variables.index', $world->serviceArgs()))->json('shared');
    expect($rows[0])->toMatchArray(['key' => 'SENTRY_DSN_PUBLIC', 'used' => true, 'value' => 'https://sentry.test/1']);
});
