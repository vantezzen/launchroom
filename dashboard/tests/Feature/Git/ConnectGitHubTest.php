<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Http\Controllers\Git\GitSettingsController;
use App\Models\Git\GitHubApp;
use App\Models\Git\GitHubInstallation;
use App\Models\Team;
use App\Support\Git\WebhookReachability;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\Support\GitHubFake;

/*
| The whole "Connect GitHub" path as the browser walks it: start → the page
| posts the manifest to GitHub → GitHub's callback → install → GitHub's setup
| URL. GitHub itself is faked.
*/

beforeEach(function (): void {
    GitHubFake::setUp();
    $this->team = Team::factory()->create();
    $this->admin = memberOf($this->team, TeamRole::Admin);
});

/**
 * The session is stored as JSON (config/session.php), so the next request
 * sees plain arrays, never PHP objects. Tests keep the session in memory;
 * this replays what the real store does between two requests.
 */
function ghJsonRoundTripSession(): void
{
    foreach (session()->all() as $key => $value) {
        session()->put($key, json_decode((string) json_encode($value), true));
    }
}

test('connecting GitHub reaches github.com with the manifest after a real session round trip', function (): void {
    URL::forceRootUrl('https://launchroom.example.com');
    URL::forceScheme('https');
    config(['app.url' => 'https://launchroom.example.com']);

    $this->actingAs($this->admin)
        ->post(route('git.github.manifest.store'))
        ->assertRedirect(route('git.edit'))
        ->assertSessionHasNoErrors();

    // Regression: a Data object in the session came back as an array and the
    // page silently showed nothing (the button just stopped spinning).
    expect(session(GitSettingsController::MANIFEST_FLASH_KEY))->toBeArray();
    ghJsonRoundTripSession();

    $this->get(route('git.edit'))->assertOk()->assertInertia(fn ($page) => $page
        ->component('settings/git')
        ->where('webhookReachable', true)
        ->where('pendingManifest.action', fn (string $action): bool => preg_match('#^https://github\.com/settings/apps/new\?state=lr_ghm_[A-Za-z0-9_-]+$#', $action) === 1)
        ->where('pendingManifest.manifest', function (string $json): bool {
            $manifest = json_decode($json, true);

            return $manifest['redirect_url'] === 'https://launchroom.example.com/settings/git/github/callback'
                && $manifest['setup_url'] === 'https://launchroom.example.com/settings/git/github/setup'
                && $manifest['hook_attributes'] === ['url' => 'https://launchroom.example.com/webhooks/github', 'active' => true]
                && $manifest['default_events'] === ['push', 'pull_request']
                && $manifest['url'] === 'https://launchroom.example.com'
                && $manifest['public'] === false;
        }));

    // The form is shown once; a reload doesn't post it to GitHub again.
    $this->get(route('git.edit'))->assertInertia(fn ($page) => $page->where('pendingManifest', null));
});

test('the callback and the setup URL complete the connection', function (): void {
    URL::forceRootUrl('https://launchroom.example.com');
    URL::forceScheme('https');

    $this->actingAs($this->admin)
        ->post(route('git.github.manifest.store'));
    ghJsonRoundTripSession();
    parse_str((string) parse_url(session(GitSettingsController::MANIFEST_FLASH_KEY)['action'], PHP_URL_QUERY), $query);

    Http::fake([
        'https://api.github.com/app-manifests/code123/conversions' => Http::response([
            'id' => 4242,
            'slug' => 'launchroom-abc',
            'name' => 'launchroom-abc',
            'client_id' => 'Iv1.abc',
            'client_secret' => 'client-secret-plain',
            'webhook_secret' => 'webhook-secret-plain',
            'pem' => GitHubFake::privateKey(),
            'html_url' => 'https://github.com/apps/launchroom-abc',
            'owner' => ['login' => 'octo-org', 'type' => 'Organization'],
        ], 201),
        'https://api.github.com/app/installations/100/access_tokens' => GitHubFake::tokenResponse(),
        'https://api.github.com/app/installations/100' => Http::response(GitHubFake::installationJson(100)),
        'https://api.github.com/installation/repositories*' => Http::response(['total_count' => 0, 'repositories' => []]),
    ]);

    // GitHub → callback (stubbed code), back on Settings → Git with the install link.
    $this->get(route('git.github.manifest.callback', ['code' => 'code123', 'state' => $query['state']]))
        ->assertRedirect(route('git.edit'))
        ->assertSessionHasNoErrors();
    ghJsonRoundTripSession();

    $this->get(route('git.edit'))->assertInertia(fn ($page) => $page
        ->where('apps.0.name', 'launchroom-abc')
        ->where('apps.0.installUrl', 'https://github.com/apps/launchroom-abc/installations/new'));

    // GitHub → setup URL after installing.
    $this->get(route('git.github.setup', ['installation_id' => '100', 'setup_action' => 'install']))
        ->assertRedirect(route('git.edit'))
        ->assertSessionHasNoErrors();

    expect(GitHubApp::query()->sole()->team_id)->toBe($this->team->id)
        ->and(GitHubInstallation::query()->sole()->account_login)->toBe('octo-org');
});

test('a dashboard GitHub cannot reach gets an app without webhooks and a clear note', function (): void {
    URL::forceRootUrl('http://localhost:8000');
    config(['app.url' => 'http://localhost:8000']);

    $this->actingAs($this->admin)->get(route('git.edit'))
        ->assertInertia(fn ($page) => $page->where('webhookReachable', false)->where('webhookUrl', 'http://localhost:8000/webhooks/github'));

    $this->post(route('git.github.manifest.store'))
        ->assertSessionHasNoErrors();
    ghJsonRoundTripSession();

    $manifest = json_decode(session(GitSettingsController::MANIFEST_FLASH_KEY)['manifest'], true);

    // github.com rejects the whole manifest for a localhost hook, even an
    // inactive one, so it carries none; pushes arrive by polling.
    expect($manifest)->not->toHaveKeys(['hook_attributes', 'default_events'])
        ->and($manifest['redirect_url'])->toBe('http://localhost:8000/settings/git/github/callback')
        ->and($manifest['default_permissions'])->toHaveKey('contents');

    $this->get(route('git.edit'))->assertInertia(fn ($page) => $page
        ->where('pendingManifest.action', fn (string $action): bool => str_starts_with($action, 'https://github.com/settings/apps/new?state=')));
});

test('GitHub Enterprise Server keeps the webhook on a private address', function (): void {
    URL::forceRootUrl('http://launchroom.lan');

    $this->actingAs($this->admin)
        ->post(route('git.github.manifest.store'), ['githubUrl' => 'https://ghe.example.com']);

    $manifest = json_decode(session(GitSettingsController::MANIFEST_FLASH_KEY)['manifest'], true);

    expect($manifest['hook_attributes']['url'])->toBe('http://launchroom.lan/webhooks/github');
});

test('onboarding shows the same note and posts the manifest after a session round trip', function (): void {
    URL::forceRootUrl('http://localhost:8000');
    $owner = memberOf($this->team, TeamRole::Owner);
    $owner->forceFill(['is_instance_owner' => true, 'current_team_id' => $this->team->id])->save();

    $this->actingAs($owner)
        ->post(route('git.github.manifest.store'), ['returnTo' => 'onboarding'])
        ->assertRedirect(route('onboarding.show'));
    ghJsonRoundTripSession();

    $this->get(route('onboarding.show'))->assertInertia(fn ($page) => $page
        ->component('onboarding')
        ->where('githubWebhookReachable', false)
        ->where('githubManifest.action', fn (string $action): bool => str_starts_with($action, 'https://github.com/settings/apps/new?state=')));
});

test('webhook reachability is decided by grammar alone', function (string $url, bool $public): void {
    expect(WebhookReachability::isPublic($url))->toBe($public);
})->with([
    'public https' => ['https://launchroom.example.com/webhooks/github', true],
    'documentation ip' => ['http://203.0.113.9/webhooks/github', false],
    'routable ip' => ['http://8.8.8.8/webhooks/github', true],
    'localhost' => ['http://localhost:8000/webhooks/github', false],
    'dot localhost' => ['http://launchroom.localhost/webhooks/github', false],
    'loopback' => ['http://127.0.0.1/webhooks/github', false],
    'private ip' => ['http://192.168.1.10/webhooks/github', false],
    'ipv6 loopback' => ['http://[::1]/webhooks/github', false],
    'single label' => ['http://dashboard/webhooks/github', false],
    'lan suffix' => ['https://launchroom.lan/webhooks/github', false],
    'home.arpa' => ['https://launchroom.home.arpa/webhooks/github', false],
    'not a url' => ['launchroom', false],
    'ftp' => ['ftp://launchroom.example.com/', false],
]);

test('a missing manifest, a foreign value or a viewer never gets a form', function (): void {
    $this->actingAs($this->admin)
        ->withSession([GitSettingsController::MANIFEST_FLASH_KEY => ['action' => 1]])
        ->get(route('git.edit'))
        ->assertInertia(fn ($page) => $page->where('pendingManifest', null));

    $this->actingAs(memberOf($this->team, TeamRole::Viewer))
        ->withSession([GitSettingsController::MANIFEST_FLASH_KEY => ['action' => 'https://github.com/settings/apps/new', 'manifest' => '{}']])
        ->get(route('git.edit'))
        ->assertInertia(fn ($page) => $page->where('pendingManifest', null));
});
