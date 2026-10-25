<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\ApiToken;
use App\Models\AuditEvent;
use App\Models\CliLoginRequest;
use App\Models\Team;
use Inertia\Testing\AssertableInertia;
use Tests\Support\Api;
use Tests\Support\DeploymentUi;
use Tests\Support\Projects;

beforeEach(fn () => Projects::useTestKek());

/**
 * @return array{deviceCode: string, userCode: string, verificationUrlComplete: string, interval: int}
 */
function startCliLogin(): array
{
    /** @var array{deviceCode: string, userCode: string, verificationUrlComplete: string, interval: int} */
    return Api::call('POST', 'cli/sessions', null, ['clientName' => 'launchroom-cli', 'hostname' => 'laptop', 'version' => '0.1.0'])
        ->assertCreated()
        ->json('data');
}

test('the device flow: pending, approved once, then expired', function (): void {
    $world = DeploymentUi::make(false);
    $owner = $world->member(TeamRole::Owner);
    $session = startCliLogin();

    expect($session['userCode'])->toMatch('/^[B-DF-HJ-NP-TV-XZ]{4}-[B-DF-HJ-NP-TV-XZ]{4}$/')
        ->and($session['verificationUrlComplete'])->toEndWith('/cli/login?code='.$session['userCode'])
        ->and(CliLoginRequest::query()->sole()->device_code_hash)->toBe(hash('sha256', $session['deviceCode']));

    Api::call('POST', 'cli/sessions/token', null, ['deviceCode' => $session['deviceCode']])
        ->assertStatus(428)
        ->assertJsonPath('error.code', 'authorization_pending');

    $this->actingAs($owner)
        ->get('/cli/login?code='.strtolower(str_replace('-', '', $session['userCode'])))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('cli/login')
            ->where('code', $session['userCode'])
            ->where('login.hostname', 'laptop')
            ->where('login.status', 'pending'));

    $this->actingAs($owner)
        ->post("/cli/login/{$session['userCode']}/approve", ['team' => $world->team->public_id, 'abilities' => ['read', 'deploy'], 'expiresInDays' => 30])
        ->assertRedirect();

    $claimed = Api::call('POST', 'cli/sessions/token', null, ['deviceCode' => $session['deviceCode']])
        ->assertOk()
        ->assertJsonPath('data.team.id', $world->team->public_id)
        ->assertJsonPath('data.abilities', ['read', 'deploy'])
        ->json('data');

    expect(CliLoginRequest::query()->sole()->token_ciphertext)->toBeNull()
        ->and(ApiToken::query()->sole()->client->value)->toBe('cli')
        ->and(AuditEvent::query()->where('action', 'api_token.cli_login_approved')->count())->toBe(1);

    Api::get('me', $claimed['token'])->assertOk()->assertJsonPath('data.token.name', 'CLI on laptop');

    Api::call('POST', 'cli/sessions/token', null, ['deviceCode' => $session['deviceCode']])
        ->assertStatus(410)
        ->assertJsonPath('error.code', 'expired_token');
});

test('authorized CLI approval needs no extra password', function (): void {
    $world = DeploymentUi::make(false);
    $session = startCliLogin();

    $this->actingAs($world->member(TeamRole::Owner))
        ->post("/cli/login/{$session['userCode']}/approve", ['team' => $world->team->public_id, 'abilities' => ['read'], 'expiresInDays' => 30])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect(ApiToken::query()->count())->toBe(1);
});

test('a denied sign-in is access_denied', function (): void {
    $world = DeploymentUi::make(false);
    $session = startCliLogin();

    $this->actingAs($world->member(TeamRole::Viewer))
        ->post("/cli/login/{$session['userCode']}/deny")
        ->assertRedirect();

    Api::call('POST', 'cli/sessions/token', null, ['deviceCode' => $session['deviceCode']])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'access_denied');
});

test('expired sign-ins and unknown device codes are expired_token', function (): void {
    $session = startCliLogin();
    CliLoginRequest::query()->update(['expires_at' => now()->subSecond()]);

    Api::call('POST', 'cli/sessions/token', null, ['deviceCode' => $session['deviceCode']])->assertStatus(410);
    Api::call('POST', 'cli/sessions/token', null, ['deviceCode' => str_repeat('A', 43)])->assertStatus(410);
    Api::call('POST', 'cli/sessions/token', null, ['deviceCode' => 'short'])->assertUnprocessable();
});

test('a sign-in can only be approved into a team of your own, with abilities your role has', function (): void {
    $world = DeploymentUi::make(false);
    $viewer = $world->member(TeamRole::Viewer);
    $foreign = Team::factory()->create();
    $session = startCliLogin();

    $this->actingAs($viewer)
        ->post("/cli/login/{$session['userCode']}/approve", ['team' => $foreign->public_id, 'abilities' => ['read'], 'expiresInDays' => 30])
        ->assertSessionHasErrors('team');

    $this->actingAs($viewer)
        ->post("/cli/login/{$session['userCode']}/approve", ['team' => $world->team->public_id, 'abilities' => ['read', 'deploy'], 'expiresInDays' => 30])
        ->assertSessionHasErrors('abilities');

    expect(ApiToken::query()->count())->toBe(0);
});

test('starting a sign-in is rate limited per IP', function (): void {
    config(['api.rate_limits.cli_starts_per_minute' => 1]);

    startCliLogin();
    Api::call('POST', 'cli/sessions', null, [])->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
});

test('the dev approve command refuses outside local', function (): void {
    $session = startCliLogin();

    $this->artisan('launchroom:dev:cli-approve', ['code' => $session['userCode']])->assertFailed();
});
