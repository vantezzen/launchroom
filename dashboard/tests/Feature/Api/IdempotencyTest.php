<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\ApiIdempotencyKey;
use App\Models\Variable;
use Tests\Support\Api;
use Tests\Support\DeploymentUi;
use Tests\Support\Projects;

beforeEach(fn () => Projects::useTestKek());

test('a replayed Idempotency-Key returns the first response without doing it again', function (): void {
    $world = DeploymentUi::make();
    $plain = Api::token($world->member(TeamRole::Owner), $world->team);
    $uri = "environments/{$world->production->public_id}/services/{$world->web->public_id}/variables";
    $headers = ['Idempotency-Key' => 'ci-run-42'];

    $first = Api::call('POST', $uri, $plain, ['key' => 'ONCE', 'value' => '1'], $headers)->assertCreated();
    $again = Api::call('POST', $uri, $plain, ['key' => 'ONCE', 'value' => '1'], $headers)
        ->assertCreated()
        ->assertHeader('Idempotent-Replayed', 'true');

    expect($again->json('data.id'))->toBe($first->json('data.id'))
        ->and(Variable::query()->where('key', 'ONCE')->count())->toBe(1);

    Api::call('POST', $uri, $plain, ['key' => 'ONCE', 'value' => '2'], $headers)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'idempotency_key_reused');

    // Keys are per token.
    $other = Api::token($world->member(TeamRole::Owner), $world->team);
    Api::call('POST', $uri, $other, ['key' => 'TWICE', 'value' => '1'], $headers)->assertCreated();
});

test('a malformed key is a validation error', function (): void {
    $world = DeploymentUi::make(false);
    $plain = Api::token($world->member(TeamRole::Owner), $world->team);

    Api::call('POST', "environments/{$world->production->public_id}/deploy", $plain, [], ['Idempotency-Key' => 'spaces are bad'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed');
});

test('a key still running is a conflict', function (): void {
    $world = DeploymentUi::make(false);
    $plain = Api::token($world->member(TeamRole::Owner), $world->team);
    [$id] = explode('|', $plain, 2);
    $uri = "environments/{$world->production->public_id}/deploy";

    ApiIdempotencyKey::query()->create([
        'personal_access_token_id' => (int) $id,
        'key' => 'busy',
        'request_hash' => hash('sha256', 'POST'."\napi/v1/{$uri}\n".'[]'),
        'created_at' => now(),
    ]);

    Api::call('POST', $uri, $plain, [], ['Idempotency-Key' => 'busy'])
        ->assertConflict()
        ->assertJsonPath('error.code', 'idempotency_key_in_progress');
});
