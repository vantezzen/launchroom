<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Enums\VariableType;
use App\Http\Middleware\SecurityHeaders;
use App\Models\Team;
use App\Models\User;
use App\Support\Logging\RedactSensitiveContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Monolog\Handler\TestHandler;
use Monolog\Processor\PsrLogMessageProcessor;
use Tests\Support\Backups;
use Tests\Support\DeploymentUi;
use Tests\Support\Projects;

/**
 * Web findings of the 2026-10 security review: M3 (CSP/HSTS), L3 (secrets
 * flashed as old input), L4 (reveal as a password oracle), L5 (open
 * redirect after password confirmation) and the Info item on log redaction.
 */
test('every page carries a nonce-based CSP and the security headers (M3)', function (): void {
    $response = $this->get(route('login'))->assertOk();

    $csp = (string) $response->headers->get('Content-Security-Policy');
    preg_match("/'nonce-([A-Za-z0-9+\\/=_-]+)'/", $csp, $nonce);

    expect($nonce)->not->toBeEmpty()
        ->and($csp)->toContain("script-src 'self' 'nonce-{$nonce[1]}' 'strict-dynamic'")
        ->toContain("object-src 'none'")
        ->toContain("base-uri 'self'")
        ->toContain("frame-ancestors 'none'")
        ->toContain('connect-src \'self\' ws://localhost')
        ->not->toContain('unsafe-eval')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin')
        ->and($response->headers->get('Permissions-Policy'))->toContain('camera=()')
        ->and($response->headers->has('Strict-Transport-Security'))->toBeFalse();

    // Every inline script in the page carries this request's nonce.
    preg_match_all('/<script(?![^>]*type="application\/json")[^>]*>/', (string) $response->getContent(), $scripts);
    expect($scripts[0])->not->toBeEmpty();
    foreach ($scripts[0] as $tag) {
        expect($tag)->toContain('nonce="'.$nonce[1].'"');
    }

    // A fresh nonce per request.
    expect($this->get(route('login'))->headers->get('Content-Security-Policy'))->not->toContain($nonce[1]);
});

test('HSTS is sent in production over https only, without preload (M3)', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    $secure = $this->get('https://localhost/login');
    $plain = $this->get('http://localhost/login');

    expect($secure->headers->get('Strict-Transport-Security'))->toBe('max-age=31536000')
        ->and($plain->headers->has('Strict-Transport-Security'))->toBeFalse();
});

test('the CSP allows the configured Reverb origin and nothing from the dev server outside local (M3)', function (): void {
    config(['launchroom.realtime.host' => 'rt.example.com', 'launchroom.realtime.port' => 443, 'launchroom.realtime.scheme' => 'https', 'broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'k']);
    app()->detectEnvironment(fn (): string => 'production');

    $csp = new SecurityHeaders()->policy(Request::create('https://dash.example.com/'), 'n0nce');

    expect($csp)->toContain("connect-src 'self' wss://rt.example.com;")
        ->not->toContain('localhost')
        ->not->toContain('5173');
});

test('secrets are never flashed to the session as old input (L3)', function (): void {
    Projects::useTestKek();
    Backups::fakeEnvironment();
    $team = Team::factory()->create();
    $admin = memberOf($team, TeamRole::Admin);

    // A domain-rule refusal (invalid bucket) …
    $this->actingAs($admin)->withSession(passwordConfirmed())
        ->post(route('backups.destinations.store'), [
            'name' => 'R2', 'endpoint' => 'https://s3.example.com', 'bucket' => 'Invalid_Bucket', 'prefix' => 'p',
            'accessKeyId' => 'AKIA-FLASH-ME-NOT', 'secretAccessKey' => 'super-secret-access-key', 'repositoryPassword' => 'repository-password-123',
        ])
        ->assertSessionHasErrors('bucket');

    $old = session()->get('_old_input', []);
    expect($old)->toHaveKey('name')
        ->not->toHaveKey('secretAccessKey')
        ->not->toHaveKey('accessKeyId')
        ->not->toHaveKey('repositoryPassword');

    // … and a validation error (passphrase confirmation mismatch).
    $owner = User::factory()->instanceOwner()->withTwoFactor()->create();
    $this->actingAs($owner)->withSession(passwordConfirmed())
        ->post(route('backups.recovery.passphrase'), ['passphrase' => 'correct horse battery', 'passphrase_confirmation' => 'typo', 'nested' => ['api_token' => 'x', 'keep' => 'y']])
        ->assertSessionHasErrors('passphrase');

    expect(session()->get('_old_input', []))->toBe(['nested' => ['keep' => 'y']]);
});

test('failed password confirmations share a 5/min budget per user and IP, and lockouts are logged (L4)', function (): void {
    $world = DeploymentUi::make();
    $secret = $world->variable('API_TOKEN', 'tok_super_secret_value', VariableType::Secret);
    $args = [...$world->envArgs(), 'variable' => $secret->public_id];
    $admin = $world->member(TeamRole::Admin);
    Log::spy();

    for ($i = 0; $i < 5; $i++) {
        $this->actingAs($admin)->postJson(route('variables.reveal', $args), ['password' => 'guess-'.$i])->assertUnprocessable();
    }

    // Locked out, even with the right password, here and on the confirm page.
    $this->actingAs($admin)->postJson(route('variables.reveal', $args), ['password' => 'password'])->assertStatus(429);
    $this->actingAs($admin)->from(route('profile.edit'))->post(route('password.confirm.store'), ['password' => 'password'])
        ->assertSessionHasErrors('password');
    expect(session()->has('auth.password_confirmed_at'))->toBeFalse();

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'locked out') && $context['user'] === $admin->id)->atLeast()->once();

    RateLimiter::clear('password-confirmation|user:'.$admin->id);
    RateLimiter::clear('password-confirmation|ip:127.0.0.1');
    $this->actingAs($admin)->postJson(route('variables.reveal', $args), ['password' => 'password'])->assertOk();
});

test('the confirm-password page counts failures too (L4)', function (): void {
    $user = User::factory()->create();

    for ($i = 0; $i < 5; $i++) {
        $this->actingAs($user)->from('/user/confirm-password')->post(route('password.confirm.store'), ['password' => 'nope'])->assertSessionHasErrors('password');
    }

    $this->actingAs($user)->from('/user/confirm-password')->post(route('password.confirm.store'), ['password' => 'password'])->assertSessionHasErrors('password');
    expect(session('errors')->first('password'))->toContain('Too many attempts');
});

test('after confirming the password, users return only to same-origin pages (L5)', function (): void {
    $team = Team::factory()->create();
    $admin = memberOf($team, TeamRole::Admin);

    // A POST from an attacker-controlled Referer.
    $this->actingAs($admin)->withHeader('Referer', 'https://evil.example/phish')
        ->post(route('team.invitations.store'), ['email' => 'x@example.com', 'role' => 'viewer'])
        ->assertRedirect(route('password.confirm'));
    expect(session('url.intended'))->toBe(url('/'));

    // A same-origin form page is kept.
    $this->actingAs($admin)->withHeader('Referer', url('/settings/team'))
        ->post(route('team.invitations.store'), ['email' => 'x@example.com', 'role' => 'viewer'])
        ->assertRedirect(route('password.confirm'));
    expect(session('url.intended'))->toBe(url('/settings/team'));

    // A GET goes back to itself, whatever the Referer says.
    $owner = User::factory()->instanceOwner()->withTwoFactor()->create();
    $this->actingAs($owner)->withHeader('Referer', 'https://evil.example/')
        ->get(route('backups.recovery.kit'))
        ->assertRedirect(route('password.confirm'));
    expect(session('url.intended'))->toBe(route('backups.recovery.kit'));
});

test('log context keys that look like credentials are redacted (Info: log processor)', function (): void {
    config(['logging.channels.redaction-test' => ['driver' => 'monolog', 'handler' => TestHandler::class, 'processors' => [PsrLogMessageProcessor::class], 'tap' => [RedactSensitiveContext::class]]]);
    $logger = Log::channel('redaction-test');
    $handler = $logger->getLogger()->getHandlers()[0];

    $logger->warning('request failed for {host} with {password}', [
        'host' => 'api.example.com',
        'Authorization' => 'Bearer abc',
        'nested' => ['client_secret' => 's3cr3t', 'api_token' => 'tok', 'region' => 'eu'],
        'password' => 'hunter2',
    ]);

    $record = $handler->getRecords()[0];
    // Placeholders are filled after redaction.
    expect($record->message)->toBe('request failed for api.example.com with [redacted]')
        ->and($record->context['Authorization'])->toBe('[redacted]')
        ->and($record->context['password'])->toBe('[redacted]')
        ->and($record->context['nested'])->toBe(['client_secret' => '[redacted]', 'api_token' => '[redacted]', 'region' => 'eu']);
});
