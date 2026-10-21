<?php

declare(strict_types=1);

use App\Enums\AuditAction;
use App\Enums\TeamRole;
use App\Http\Controllers\Git\GitSettingsController;
use App\Models\AuditEvent;
use App\Models\Git\GitHubApp;
use App\Models\Team;
use App\Services\Crypto\Exceptions\DecryptionFailed;
use App\Services\GitHub\GitHubAppSecrets;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\Support\GitHubFake;

beforeEach(function (): void {
    // A public address: github.com only accepts webhooks it can reach.
    URL::forceRootUrl('https://launchroom.example.com');
    URL::forceScheme('https');
    GitHubFake::setUp();
    $this->team = Team::factory()->create();
    $this->admin = memberOf($this->team, TeamRole::Admin);
});

/**
 * Starts the flow and returns [state, manifest, action URL].
 *
 * @return array{0: string, 1: array<string, mixed>, 2: string}
 */
function ghStartManifest(object $test, array $input = []): array
{
    $test->actingAs($test->admin)->withSession(passwordConfirmed())
        ->post(route('git.github.manifest.store'), $input)
        ->assertRedirect(route('git.edit'));

    $redirect = session(GitSettingsController::MANIFEST_FLASH_KEY);
    parse_str((string) parse_url($redirect['action'], PHP_URL_QUERY), $query);

    return [$query['state'], json_decode($redirect['manifest'], true), $redirect['action']];
}

function ghConversionResponse(int $appId = 4242): array
{
    return [
        'id' => $appId,
        'slug' => 'launchroom-abc',
        'name' => 'launchroom-abc',
        'client_id' => 'Iv1.abc',
        'client_secret' => 'client-secret-plain',
        'webhook_secret' => 'webhook-secret-plain',
        'pem' => GitHubFake::privateKey(),
        'html_url' => 'https://github.com/apps/launchroom-abc',
        'owner' => ['login' => 'octo-org', 'type' => 'Organization'],
    ];
}

test('the manifest asks for the minimal permissions and our callback URLs', function (): void {
    [$state, $manifest, $action] = ghStartManifest($this);

    expect($action)->toStartWith('https://github.com/settings/apps/new?state=lr_ghm_')
        ->and($manifest['default_permissions'])->toBe([
            'contents' => 'read',
            'metadata' => 'read',
            'pull_requests' => 'write',
            'checks' => 'write',
            'deployments' => 'write',
            'statuses' => 'write',
        ])
        ->and($manifest['default_events'])->toBe(['push', 'pull_request'])
        ->and($manifest['hook_attributes']['url'])->toBe(route('git.github.webhook'))
        ->and($manifest['redirect_url'])->toBe(route('git.github.manifest.callback'))
        ->and($manifest['setup_url'])->toBe(route('git.github.setup'))
        ->and($manifest['public'])->toBeFalse()
        ->and(strlen($manifest['name']))->toBeLessThanOrEqual(34);

    // The page posts it to GitHub on the next view.
    $this->get(route('git.edit'))->assertInertia(fn ($page) => $page
        ->where('pendingManifest.action', $action));
});

test('organization apps are created under the organization', function (): void {
    [, , $action] = ghStartManifest($this, ['organization' => 'octo-org']);

    expect($action)->toStartWith('https://github.com/organizations/octo-org/settings/apps/new?state=');
});

test('GitHub Enterprise URLs are validated before anything is sent there', function (): void {
    [, , $action] = ghStartManifest($this, ['githubUrl' => 'https://ghe.example.com']);
    expect($action)->toStartWith('https://ghe.example.com/settings/apps/new');

    $this->actingAs($this->admin)->withSession(passwordConfirmed())
        ->post(route('git.github.manifest.store'), ['githubUrl' => 'https://ghe.internal.example.com'])
        ->assertSessionHasErrors('githubUrl');

    $this->actingAs($this->admin)->withSession(passwordConfirmed())
        ->post(route('git.github.manifest.store'), ['githubUrl' => 'http://ghe.example.com'])
        ->assertSessionHasErrors('githubUrl');

    $this->actingAs($this->admin)->withSession(passwordConfirmed())
        ->post(route('git.github.manifest.store'), ['organization' => 'bad org;id'])
        ->assertSessionHasErrors('organization');
});

test('starting the flow needs a confirmed password and the servers permission', function (): void {
    $this->actingAs($this->admin)->post(route('git.github.manifest.store'))
        ->assertRedirect(route('password.confirm'));

    foreach ([TeamRole::Developer, TeamRole::Viewer] as $role) {
        $this->actingAs(memberOf($this->team, $role))->withSession(passwordConfirmed())
            ->post(route('git.github.manifest.store'))
            ->assertForbidden();
    }
});

test('the callback converts the code and stores every secret envelope-encrypted', function (): void {
    Http::fake(['https://api.github.com/app-manifests/abc123/conversions' => Http::response(ghConversionResponse(), 201)]);

    [$state] = ghStartManifest($this);

    $this->actingAs($this->admin)
        ->get(route('git.github.manifest.callback', ['code' => 'abc123', 'state' => $state]))
        ->assertRedirect(route('git.edit'))
        ->assertSessionHasNoErrors();

    $app = GitHubApp::query()->sole();
    $secrets = resolve(GitHubAppSecrets::class);

    expect($app->team_id)->toBe($this->team->id)
        ->and($app->app_id)->toBe(4242)
        ->and($app->api_url)->toBe('https://api.github.com')
        ->and($app->client_secret)->toStartWith('lr1:')->not->toContain('client-secret-plain')
        ->and($app->webhook_secret)->toStartWith('lr1:')
        ->and($app->private_key)->toStartWith('lr1:')->not->toContain('PRIVATE KEY')
        ->and($secrets->clientSecret($app))->toBe('client-secret-plain')
        ->and($secrets->webhookSecret($app))->toBe('webhook-secret-plain')
        ->and($secrets->privateKey($app))->toBe(GitHubFake::privateKey())
        ->and(json_encode($app->toArray()))->not->toContain('lr1:')
        ->and($app->toArray())->not->toHaveKeys(['client_secret', 'webhook_secret', 'private_key', 'wrapped_dek'])
        ->and(AuditEvent::query()->where('action', AuditAction::GitHubAppCreated->value)->count())->toBe(1);

    // Body-less POST, no credentials (the code is the credential).
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && ! $request->hasHeader('Authorization'));

    // The settings page never exposes secrets.
    $this->get(route('git.edit'))->assertInertia(fn ($page) => $page
        ->where('apps.0.name', 'launchroom-abc')
        ->where('apps.0.installUrl', 'https://github.com/apps/launchroom-abc/installations/new')
        ->where('apps.0.webhook.health', 'never')
        ->missing('apps.0.privateKey')
        ->missing('apps.0.webhookSecret'));
});

test('ciphertexts are bound to their app and field', function (): void {
    $a = GitHubFake::app($this->team, 1);
    $b = GitHubFake::app($this->team, 2);

    // Swapping a ciphertext into another field or app fails to decrypt.
    $a->private_key = $a->client_secret;
    expect(fn () => resolve(GitHubAppSecrets::class)->privateKey($a))->toThrow(DecryptionFailed::class);

    $b->wrapped_dek = GitHubApp::query()->whereKey($a->id)->value('wrapped_dek');
    expect(fn () => resolve(GitHubAppSecrets::class)->clientSecret($b))->toThrow(DecryptionFailed::class);
});

test('the state is single use and bound to the user who started the flow', function (): void {
    Http::fake(['https://api.github.com/app-manifests/*' => Http::response(ghConversionResponse(), 201)]);

    [$state] = ghStartManifest($this);

    // Another admin of the same team can't redeem it.
    $this->actingAs(memberOf($this->team, TeamRole::Admin))
        ->get(route('git.github.manifest.callback', ['code' => 'abc123', 'state' => $state]))
        ->assertSessionHasErrors('github');

    // Redeeming failed above, so the state is gone even for its owner.
    $this->actingAs($this->admin)
        ->get(route('git.github.manifest.callback', ['code' => 'abc123', 'state' => $state]))
        ->assertSessionHasErrors('github');

    [$state] = ghStartManifest($this);
    $this->get(route('git.github.manifest.callback', ['code' => 'abc123', 'state' => $state]))->assertSessionHasNoErrors();
    $this->get(route('git.github.manifest.callback', ['code' => 'abc123', 'state' => $state]))->assertSessionHasErrors('github');

    expect(GitHubApp::query()->count())->toBe(1);
    Http::assertSentCount(1);
});

test('forged or malformed callbacks never reach GitHub', function (array $query): void {
    Http::fake();

    $this->actingAs($this->admin)->get(route('git.github.manifest.callback', $query))
        ->assertRedirect(route('git.edit'))
        ->assertSessionHasErrors('github');

    Http::assertNothingSent();
})->with([
    'no state' => [['code' => 'abc']],
    'unknown state' => [['code' => 'abc', 'state' => 'lr_ghm_'.str_repeat('a', 43)]],
    'junk state' => [['code' => 'abc', 'state' => '../../x']],
]);

test('a rejected code is reported without storing anything', function (): void {
    Http::fake(['https://api.github.com/*' => Http::response(['message' => 'Not Found'], 404)]);
    [$state] = ghStartManifest($this);

    $this->get(route('git.github.manifest.callback', ['code' => 'abc123', 'state' => $state]))
        ->assertSessionHasErrors('github');

    expect(GitHubApp::query()->count())->toBe(0);
});

test('a callback with a valid code but a demoted user is refused', function (): void {
    Http::fake();
    [$state] = ghStartManifest($this);

    $this->admin->memberships()->update(['role' => TeamRole::Viewer->value]);
    $this->admin->forgetTeamRoles();

    $this->get(route('git.github.manifest.callback', ['code' => 'abc123', 'state' => $state]))->assertForbidden();
    Http::assertNothingSent();
});

test('a GHE server cannot choose the app links we render (L10)', function (): void {
    Http::fake(['https://ghe.example.com/api/v3/app-manifests/abc123/conversions' => Http::response([...ghConversionResponse(), 'html_url' => 'https://evil.example/apps/launchroom-abc'], 201)]);

    [$state] = ghStartManifest($this, ['githubUrl' => 'https://ghe.example.com']);

    $this->actingAs($this->admin)
        ->get(route('git.github.manifest.callback', ['code' => 'abc123', 'state' => $state]))
        ->assertRedirect(route('git.edit'));

    $app = GitHubApp::query()->sole();
    expect($app->html_url)->toBe('https://ghe.example.com/apps/launchroom-abc')
        ->and($app->installUrl())->toBe('https://ghe.example.com/apps/launchroom-abc/installations/new');
});

test('stored app links are re-checked before rendering (L10)', function (string $htmlUrl, string $expected): void {
    expect(GitHubApp::safeHtmlUrl($htmlUrl, 'https://ghe.example.com', 'my-app'))->toBe($expected);
})->with([
    'same host' => ['https://ghe.example.com/apps/my-app', 'https://ghe.example.com/apps/my-app'],
    'other host' => ['https://evil.example/apps/my-app', 'https://ghe.example.com/apps/my-app'],
    'plain http' => ['http://ghe.example.com/apps/my-app', 'https://ghe.example.com/apps/my-app'],
    'javascript' => ['javascript:alert(1)', 'https://ghe.example.com/apps/my-app'],
    'credentials' => ['https://user:pw@ghe.example.com/apps/my-app', 'https://ghe.example.com/apps/my-app'],
    'other port' => ['https://ghe.example.com:8443/apps/my-app', 'https://ghe.example.com/apps/my-app'],
    'lookalike host' => ['https://ghe.example.com.evil.example/apps/my-app', 'https://ghe.example.com/apps/my-app'],
]);
