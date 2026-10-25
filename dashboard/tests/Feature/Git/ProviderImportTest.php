<?php

declare(strict_types=1);

use App\Actions\Git\EnsureRepositoryWebhook;
use App\Enums\Git\GitProviderKind;
use App\Enums\TeamRole;
use App\Jobs\Git\EnsureRepositoryWebhookJob;
use App\Models\Git\GitRepository;
use App\Models\Project;
use App\Models\Service;
use App\Models\Team;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\Support\GitHubFake;
use Tests\Support\GitProviderFake;
use Tests\Support\Projects;

/*
| The import flow across providers (docs/19 §2, §5): one repository list with
| a provider filter, the inspector through the provider's file API, and the
| webhook registered when a service starts using a GitLab/Gitea repository.
*/

beforeEach(function (): void {
    GitProviderFake::setUp();
    Projects::useTestKek();
    config(['app.url' => 'https://launchroom.example.com']);
    URL::forceRootUrl('https://launchroom.example.com');
    $this->team = Team::factory()->create();
    $this->developer = memberOf($this->team, TeamRole::Developer);
    $this->developer->forceFill(['current_team_id' => $this->team->id])->save();
    GitHubFake::repository(GitHubFake::installation(GitHubFake::app($this->team)), 555, 'octo-org/shop');
    $this->gitlab = GitProviderFake::connection($this->team, GitProviderKind::GitLab);

    Http::fake([
        GitProviderFake::GITLAB.'/api/v4/projects?*' => Http::response([
            ['id' => 42, 'path_with_namespace' => 'acme/platform/web', 'default_branch' => 'main', 'visibility' => 'private', 'archived' => false, 'last_activity_at' => now()->toIso8601String()],
            ['id' => 43, 'path_with_namespace' => 'not a/valid path', 'default_branch' => 'main'],
        ]),
        GitProviderFake::GITLAB.'/api/v4/projects/42/repository/tree*' => Http::response([
            ['path' => 'package.json', 'type' => 'blob'],
            ['path' => '.env.example', 'type' => 'blob'],
        ]),
        GitProviderFake::GITLAB.'/api/v4/projects/42/repository/files/.env.example/raw*' => Http::response("DATABASE_URL=postgres://localhost/app\n"),
        GitProviderFake::GITLAB.'/api/v4/projects/42/repository/branches?*' => Http::response([['name' => 'main', 'commit' => ['id' => str_repeat('a', 40)], 'protected' => true, 'default' => true]]),
        GitProviderFake::GITLAB.'/api/v4/projects/42/hooks' => Http::response(['id' => 900], 201),
    ]);
});

test('the repository list merges GitHub and GitLab, with a provider filter', function (): void {
    $all = $this->actingAs($this->developer)->getJson(route('git.repositories.index'))->assertOk()->json('repositories');

    expect(array_column($all, 'fullName'))->toContain('octo-org/shop', 'acme/platform/web')
        ->and(array_column($all, 'fullName'))->not->toContain('not a/valid path');

    $gitlab = $this->getJson(route('git.repositories.index', ['provider' => 'gitlab']))->json('repositories');

    expect($gitlab)->toHaveCount(1)
        ->and($gitlab[0])->toMatchArray(['provider' => 'gitlab', 'serverKind' => 'gitlab', 'fullName' => 'acme/platform/web', 'githubRepoId' => 42])
        ->and($gitlab[0]['id'])->toStartWith('gir_');

    $this->getJson(route('git.repositories.branches', $gitlab[0]['id']))->assertOk()->assertJsonPath('branches.0.name', 'main')->assertJsonPath('branches.0.isDefault', true);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'min_access_level=30') && str_contains($request->url(), 'membership=true'));
});

test('another team’s GitLab repositories are never listed or reachable', function (): void {
    $other = Team::factory()->create();
    $foreign = GitProviderFake::repository(GitProviderFake::connection($other, GitProviderKind::GitLab, accountId: 8), 77, 'them/secret');

    $listed = $this->actingAs($this->developer)->getJson(route('git.repositories.index', ['provider' => 'gitlab']))->json('repositories');

    expect(array_column($listed, 'fullName'))->not->toContain('them/secret');
    $this->getJson(route('git.repositories.branches', $foreign->public_id))->assertNotFound();
    $this->getJson(route('projects.import.inspect', ['repository' => $foreign->public_id, 'branch' => 'main']))->assertNotFound();
});

test('inspecting a GitLab repository reads its tree and .env.example through the API', function (): void {
    $repository = GitProviderFake::repository($this->gitlab, 42, 'acme/platform/web');

    $this->actingAs($this->developer)->getJson(route('projects.import.inspect', ['repository' => $repository->public_id, 'branch' => 'main']))
        ->assertOk()
        ->assertJsonPath('apps.0.envKeys', ['DATABASE_URL'])
        ->assertJsonPath('databases.0.engine', 'postgres');
});

test('importing a GitLab repository creates a gitlab-sourced service and registers its webhook', function (): void {
    $repository = GitProviderFake::repository($this->gitlab, 42, 'acme/platform/web');

    $this->actingAs($this->developer)->post(route('projects.import.store'), [
        'name' => 'Platform',
        'repository' => $repository->public_id,
        'branch' => 'main',
        'apps' => [['rootDirectory' => '', 'name' => 'web', 'port' => 3000, 'processes' => []]],
        'databases' => [],
    ])->assertRedirect();

    $service = Service::query()->where('project_id', Project::query()->sole()->id)->sole();

    expect($service->source->toArray())->toMatchArray(['provider' => 'gitlab', 'installationId' => $this->gitlab->id, 'repositoryId' => 42, 'fullName' => 'acme/platform/web'])
        ->and(GitRepository::query()->whereKey($repository->id)->value('hook_error'))->toBeNull()
        ->and(GitRepository::query()->whereKey($repository->id)->value('hook_id'))->toBe(900);

    Http::assertSent(fn (Request $request): bool => $request->url() === GitProviderFake::GITLAB.'/api/v4/projects/42/hooks'
        && $request['url'] === route('git.webhooks.gitlab', ['connection' => $this->gitlab->public_id])
        && $request['token'] === GitProviderFake::secret($this->gitlab)
        && $request['merge_requests_events'] === true);
});

test('the webhook is not registered on gitlab.com-style public servers when the dashboard is not reachable', function (): void {
    config(['app.url' => 'http://localhost:8000']);
    URL::forceRootUrl('http://localhost:8000');
    $repository = GitProviderFake::repository($this->gitlab, 42, 'acme/platform/web');

    new EnsureRepositoryWebhookJob($repository->id, $this->team->id)->handle(resolve(EnsureRepositoryWebhook::class));

    expect($repository->refresh()->hook_id)->toBeNull()->and($repository->hook_error)->toContain('polls the repository');
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/hooks'));
});
