<?php

declare(strict_types=1);

use App\Enums\AuditAction;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\Git\GitHubApp;
use App\Models\Git\GitHubInstallation;
use App\Models\Git\WebhookDelivery;
use App\Models\Team;
use Illuminate\Support\Facades\Http;
use Tests\Support\GitHubFake;

beforeEach(function (): void {
    GitHubFake::setUp();
    $this->team = Team::factory()->create();
    $this->admin = memberOf($this->team, TeamRole::Admin);
    $this->viewer = memberOf($this->team, TeamRole::Viewer);
    $this->developer = memberOf($this->team, TeamRole::Developer);
    $this->githubApp = GitHubFake::app($this->team);
    $this->installation = GitHubFake::installation($this->githubApp);
    GitHubFake::repository($this->installation, 555, 'octo-org/web');
    GitHubFake::repository($this->installation, 556, 'octo-org/api');
});

test('the Git page shows apps, installations and webhook health without secrets', function (): void {
    WebhookDelivery::query()->insert(['delivery_id' => 'd-1', 'github_app_id' => $this->githubApp->id, 'team_id' => $this->team->id, 'event' => 'push', 'status' => 'processed', 'received_at' => now()]);
    $this->githubApp->forceFill(['webhook_last_received_at' => now()])->save();

    // Another team's app never shows up.
    GitHubFake::app(Team::factory()->create(), 9999);

    $response = $this->actingAs($this->viewer)->get(route('git.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('settings/git')
            ->has('apps', 1)
            ->where('apps.0.id', $this->githubApp->public_id)
            ->where('apps.0.canManage', false)
            ->where('apps.0.webhook.health', 'healthy')
            ->where('apps.0.webhook.lastEvent', 'push')
            ->where('apps.0.webhook.lastStatus', 'processed')
            ->where('apps.0.installations.0.accountLogin', 'octo-org')
            ->where('apps.0.installations.0.repositoryCount', 2)
            ->where('canManage', false)
            ->where('pendingManifest', null));

    expect($response->getContent())->not->toContain('lr1:')
        ->not->toContain('PRIVATE KEY');
});

test('webhook health stays healthy when quiet and turns stale after a missed push', function (): void {
    $this->githubApp->forceFill(['webhook_last_received_at' => now()->subDay()])->save();

    $this->actingAs($this->admin)->get(route('git.edit'))
        ->assertInertia(fn ($page) => $page->where('apps.0.webhook.health', 'healthy')->where('apps.0.webhook.missedAt', null));

    $this->githubApp->forceFill(['webhook_missed_at' => now()->subMinute()])->save();

    $this->actingAs($this->admin)->get(route('git.edit'))
        ->assertInertia(fn ($page) => $page->where('apps.0.webhook.health', 'stale')->whereType('apps.0.webhook.missedAt', 'string')->where('canManage', true));
});

test('disconnecting needs the servers permission', function (): void {
    $this->actingAs($this->developer)
        ->delete(route('git.github.destroy', $this->githubApp->public_id))
        ->assertForbidden();

    $this->actingAs($this->admin)
        ->delete(route('git.github.destroy', $this->githubApp->public_id))
        ->assertRedirect(route('git.edit'));

    expect(GitHubApp::query()->count())->toBe(0)
        ->and(GitHubInstallation::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', AuditAction::GitHubAppDisconnected->value)->count())->toBe(1);
});

test('apps of other teams are not found', function (): void {
    $foreign = GitHubFake::app(Team::factory()->create(), 9999);

    $this->actingAs($this->admin)
        ->delete(route('git.github.destroy', $foreign->public_id))
        ->assertNotFound();

    $this->actingAs($this->admin)
        ->delete('/settings/git/github/gha_not-valid')
        ->assertNotFound();
});

test('the repo picker searches the team’s cached repositories', function (): void {
    $foreign = GitHubFake::installation(GitHubFake::app(Team::factory()->create(), 9999), 300);
    GitHubFake::repository($foreign, 777, 'octo-org/web-secret');

    $this->actingAs($this->developer)->getJson(route('git.repositories.index', ['search' => 'web']))
        ->assertOk()
        ->assertJsonCount(1, 'repositories')
        ->assertJsonPath('repositories.0.fullName', 'octo-org/web')
        ->assertJsonPath('repositories.0.githubRepoId', 555)
        ->assertJsonPath('repositories.0.installationId', $this->installation->public_id);

    // LIKE wildcards are literal.
    $this->actingAs($this->developer)->getJson(route('git.repositories.index', ['search' => '%']))
        ->assertJsonCount(0, 'repositories');

    $this->actingAs($this->viewer)->getJson(route('git.repositories.index'))->assertForbidden();
});

test('branches come from GitHub with a read-only token for that repository', function (): void {
    Http::fake([
        'https://api.github.com/app/installations/100/access_tokens' => GitHubFake::tokenResponse(),
        'https://api.github.com/repos/octo-org/web/branches*' => Http::response([
            ['name' => 'main', 'commit' => ['sha' => str_repeat('a', 40)], 'protected' => true],
            ['name' => 'feature/x', 'commit' => ['sha' => str_repeat('b', 40)], 'protected' => false],
        ]),
    ]);

    $repository = $this->installation->repositories()->where('github_repo_id', 555)->sole();

    $this->actingAs($this->developer)->getJson(route('git.repositories.branches', $repository->public_id))
        ->assertOk()
        ->assertJsonPath('branches.0.name', 'main')
        ->assertJsonPath('branches.0.isDefault', true)
        ->assertJsonPath('branches.1.isDefault', false);

    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/access_tokens')
        && $request->data()['repository_ids'] === [555]);
});

test('branch lookups for other teams’ repositories are not found', function (): void {
    $foreign = GitHubFake::repository(GitHubFake::installation(GitHubFake::app(Team::factory()->create(), 9999), 300), 777, 'x/y');

    $this->actingAs($this->admin)->getJson(route('git.repositories.branches', $foreign->public_id))->assertNotFound();
});
