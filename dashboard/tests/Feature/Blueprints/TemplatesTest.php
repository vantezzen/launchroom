<?php

declare(strict_types=1);

use App\Enums\DomainKind;
use App\Enums\ServiceKind;
use App\Enums\TeamRole;
use App\Enums\VariableType;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\Domain;
use App\Models\Project;
use App\Models\Service;
use App\Models\Team;
use App\Models\Variable;
use App\Services\Variables\VariableStore;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Support\GitHubFake;
use Tests\Support\Projects;

beforeEach(function (): void {
    GitHubFake::setUp();
    Projects::useTestKek();
    Queue::fake();
    $this->team = Team::factory()->create();
    $this->developer = memberOf($this->team, TeamRole::Developer);
    $this->viewer = memberOf($this->team, TeamRole::Viewer);
});

test('the gallery lists the catalog for people who can create projects', function (): void {
    $this->actingAs($this->viewer)->get(route('projects.templates'))->assertForbidden();

    $this->actingAs($this->developer)->get(route('projects.templates'))
        ->assertInertia(fn ($page) => $page
            ->component('projects/templates')
            ->has('templates', 14)
            ->where('categories.stacks', 'Stacks')
            ->where('templates.0.id', 'django-postgres-celery')
            ->where('templates.0.needsRepository', true));
});

test('a one-click app creates its services with generated secrets and deploys', function (): void {
    Projects::server($this->team);
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message.json_encode($event->context);
    });

    $this->actingAs($this->viewer)->post(route('projects.templates.store', 'umami'), ['name' => 'Stats'])->assertForbidden();

    $response = $this->actingAs($this->developer)->post(route('projects.templates.store', 'umami'), [
        'name' => 'Stats',
        'inputs' => ['domain' => 'stats.example.com'],
    ])->assertRedirect();

    $project = Project::query()->where('name', 'Stats')->sole();
    $services = Service::query()->where('project_id', $project->id)->get()->keyBy('slug');
    $store = resolve(VariableStore::class);
    $secret = Variable::query()->where('service_id', $services['umami']->id)->where('key', 'APP_SECRET')->sole();
    $databaseUrl = Variable::query()->where('service_id', $services['umami']->id)->where('key', 'DATABASE_URL')->sole();
    $value = $store->plaintext($project, $secret);

    expect($services->keys()->sort()->values()->all())->toBe(['postgres', 'umami'])
        ->and($services['umami']->icon)->toBe('umami')
        ->and($services['postgres']->kind)->toBe(ServiceKind::Database)
        ->and($secret->type)->toBe(VariableType::Secret)
        ->and($value)->toMatch('/^[A-Za-z0-9]{48}$/')
        // The template's `db` key became the service's slug.
        ->and($store->plaintext($project, $databaseUrl))->toBe('${{postgres.DATABASE_URL}}')
        ->and(Domain::query()->where('kind', DomainKind::Custom->value)->sole()->hostname)->toBe('stats.example.com')
        ->and(Deployment::query()->count())->toBeGreaterThan(0)
        ->and(AuditEvent::query()->where('action', 'project.imported')->sole()->meta)->toMatchArray(['source' => 'template', 'template' => 'umami']);

    // Generated values never leave the server: not in logs, audit entries or the response.
    expect(implode("\n", $logged))->not->toContain($value)
        ->and(AuditEvent::query()->get()->map(fn (AuditEvent $event): string => (string) json_encode($event->meta))->implode("\n"))->not->toContain($value)
        ->and((string) $response->getContent())->not->toContain($value);

    // A second project from the same template gets fresh secrets.
    $this->actingAs($this->developer)->post(route('projects.templates.store', 'umami'), ['name' => 'Stats 2'])->assertRedirect();
    $other = Project::query()->where('name', 'Stats 2')->sole();
    $otherSecret = Variable::query()->where('project_id', $other->id)->where('key', 'APP_SECRET')->sole();
    expect($store->plaintext($other, $otherSecret))->not->toBe($value);
});

test('inputs are checked before anything is created', function (): void {
    $this->actingAs($this->developer)->post(route('projects.templates.store', 'umami'), ['name' => 'Stats', 'inputs' => ['domain' => 'not a domain']])
        ->assertSessionHasErrors('inputs');
    $this->actingAs($this->developer)->post(route('projects.templates.store', 'nope'), ['name' => 'X'])->assertNotFound();

    expect(Project::query()->count())->toBe(0);
});

test('stacks build from your repository with workers of the app', function (): void {
    $repository = GitHubFake::repository(GitHubFake::installation(GitHubFake::app($this->team)), 555, 'octo-org/shop');

    $this->actingAs($this->developer)->post(route('projects.templates.store', 'laravel-mysql-redis'), ['name' => 'Shop'])
        ->assertSessionHasErrors('repository');

    $this->actingAs($this->developer)->post(route('projects.templates.store', 'laravel-mysql-redis'), [
        'name' => 'Shop', 'repository' => $repository->public_id, 'branch' => 'main',
    ])->assertRedirect();

    $project = Project::query()->where('name', 'Shop')->sole();
    $services = Service::query()->where('project_id', $project->id)->get()->keyBy('slug');

    expect($services->map(fn (Service $s): string => $s->kind->value)->sortKeys()->all())->toBe([
        'mysql' => 'database', 'queue' => 'worker', 'redis' => 'database', 'scheduler' => 'worker', 'web' => 'app',
    ])
        ->and($services['queue']->parent_service_id)->toBe($services['web']->id)
        ->and(Variable::query()->where('service_id', $services['web']->id)->whereNotNull('connection_id')->pluck('key')->all())->toContain('DB_CONNECTION', 'REDIS_HOST');
});

test('adding from the catalog on a canvas stages the services next to the existing ones', function (): void {
    $project = Projects::create($this->team);
    Projects::postgres($project, 'postgres');
    $environment = $project->production()->sole();

    $this->actingAs($this->viewer)->getJson(route('projects.catalog', [$project, $environment]))->assertForbidden();
    $this->actingAs($this->developer)->getJson(route('projects.catalog', [$project, $environment]))->assertOk()->assertJsonCount(14, 'templates');

    $this->actingAs($this->viewer)->post(route('projects.catalog.store', [$project, $environment, 'umami']))->assertForbidden();
    $this->actingAs($this->developer)->post(route('projects.catalog.store', [$project, $environment, 'umami']))
        ->assertRedirect(route('projects.canvas', [$project, $environment]));

    $store = resolve(VariableStore::class);
    $umami = Service::query()->where('project_id', $project->id)->where('slug', 'umami')->sole();
    $url = Variable::query()->where('service_id', $umami->id)->where('key', 'DATABASE_URL')->sole();

    // The project already had a postgres: the template's database is postgres-2.
    expect(Service::query()->where('project_id', $project->id)->pluck('slug')->sort()->values()->all())->toBe(['postgres', 'postgres-2', 'umami'])
        ->and($store->plaintext($project, $url))->toBe('${{postgres-2.DATABASE_URL}}')
        ->and(Deployment::query()->count())->toBe(0);

    $other = Projects::create(Team::factory()->create(), 'Other');
    $this->actingAs($this->developer)->post(route('projects.catalog.store', [$other, $other->production()->sole(), 'umami']))->assertNotFound();
});
