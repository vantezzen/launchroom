<?php

declare(strict_types=1);

use App\Enums\ConnectionStyle;
use App\Enums\DaemonTaskStatus;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\Connection;
use App\Models\DaemonTask;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Service;
use App\Models\Team;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\GitHubFake;
use Tests\Support\Projects;

beforeEach(function (): void {
    GitHubFake::setUp();
    Projects::useTestKek();
    $this->team = Team::factory()->create();
    $this->developer = memberOf($this->team, TeamRole::Developer);
    $this->viewer = memberOf($this->team, TeamRole::Viewer);
    $this->repository = GitHubFake::repository(GitHubFake::installation(GitHubFake::app($this->team)), 555, 'octo-org/shop');

    Http::fake([
        'https://api.github.com/app/installations/100/access_tokens' => GitHubFake::tokenResponse(),
        'https://api.github.com/repos/octo-org/shop/git/trees/main*' => Http::response(['truncated' => false, 'tree' => [
            ['path' => 'package.json', 'type' => 'blob'],
            ['path' => 'apps', 'type' => 'tree'],
            ['path' => 'apps/web/package.json', 'type' => 'blob'],
            ['path' => 'apps/api/composer.json', 'type' => 'blob'],
            ['path' => 'apps/api/.env.example', 'type' => 'blob'],
            ['path' => 'apps/docs/package.json', 'type' => 'blob'],
            ['path' => 'packages/ui/package.json', 'type' => 'blob'],
        ]]),
        'https://api.github.com/repos/octo-org/shop/git/trees/gone*' => Http::response(['message' => 'Not Found'], 404),
        'https://api.github.com/repos/octo-org/shop/contents/apps/api/.env.example*' => Http::response("APP_KEY=\nDB_CONNECTION=pgsql\nDB_PASSWORD=secret-example\nREDIS_HOST=127.0.0.1\n"),
        'https://api.github.com/repos/octo-org/shop/commits/main' => Http::response(['sha' => str_repeat('a', 40), 'commit' => ['message' => 'Ship it']]),
    ]);
});

test('the import page is for people who can create projects', function (): void {
    $this->actingAs($this->viewer)->get(route('projects.import'))->assertForbidden();

    $this->actingAs($this->developer)->get(route('projects.import'))
        ->assertInertia(fn ($page) => $page
            ->component('projects/new')
            ->where('githubConnected', true)
            ->where('buildServerOnline', false)
            ->has('engines', 6));
});

test('inspecting finds the monorepo apps and the databases their .env.example asks for', function (): void {
    $url = fn (string $branch = 'main', ?string $repository = null): string => route('projects.import.inspect', ['repository' => $repository ?? $this->repository->public_id, 'branch' => $branch]);

    $this->actingAs($this->viewer)->getJson($url())->assertForbidden();

    $response = $this->actingAs($this->developer)->getJson($url())->assertOk()
        ->assertJsonPath('complete', true)
        ->assertJsonPath('apps.0.rootDirectory', 'apps/api')
        ->assertJsonPath('apps.0.runtime', 'php')
        ->assertJsonPath('apps.0.envKeys', ['APP_KEY', 'DB_CONNECTION', 'DB_PASSWORD', 'REDIS_HOST'])
        ->assertJsonPath('apps.1.rootDirectory', 'apps/docs')
        ->assertJsonPath('apps.1.selected', false)
        ->assertJsonPath('apps.2.rootDirectory', 'apps/web')
        ->assertJsonPath('databases.0.engine', 'postgres')
        ->assertJsonPath('databases.0.apps', ['apps/api'])
        ->assertJsonPath('databases.1.engine', 'redis');

    // Values never leave the server.
    expect($response->getContent())->not->toContain('secret-example');

    $this->actingAs($this->developer)->getJson($url('gone'))->assertUnprocessable();
    $this->actingAs($this->developer)->getJson($url('bad..branch'))->assertUnprocessable();

    $foreign = GitHubFake::repository(GitHubFake::installation(GitHubFake::app(Team::factory()->create(), 4343), 101), 777, 'evil/repo');
    $this->actingAs($this->developer)->getJson($url('main', $foreign->public_id))->assertNotFound();
});

test('plan previews work before the project exists', function (): void {
    $url = route('projects.import.plan-preview', ['repository' => $this->repository->public_id, 'branch' => 'main', 'rootDirectory' => 'apps/web']);

    $this->actingAs($this->viewer)->getJson($url)->assertForbidden();
    $this->actingAs($this->developer)->getJson($url)->assertOk()->assertJson(['status' => 'unavailable']);

    Projects::server($this->team);
    $this->actingAs($this->developer)->getJson($url)->assertOk()->assertJson(['status' => 'pending']);

    $task = DaemonTask::query()->sole();
    expect($task->spec->repo->sha)->toBe(str_repeat('a', 40))
        ->and($task->spec->rootDir ?? null)->toBe('apps/web');

    // Ready: the plan's worker processes and the databases its code references.
    $task->forceFill(['status' => DaemonTaskStatus::Succeeded, 'result' => ['plan' => ['summary' => [
        'builder' => 'railpack', 'builderReason' => 'railpack', 'framework' => 'express', 'port' => 3000,
        'processes' => [['name' => 'worker', 'args' => ['node', 'worker.js']]],
        'envReferences' => ['DATABASE_URL', 'REDIS_URL', 'STRIPE_KEY'],
    ]]]])->save();
    $this->actingAs($this->developer)->getJson($url)->assertOk()
        ->assertJsonPath('status', 'ready')
        ->assertJsonPath('suggestions.processes', [['name' => 'worker', 'command' => ['node', 'worker.js']]])
        ->assertJsonPath('suggestions.databases.0', ['engine' => 'postgres', 'reason' => 'DATABASE_URL in the code', 'apps' => ['apps/web']])
        ->assertJsonPath('suggestions.databases.1.engine', 'redis');

    // Laravel's config references every driver: no database guesses from code.
    $task->forceFill(['result' => ['plan' => ['summary' => [
        'builder' => 'railpack', 'builderReason' => 'preset', 'framework' => 'laravel',
        'processes' => [['name' => 'queue', 'args' => ['php', 'artisan', 'queue:work', '--tries=3']], ['name' => 'scheduler', 'args' => ['php', 'artisan', 'schedule:work']]],
        'envReferences' => ['DB_HOST', 'DB_DATABASE', 'REDIS_HOST'],
    ]]]])->save();
    $this->actingAs($this->developer)->getJson($url)->assertOk()
        ->assertJsonPath('suggestions.processes.1.name', 'scheduler')
        ->assertJsonPath('suggestions.databases', []);
});

test('importing creates the suggested processes the user kept as workers of their app', function (): void {
    Queue::fake();
    Projects::server($this->team);
    $app = ['name' => 'api', 'rootDirectory' => 'apps/api', 'port' => 8000, 'framework' => 'Laravel'];

    $this->actingAs($this->developer)->post(route('projects.import.store'), [
        'name' => 'Shop', 'repository' => $this->repository->public_id, 'branch' => 'main',
        'apps' => [[...$app, 'processes' => [['name' => 'queue', 'command' => 'php artisan queue:work']]]],
    ])->assertSessionHasErrors('apps.0.processes.0.command');

    $this->actingAs($this->developer)->post(route('projects.import.store'), [
        'name' => 'Shop', 'repository' => $this->repository->public_id, 'branch' => 'main',
        'apps' => [[...$app, 'processes' => [['name' => 'queue', 'command' => ['php', 'artisan', 'queue:work', '--tries=3']], ['name' => 'scheduler', 'command' => ['php', 'artisan', 'schedule:work']]]]],
    ])->assertRedirect();

    $project = Project::query()->sole();
    $api = Service::query()->where('project_id', $project->id)->where('name', 'api')->sole();
    $queue = Service::query()->where('project_id', $project->id)->where('name', 'queue')->sole();

    expect(Service::query()->where('project_id', $project->id)->pluck('kind', 'name')->map->value->all())->toEqual(['api' => 'app', 'queue' => 'worker', 'scheduler' => 'worker'])
        ->and($queue->parent_service_id)->toBe($api->id)
        ->and(AuditEvent::query()->where('action', 'project.imported')->sole()->meta)->toMatchArray(['apps' => 1, 'processes' => 2]);
});

test('a project can be created before variables are configured and deployed', function (): void {
    Queue::fake();
    $this->actingAs($this->developer)->post(route('projects.import.store'), [
        'name' => 'Shop', 'repository' => $this->repository->public_id, 'branch' => 'main',
        'apps' => [['name' => 'web', 'port' => 3000]], 'deploy' => false,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $project = Project::query()->sole();
    expect($project->services()->count())->toBe(1)
        ->and(Deployment::query()->count())->toBe(0)
        ->and($project->production()->sole()->last_applied_revision_id)->toBeNull();
});

test('importing creates the project, services and connections, then deploys right away', function (): void {
    Queue::fake();
    Projects::server($this->team);
    $payload = [
        'name' => 'Shop',
        'repository' => $this->repository->public_id,
        'branch' => 'main',
        'apps' => [
            ['name' => 'api', 'rootDirectory' => 'apps/api', 'port' => 8000, 'framework' => 'Laravel'],
            ['name' => 'web', 'rootDirectory' => 'apps/web', 'port' => 3000, 'framework' => 'Next.js'],
        ],
        'databases' => [
            ['engine' => 'postgres', 'name' => 'postgres', 'connectTo' => ['apps/api']],
            ['engine' => 'redis', 'name' => 'redis', 'connectTo' => ['apps/api', 'apps/web']],
        ],
    ];

    $this->actingAs($this->viewer)->post(route('projects.import.store'), $payload)->assertForbidden();
    $this->actingAs($this->developer)->post(route('projects.import.store'), [...$payload, 'apps' => [$payload['apps'][0], $payload['apps'][0]]])
        ->assertSessionHasErrors('apps');
    expect(Project::query()->count())->toBe(0);

    $response = $this->actingAs($this->developer)->post(route('projects.import.store'), $payload);

    $project = Project::query()->sole();
    $production = $project->production()->sole();
    $response->assertRedirect(route('projects.canvas', [$project, $production]));

    $services = Service::query()->where('project_id', $project->id)->pluck('kind', 'name')->map->value->all();
    $api = Service::query()->where('project_id', $project->id)->where('name', 'api')->sole();

    expect($services)->toEqual(['api' => 'app', 'web' => 'app', 'postgres' => 'database', 'redis' => 'database'])
        ->and($api->source->toArray()['rootDirectory'] ?? null)->toBe('apps/api')
        ->and($production->triggers->branch)->toBe('main')
        ->and(Connection::query()->where('project_id', $project->id)->count())->toBe(3)
        ->and(Connection::query()->where('from_service_id', $api->id)->pluck('style')->unique()->all())->toEqual([ConnectionStyle::Laravel])
        // The first deploy is not staged: a revision and deployments exist.
        ->and($production->refresh()->last_applied_revision_id)->not->toBeNull()
        ->and(Deployment::query()->where('environment_id', $production->id)->count())->toBe(4)
        ->and(AuditEvent::query()->where('action', 'project.imported')->sole()->meta)->toMatchArray(['repository' => 'octo-org/shop', 'apps' => 2]);
});
