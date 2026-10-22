<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Enums\ServiceKind;
use App\Enums\TeamRole;
use App\Enums\VariableType;
use App\Models\AuditEvent;
use App\Models\Connection;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Service;
use App\Models\Team;
use App\Models\Variable;
use App\Models\Volume;
use App\Services\Variables\VariableStore;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
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
    $this->compose = (string) file_get_contents(base_path('tests/Fixtures/compose/node-api.yml'));
    $this->wordpress = (string) file_get_contents(base_path('tests/Fixtures/compose/wordpress.yml'));
});

test('the compose import is for people who can create projects', function (): void {
    $this->actingAs($this->viewer)->get(route('projects.compose'))->assertForbidden();
    $this->actingAs($this->viewer)->postJson(route('projects.compose.preview'), ['compose' => $this->wordpress])->assertForbidden();
    $this->actingAs($this->viewer)->post(route('projects.compose.store'), ['compose' => $this->wordpress, 'name' => 'Blog'])->assertForbidden();

    $this->actingAs($this->developer)->get(route('projects.compose'))->assertInertia(fn ($page) => $page->component('projects/new-compose'));
});

test('the preview shows the canvas and the notes, never a value', function (): void {
    $response = $this->actingAs($this->developer)->postJson(route('projects.compose.preview'), ['compose' => $this->wordpress])
        ->assertOk()
        ->assertJsonPath('error', null)
        ->assertJsonPath('preview.services.0.key', 'wordpress')
        ->assertJsonPath('preview.services.0.group', 'public')
        ->assertJsonPath('preview.services.0.variables.2', ['key' => 'WORDPRESS_DB_PASSWORD', 'secret' => true, 'generated' => false, 'reference' => false])
        ->assertJsonPath('preview.services.1.choice.engine', 'mysql')
        ->assertJsonPath('preview.services.1.choice.managed', false)
        ->assertJsonPath('preview.services.0.notes.0.kind', 'refused')
        ->assertJsonPath('preview.services.0.notes.0.line', 15);

    expect($response->getContent())->not->toContain('examplepass');

    $this->actingAs($this->developer)->postJson(route('projects.compose.preview'), ['compose' => "services: [\n"])
        ->assertOk()
        ->assertJsonPath('preview', null)
        ->assertJsonPath('errorLine', 1);
    $this->actingAs($this->developer)->postJson(route('projects.compose.preview'), [])->assertUnprocessable();
});

test('a Compose project can be configured before deploying', function (): void {
    $this->actingAs($this->developer)->post(route('projects.compose.store'), [
        'compose' => $this->wordpress, 'name' => 'Blog', 'deploy' => false,
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect(Project::query()->sole()->services()->count())->toBeGreaterThan(0)
        ->and(Deployment::query()->count())->toBe(0);
});

test('importing creates the services, keeps secrets encrypted and deploys right away', function (): void {
    Queue::fake();
    Projects::server($this->team);
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message.json_encode($event->context);
    });

    $this->actingAs($this->developer)->post(route('projects.compose.store'), [
        'compose' => $this->wordpress,
        'name' => 'Blog',
        'databases' => ['db' => 'managed'],
    ])->assertRedirect();

    $project = Project::query()->where('name', 'Blog')->sole();
    $services = Service::query()->where('project_id', $project->id)->get()->keyBy('slug');
    $wordpress = $services['wordpress'];
    $store = resolve(VariableStore::class);
    $password = Variable::query()->where('service_id', $wordpress->id)->where('key', 'WORDPRESS_DB_PASSWORD')->sole();

    expect($services->keys()->sort()->values()->all())->toBe(['db', 'wordpress'])
        ->and($services['db']->kind)->toBe(ServiceKind::Database)
        ->and($wordpress->icon)->toBe('wordpress')
        ->and($store->plaintext($project, $password))->toBe('${{db.MYSQL_PASSWORD}}')
        ->and($password->type)->toBe(VariableType::Secret)
        ->and(Volume::query()->whereRelation('serviceInstance', 'service_id', $wordpress->id)->pluck('mount_path')->all())->toBe(['/var/www/html'])
        ->and(Connection::query()->where('from_service_id', $wordpress->id)->where('to_service_id', $services['db']->id)->exists())->toBeTrue()
        ->and(Deployment::query()->where('status', '!=', DeploymentStatus::Cancelled->value)->count())->toBeGreaterThan(0);

    $audit = AuditEvent::query()->where('action', 'project.imported')->sole();
    expect($audit->meta)->toMatchArray(['source' => 'compose', 'services' => ['image', 'database']])
        ->and(json_encode($audit->meta))->not->toContain('exampleuser');

    $databasePassword = Variable::query()->where('service_id', $services['db']->id)->where('key', 'MYSQL_PASSWORD')->sole();
    $generated = $store->plaintext($project, $databasePassword);
    expect($generated)->toMatch('/^[A-Za-z0-9]{32}$/')
        ->and(implode("\n", $logged))->not->toContain($generated);
});

test('generated shared secrets get a value once and are used by reference', function (): void {
    Queue::fake();

    $this->actingAs($this->developer)->post(route('projects.compose.store'), [
        'compose' => "services:\n  app:\n    image: ghcr.io/example-org/app:1\n    ports: ['8080']\n    environment:\n      SESSION_SECRET: \${SESSION_SECRET}\n      COPY: \${SESSION_SECRET}\n",
        'name' => 'App',
    ])->assertRedirect();

    $project = Project::query()->where('name', 'App')->sole();
    $shared = Variable::query()->where('project_id', $project->id)->whereNull('service_id')->sole();
    $store = resolve(VariableStore::class);

    expect($shared->key)->toBe('SESSION_SECRET')
        ->and($shared->type)->toBe(VariableType::Secret)
        ->and($store->plaintext($project, $shared))->toMatch('/^[A-Za-z0-9]{32}$/')
        ->and(Variable::query()->where('project_id', $project->id)->whereNotNull('service_id')->get()->map(fn (Variable $v): string => $store->plaintext($project, $v))->unique()->values()->all())
        ->toBe(['${{shared.SESSION_SECRET}}']);
});

test('a file that can’t be imported is an error on the form', function (): void {
    $this->actingAs($this->developer)->post(route('projects.compose.store'), ['compose' => "services:\n  a:\n    image: quay.io/x/y:1\n", 'name' => 'X'])
        ->assertSessionHasErrors('compose');
    $this->actingAs($this->developer)->post(route('projects.compose.store'), ['compose' => $this->wordpress])
        ->assertSessionHasErrors('name');

    expect(Project::query()->count())->toBe(0);
});

test('a repository import reads compose.yaml from the branch and builds its services', function (): void {
    Queue::fake();
    $repository = GitHubFake::repository(GitHubFake::installation(GitHubFake::app($this->team)), 555, 'octo-org/shop');
    Http::fake([
        'https://api.github.com/app/installations/100/access_tokens' => GitHubFake::tokenResponse(),
        'https://api.github.com/repos/octo-org/shop/contents/compose.yaml*' => Http::response($this->compose),
        'https://api.github.com/repos/octo-org/shop/*' => Http::response(['message' => 'Not Found'], 404),
    ]);

    $this->actingAs($this->developer)->postJson(route('projects.compose.preview'), ['source' => 'repository', 'repository' => $repository->public_id, 'branch' => 'main'])
        ->assertOk()
        ->assertJsonPath('path', 'compose.yaml')
        ->assertJsonPath('preview.services.0.kind', 'app')
        ->assertJsonPath('preview.services.1.subtitle', 'Worker of api');

    $this->actingAs($this->developer)->post(route('projects.compose.store'), ['source' => 'repository', 'repository' => $repository->public_id, 'branch' => 'main', 'name' => 'Shop'])
        ->assertRedirect();

    $project = Project::query()->where('name', 'Shop')->sole();
    $kinds = Service::query()->where('project_id', $project->id)->get()->mapWithKeys(fn (Service $s): array => [$s->slug => $s->kind->value])->all();

    expect($kinds)->toEqual(['api' => 'app', 'queue' => 'worker', 'db' => 'database', 'cache' => 'database'])
        ->and($project->production()->sole()->triggers->branch)->toBe('main');

    $foreign = GitHubFake::repository(GitHubFake::installation(GitHubFake::app(Team::factory()->create(), 4343), 101), 777, 'evil/repo');
    $this->actingAs($this->developer)->postJson(route('projects.compose.preview'), ['source' => 'repository', 'repository' => $foreign->public_id, 'branch' => 'main'])
        ->assertOk()
        ->assertJsonPath('preview', null);
});
