<?php

declare(strict_types=1);

use App\Enums\AuditAction;
use App\Enums\Git\GitAuthMethod;
use App\Enums\Git\GitConnectionStatus;
use App\Enums\Git\GitProviderKind;
use App\Enums\Git\GitServerKind;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\Git\GitConnection;
use App\Models\Git\GitOAuthState;
use App\Models\Git\GitRepository;
use App\Models\Team;
use App\Services\Git\GitConnectionSecrets;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\GitProviderFake;

/*
| Connecting GitLab, Gitea/Forgejo and plain git servers (docs/19 §4):
| OAuth with PKCE, access tokens, the private-network override, plain git
| host keys + deploy keys, authorization.
*/

beforeEach(function (): void {
    GitProviderFake::setUp();
    $this->team = Team::factory()->create();
    $this->admin = memberOf($this->team, TeamRole::Admin);
    $this->admin->forceFill(['current_team_id' => $this->team->id])->save();
});

function pcConnect(object $test, array $input, ?object $user = null): TestResponse
{
    return $test->actingAs($user ?? $test->admin)->post(route('git.connections.store'), $input);
}

test('GitLab OAuth: a pending connection, a single-use state and an S256 code challenge in the authorize URL', function (): void {
    Http::fake([GitProviderFake::GITLAB.'/api/v4/projects*' => Http::response([], 200, ['X-Gitlab-Meta' => '{}'])]);

    $response = pcConnect($this, ['provider' => 'gitlab', 'baseUrl' => GitProviderFake::GITLAB.'/', 'method' => 'oauth', 'clientId' => 'app-id', 'clientSecret' => 'app-secret']);

    $connection = GitConnection::query()->sole();
    $location = (string) $response->headers->get('X-Inertia-Location', (string) $response->headers->get('Location'));
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    $state = GitOAuthState::query()->sole();

    expect($connection)
        ->provider->toBe(GitProviderKind::GitLab)
        ->server_kind->toBe(GitServerKind::GitLab)
        ->status->toBe(GitConnectionStatus::Pending)
        ->base_url->toBe(GitProviderFake::GITLAB)
        ->and(str_starts_with($location, GitProviderFake::GITLAB.'/oauth/authorize?'))->toBeTrue()
        ->and($query)->toMatchArray(['client_id' => 'app-id', 'response_type' => 'code', 'code_challenge_method' => 'S256', 'scope' => 'api', 'redirect_uri' => route('git.oauth.callback')])
        ->and($state->state_hash)->toBe(hash('sha256', $query['state']))
        ->and($query['code_challenge'])->toBe(rtrim(strtr(base64_encode(hash('sha256', $state->code_verifier, true)), '+/', '-_'), '='))
        ->and(resolve(GitConnectionSecrets::class)->get($connection, 'oauth_client_secret'))->toBe('app-secret')
        ->and(json_encode(GitConnection::query()->toBase()->first()))->not->toContain('app-secret');
});

test('the OAuth callback exchanges the code with the verifier and activates the connection', function (): void {
    Http::fake([
        GitProviderFake::GITLAB.'/api/v4/projects*' => Http::response([], 200, ['X-Gitlab-Meta' => '{}']),
        GitProviderFake::GITLAB.'/oauth/token' => Http::response(['access_token' => 'at-1', 'refresh_token' => 'rt-1', 'expires_in' => 7200]),
        GitProviderFake::GITLAB.'/api/v4/user' => Http::response(['id' => 77, 'username' => 'ada']),
    ]);

    $response = pcConnect($this, ['provider' => 'gitlab', 'baseUrl' => GitProviderFake::GITLAB, 'method' => 'oauth', 'clientId' => 'app-id', 'clientSecret' => 'app-secret']);
    parse_str((string) parse_url((string) $response->headers->get('X-Inertia-Location', (string) $response->headers->get('Location')), PHP_URL_QUERY), $query);
    $verifier = GitOAuthState::query()->sole()->code_verifier;

    $this->actingAs($this->admin)->get(route('git.oauth.callback', ['state' => $query['state'], 'code' => 'the-code']))->assertRedirect(route('git.edit'));

    $connection = GitConnection::query()->sole();

    expect($connection->status)->toBe(GitConnectionStatus::Active)
        ->and($connection->account_login)->toBe('ada')
        ->and($connection->account_id)->toBe(77)
        ->and(resolve(GitConnectionSecrets::class)->get($connection, 'access_token'))->toBe('at-1')
        ->and(GitOAuthState::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', AuditAction::GitConnectionAuthorized->value)->exists())->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->url() === GitProviderFake::GITLAB.'/oauth/token'
        && $request['grant_type'] === 'authorization_code' && $request['code'] === 'the-code' && $request['code_verifier'] === $verifier && $request['client_secret'] === 'app-secret');

    // Single use: the same state again does nothing.
    $this->actingAs($this->admin)->get(route('git.oauth.callback', ['state' => $query['state'], 'code' => 'the-code']))->assertSessionHasErrors('git');
});

test('the OAuth state is bound to the user who started it and expires', function (string $case): void {
    Http::fake([GitProviderFake::GITLAB.'/api/v4/projects*' => Http::response([], 200, ['X-Gitlab-Meta' => '{}'])]);
    $response = pcConnect($this, ['provider' => 'gitlab', 'baseUrl' => GitProviderFake::GITLAB, 'method' => 'oauth', 'clientId' => 'app-id', 'clientSecret' => 'app-secret']);
    parse_str((string) parse_url((string) $response->headers->get('X-Inertia-Location', (string) $response->headers->get('Location')), PHP_URL_QUERY), $query);

    $user = $this->admin;

    if ($case === 'other user') {
        $user = memberOf($this->team, TeamRole::Admin);
    } else {
        GitOAuthState::query()->update(['expires_at' => now()->subMinute()]);
    }

    $this->actingAs($user)->get(route('git.oauth.callback', ['state' => $query['state'], 'code' => 'x']))->assertSessionHasErrors('git');
    expect(GitConnection::query()->sole()->status)->toBe(GitConnectionStatus::Pending);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/oauth/token'));
})->with(['other user', 'expired']);

test('a Forgejo access token connects right away; Forgejo is told apart from Gitea by its version', function (): void {
    Http::fake([
        GitProviderFake::GITEA.'/api/v1/version' => Http::response(['version' => '11.0.1+gitea-1.22.0']),
        GitProviderFake::GITEA.'/api/v1/user' => Http::response(['id' => 3, 'login' => 'ada']),
    ]);

    pcConnect($this, ['provider' => 'gitea', 'baseUrl' => GitProviderFake::GITEA, 'method' => 'token', 'token' => 'gitea-token'])->assertRedirect(route('git.edit'));

    expect(GitConnection::query()->sole())
        ->server_kind->toBe(GitServerKind::Forgejo)
        ->auth_method->toBe(GitAuthMethod::Token)
        ->status->toBe(GitConnectionStatus::Active)
        ->name->toBe('git.example.com (ada)');
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/user') && $request->header('Authorization')[0] === 'Bearer gitea-token');
});

test('a token the server refuses is not stored', function (): void {
    Http::fake([
        GitProviderFake::GITEA.'/api/v1/version' => Http::response(['version' => '1.22.0']),
        GitProviderFake::GITEA.'/api/v1/user' => Http::response(['message' => 'token is invalid'], 401),
    ]);

    pcConnect($this, ['provider' => 'gitea', 'baseUrl' => GitProviderFake::GITEA, 'method' => 'token', 'token' => 'bad'])->assertSessionHasErrors('token');
    expect(GitConnection::query()->count())->toBe(0);
});

test('a server on a private network needs an instance owner, and the override is audited', function (): void {
    pcConnect($this, ['provider' => 'gitea', 'baseUrl' => 'http://gitea.internal.example.com:3000', 'method' => 'token', 'token' => 't'])->assertSessionHasErrors('baseUrl');
    pcConnect($this, ['provider' => 'gitea', 'baseUrl' => 'http://gitea.internal.example.com:3000', 'method' => 'token', 'token' => 't', 'allowPrivateNetwork' => true])->assertSessionHasErrors('allowPrivateNetwork');

    $this->admin->forceFill(['is_instance_owner' => true])->save();
    Http::fake([
        'http://gitea.internal.example.com:3000/api/v1/version' => Http::response(['version' => '1.22.0']),
        'http://gitea.internal.example.com:3000/api/v1/user' => Http::response(['id' => 3, 'login' => 'ada']),
    ]);

    pcConnect($this, ['provider' => 'gitea', 'baseUrl' => 'http://gitea.internal.example.com:3000', 'method' => 'token', 'token' => 't', 'allowPrivateNetwork' => true])->assertRedirect(route('git.edit'));

    expect(GitConnection::query()->sole()->allow_private_network)->toBeTrue()
        ->and(AuditEvent::query()->where('action', AuditAction::GitConnectionPrivateNetworkAllowed->value)->exists())->toBeTrue();
});

test('even with the override, loopback, metadata and the control plane stay refused', function (string $url): void {
    $this->admin->forceFill(['is_instance_owner' => true])->save();

    pcConnect($this, ['provider' => 'gitea', 'baseUrl' => $url, 'method' => 'token', 'token' => 't', 'allowPrivateNetwork' => true])->assertSessionHasErrors('baseUrl');
})->with(['http://127.0.0.1:3000', 'http://169.254.169.254', 'http://postgres:5432', 'http://dashboard.localhost']);

test('only owners and admins connect; everyone sees the connections', function (TeamRole $role): void {
    $member = memberOf($this->team, $role);
    $member->forceFill(['current_team_id' => $this->team->id])->save();

    pcConnect($this, ['provider' => 'gitea', 'baseUrl' => GitProviderFake::GITEA, 'method' => 'token', 'token' => 't'], $member)->assertForbidden();
    $this->actingAs($member)->get(route('git.edit'))->assertOk();
})->with([TeamRole::Developer, TeamRole::Viewer]);

test('disconnecting removes the connection, its secrets and repositories (another team’s is a 404)', function (): void {
    $connection = GitProviderFake::connection($this->team, GitProviderKind::Gitea);
    $repository = GitProviderFake::repository($connection, 11, 'acme/api');
    $repository->forceFill(['hook_id' => 5])->save();
    Http::fake([GitProviderFake::GITEA.'/api/v1/repos/acme/api/hooks/5' => Http::response(null, 204)]);

    $foreign = GitProviderFake::connection(Team::factory()->create(), GitProviderKind::Gitea, accountId: 9);
    $this->actingAs($this->admin)->delete(route('git.connections.destroy', $foreign))->assertNotFound();

    $this->actingAs($this->admin)->delete(route('git.connections.destroy', $connection))->assertRedirect(route('git.edit'));

    expect(GitConnection::query()->whereKey($connection->id)->exists())->toBeFalse()
        ->and(GitRepository::query()->whereKey($repository->id)->exists())->toBeFalse();
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/hooks/5'));
});

test('a plain git server: host keys are read, a deploy key generated, nothing trusted until confirmed', function (): void {
    $key = GitProviderFake::hostKey();
    $ssh = GitProviderFake::ssh([$key], ['HEAD' => str_repeat('a', 40), 'refs/heads/main' => str_repeat('a', 40), 'refs/heads/dev' => str_repeat('b', 40)]);

    $this->actingAs($this->admin)->post(route('git.servers.store'), ['url' => 'git@git.example.com:team/app.git'])
        ->assertRedirect();

    $connection = GitConnection::query()->sole();

    expect($connection)
        ->provider->toBe(GitProviderKind::Git)
        ->status->toBe(GitConnectionStatus::Pending)
        ->base_url->toBe('ssh://git@git.example.com/~/team/app.git')
        ->host_keys_confirmed_at->toBeNull()
        ->and($connection->ssh_public_key)->toStartWith('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5')
        ->and(GitRepository::query()->sole()->full_name)->toBe('git.example.com/team/app')
        ->and($ssh->refCalls)->toBe([]);

    $this->get(route('git.edit', ['setup' => $connection->public_id]))->assertOk()
        ->assertInertia(fn ($page) => $page->where('setup', $connection->public_id)
            ->where('connections.0.hostKeys.0.fingerprint', $key->fingerprint())
            ->where('connections.0.deployKey', $connection->ssh_public_key)
            ->missing('connections.0.sshPrivateKey'));

    // A fingerprint the user did not see is refused.
    $this->post(route('git.servers.confirm', $connection), ['fingerprints' => ['SHA256:'.str_repeat('A', 43)]])->assertSessionHasErrors('fingerprints');
    expect($connection->refresh()->host_keys_confirmed_at)->toBeNull();

    $this->post(route('git.servers.confirm', $connection), ['fingerprints' => [$key->fingerprint()]])->assertRedirect(route('git.edit'));

    expect($connection->refresh())
        ->status->toBe(GitConnectionStatus::Active)
        ->host_keys_confirmed_at->not->toBeNull()
        ->and(GitRepository::query()->sole()->default_branch)->toBe('main')
        ->and($ssh->refCalls[0][1][0]->fingerprint())->toBe($key->fingerprint())
        ->and(AuditEvent::query()->where('action', AuditAction::GitHostKeysConfirmed->value)->exists())->toBeTrue();
});

test('a refused deploy key keeps the server pending with the reason', function (): void {
    GitProviderFake::ssh([GitProviderFake::hostKey()], refuse: 'git.example.com refused the deploy key. Add it as a deploy key of the repository.');
    $this->actingAs($this->admin)->post(route('git.servers.store'), ['url' => 'ssh://git@git.example.com:2222/srv/app.git']);
    $connection = GitConnection::query()->sole();

    $this->post(route('git.servers.confirm', $connection), ['fingerprints' => [GitProviderFake::hostKey()->fingerprint()]])->assertSessionHasErrors('access');

    expect($connection->refresh()->status)->toBe(GitConnectionStatus::Pending)
        ->and($connection->status_reason)->toContain('refused the deploy key')
        ->and($connection->base_url)->toBe('ssh://git@git.example.com:2222/srv/app.git');
});

test('a changed host key is never accepted silently', function (): void {
    $connection = GitProviderFake::connection($this->team, GitProviderKind::Git);
    GitProviderFake::ssh([GitProviderFake::hostKey('rotated')]);

    $this->actingAs($this->admin)->post(route('git.servers.recheck', $connection))->assertRedirect(route('git.edit', ['setup' => $connection->public_id]));

    expect($connection->refresh()->host_keys_confirmed_at)->toBeNull()
        ->and($connection->host_keys[0]['fingerprint'])->toBe(GitProviderFake::hostKey('rotated')->fingerprint());
});

test('plain git URLs are checked by grammar', function (string $url): void {
    GitProviderFake::ssh([GitProviderFake::hostKey()]);

    $this->actingAs($this->admin)->post(route('git.servers.store'), ['url' => $url])->assertSessionHasErrors('url');
    expect(GitConnection::query()->count())->toBe(0);
})->with([
    "git@git.example.com:team/app'; rm -rf /.git",
    'ssh://git@git.example.com/../../etc/passwd',
    'http://git.example.com/app.git',
    'file:///srv/app.git',
    'ssh://root:pw@git.example.com/app.git',
]);

test('the generic webhook secret is shown to owners and admins on request, and audited', function (): void {
    $connection = GitProviderFake::connection($this->team, GitProviderKind::Git);
    $developer = memberOf($this->team, TeamRole::Developer);

    $this->actingAs($developer)->postJson(route('git.servers.webhook', $connection))->assertForbidden();

    $this->actingAs($this->admin)->postJson(route('git.servers.webhook', $connection))
        ->assertOk()
        ->assertJson(['url' => route('git.webhooks.git', ['connection' => $connection->public_id]), 'header' => 'X-Launchroom-Signature', 'secret' => GitProviderFake::secret($connection)]);

    expect(AuditEvent::query()->where('action', AuditAction::GitWebhookSecretRevealed->value)->exists())->toBeTrue();
});
