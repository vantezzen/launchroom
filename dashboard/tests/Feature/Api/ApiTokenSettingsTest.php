<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\ApiToken;
use App\Models\AuditEvent;
use Inertia\Testing\AssertableInertia;
use Tests\Support\Api;
use Tests\Support\DeploymentUi;
use Tests\Support\Projects;

beforeEach(fn () => Projects::useTestKek());

test('the page lists your tokens and what your role allows', function (): void {
    $world = DeploymentUi::make(false);
    $developer = $world->member(TeamRole::Developer);
    Api::token($developer, $world->team);
    Api::token($world->member(TeamRole::Owner), $world->team);

    $this->actingAs($developer)
        ->get('/settings/api-tokens')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('settings/api-tokens')
            ->has('tokens', 1)
            ->missing('tokens.0.token')
            ->where('teamOptions.0.role', 'developer')
            ->where('teamOptions.0.abilities.4.value', 'secrets:read')
            ->where('teamOptions.0.abilities.4.available', false));
});

test('creating an authorized token shows the plaintext once without an extra password', function (): void {
    $world = DeploymentUi::make(false);
    $owner = $world->member(TeamRole::Owner);
    $body = ['name' => 'CI', 'expiresInDays' => 90, 'abilities' => ['deploy']];

    $created = $this->actingAs($owner)->postJson('/settings/api-tokens', $body)
        ->assertCreated()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('token.abilities', ['read', 'deploy'])
        ->json();

    $token = ApiToken::query()->sole();
    expect($created['plainText'])->toStartWith($token->id.'|lr_pat_')
        ->and($token->expires_at?->isSameDay(now()->addDays(90)))->toBeTrue()
        ->and($token->team_id)->toBe($world->team->id)
        ->and(AuditEvent::query()->where('action', 'api_token.created')->sole()->meta['abilities'])->toBe(['read', 'deploy']);

    Api::get('me', $created['plainText'])->assertOk();
});

test('lifetimes and abilities are checked', function (): void {
    $world = DeploymentUi::make(false);
    $viewer = $world->member(TeamRole::Viewer);

    $this->actingAs($viewer)->withSession(passwordConfirmed())
        ->postJson('/settings/api-tokens', ['name' => 'x', 'expiresInDays' => 1000, 'abilities' => ['read']])
        ->assertUnprocessable();

    $this->actingAs($viewer)->withSession(passwordConfirmed())
        ->postJson('/settings/api-tokens', ['name' => 'x', 'expiresInDays' => 30, 'abilities' => ['deploy']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('abilities');

    $this->actingAs($viewer)->withSession(passwordConfirmed())
        ->postJson('/settings/api-tokens', ['name' => 'x', 'expiresInDays' => 30, 'abilities' => ['*']])
        ->assertUnprocessable();
});

test('you revoke your own tokens only', function (): void {
    $world = DeploymentUi::make(false);
    $owner = $world->member(TeamRole::Owner);
    $developer = $world->member(TeamRole::Developer);
    $mine = Api::token($developer, $world->team);
    Api::token($owner, $world->team);
    $theirs = ApiToken::query()->where('tokenable_id', $owner->id)->sole();
    $own = ApiToken::query()->where('tokenable_id', $developer->id)->sole();

    $this->actingAs($developer)->delete("/settings/api-tokens/{$theirs->public_id}")->assertNotFound();
    $this->actingAs($developer)->delete("/settings/api-tokens/{$own->public_id}")->assertRedirect();

    expect(ApiToken::query()->whereKey($own->id)->exists())->toBeFalse()
        ->and(AuditEvent::query()->where('action', 'api_token.revoked')->count())->toBe(1);
    Api::get('me', $mine)->assertUnauthorized();
});

test('the API reference page renders the committed document', function (): void {
    $world = DeploymentUi::make(false);

    $this->actingAs($world->member(TeamRole::Viewer))
        ->get('/docs/api')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('docs/api')
            ->where('document.openapi', '3.1.0'));
});
