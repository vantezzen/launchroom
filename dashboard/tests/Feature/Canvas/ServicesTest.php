<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\CanvasLayout;
use App\Models\Connection;
use App\Models\Domain;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\Team;
use Tests\Support\GitHubFake;
use Tests\Support\Projects;

beforeEach(function (): void {
    Projects::useTestKek();
    $this->team = Team::factory()->create();
    $this->developer = memberOf($this->team, TeamRole::Developer);
    $this->viewer = memberOf($this->team, TeamRole::Viewer);
    $this->project = Projects::create($this->team);
    $this->production = $this->project->production()->sole();
    $this->store = route('projects.services.store', [$this->project, $this->production]);
});

test('adding an image service stages it and places it where it was dropped', function (): void {
    $this->actingAs($this->viewer)->post($this->store, ['name' => 'web', 'kind' => 'image', 'image' => 'nginx:1.27', 'port' => 80])->assertForbidden();
    $this->actingAs($this->developer)->post($this->store, ['name' => 'web', 'kind' => 'image'])->assertSessionHasErrors('image');
    $this->actingAs($this->developer)->post($this->store, ['name' => 'web', 'kind' => 'image', 'image' => 'not an image!'])->assertSessionHasErrors('reference');

    $response = $this->actingAs($this->developer)->post($this->store, [
        'name' => 'web', 'kind' => 'image', 'image' => 'nginx:1.27', 'port' => 80, 'position' => ['x' => 120, 'y' => 40],
    ]);

    $service = Service::query()->sole();
    $response->assertRedirect(route('projects.services.show', [$this->project, $this->production, $service, 'settings']));
    expect($service->kind->value)->toBe('image')
        ->and(CanvasLayout::query()->where('environment_id', $this->production->id)->sole()->positions)
        ->toEqual([$service->public_id => ['x' => 120, 'y' => 40]]);
});

test('adding a database and a worker of an app', function (): void {
    $this->actingAs($this->developer)->post($this->store, ['name' => 'postgres', 'kind' => 'database', 'engine' => 'postgres', 'version' => '18'])->assertRedirect();
    $this->actingAs($this->developer)->post($this->store, ['name' => 'db2', 'kind' => 'database', 'engine' => 'postgres', 'version' => '9'])->assertSessionHasErrors('source');

    $api = Projects::image($this->project, 'api');
    // Workers need an app parent (built from a repository), not an image.
    $this->actingAs($this->developer)->post($this->store, ['name' => 'queue', 'kind' => 'worker', 'parentServiceId' => $api->public_id, 'startCommand' => ['php', 'artisan', 'queue:work']])
        ->assertSessionHasErrors('parentServiceId');
    $this->actingAs($this->developer)->post($this->store, ['name' => 'queue', 'kind' => 'worker', 'parentServiceId' => $api->public_id])
        ->assertSessionHasErrors('startCommand');
});

test('adding a GitHub app resolves the repository within the team and pins its branch', function (): void {
    GitHubFake::setUp();
    $repository = GitHubFake::repository(GitHubFake::installation(GitHubFake::app($this->team)), 555, 'octo-org/shop');
    $foreign = GitHubFake::repository(GitHubFake::installation(GitHubFake::app(Team::factory()->create(), 4343), 101), 777, 'evil/repo');

    $this->actingAs($this->developer)->post($this->store, ['name' => 'web', 'kind' => 'app', 'repository' => $foreign->public_id, 'branch' => 'main'])
        ->assertSessionHasErrors('repository');
    $this->actingAs($this->developer)->post($this->store, ['name' => 'web', 'kind' => 'app', 'repository' => $repository->public_id, 'branch' => 'bad..branch'])
        ->assertSessionHasErrors('branch');

    $this->actingAs($this->developer)->post($this->store, [
        'name' => 'web', 'kind' => 'app', 'repository' => $repository->public_id, 'branch' => 'develop', 'rootDirectory' => 'apps/web',
    ])->assertRedirect();

    $service = Service::query()->sole();
    expect($service->source->toArray())->toMatchArray(['repositoryId' => 555, 'fullName' => 'octo-org/shop', 'rootDirectory' => 'apps/web'])
        ->and(ServiceInstance::query()->sole()->branch_override)->toBe('develop');

    // The worker form can now use it as parent.
    $this->actingAs($this->developer)->post($this->store, ['name' => 'queue', 'kind' => 'worker', 'parentServiceId' => $service->public_id, 'startCommand' => ['php', 'artisan', 'queue:work']])
        ->assertRedirect();
    expect(Service::query()->where('slug', 'queue')->sole()->parent_service_id)->toBe($service->id);
});

test('the plan preview answers for team repositories only', function (): void {
    GitHubFake::setUp();
    $repository = GitHubFake::repository(GitHubFake::installation(GitHubFake::app($this->team)));
    $foreign = GitHubFake::repository(GitHubFake::installation(GitHubFake::app(Team::factory()->create(), 4343), 101), 777, 'evil/repo');
    $url = fn (string $repo, string $branch = 'main'): string => route('projects.plan-preview', ['project' => $this->project, 'repository' => $repo, 'branch' => $branch]);

    $this->actingAs($this->viewer)->getJson($url($repository->public_id))->assertForbidden();
    $this->actingAs($this->developer)->getJson($url($foreign->public_id))->assertNotFound();
    $this->actingAs($this->developer)->getJson($url($repository->public_id, '-x..y'))->assertUnprocessable();
    $this->actingAs($this->developer)->getJson($url($repository->public_id))
        ->assertOk()
        ->assertJson(['status' => 'unavailable', 'plan' => null]);
});

test('settings saves are staged and validated', function (): void {
    $web = Projects::image($this->project, 'web');
    $postgres = Projects::postgres($this->project);
    $url = fn (Service $service): string => route('projects.services.settings', [$this->project, $this->production, $service]);

    $this->actingAs($this->viewer)->patch($url($web), ['scaling' => ['replicas' => 2]])->assertForbidden();
    $this->actingAs($this->developer)->patch($url($web), ['scaling' => ['replicas' => 99]])->assertSessionHasErrors('scaling.replicas');
    $this->actingAs($this->developer)->patch($url($postgres), ['scaling' => ['replicas' => 2]])->assertSessionHasErrors('scaling.replicas');

    $this->actingAs($this->developer)->patch($url($web), [
        'scaling' => ['replicas' => 3, 'cpus' => 0.5, 'memoryMb' => 256],
        'deploy' => ['port' => 8080, 'startCommand' => ['nginx', '-g', 'daemon off;'], 'healthcheck' => ['type' => 'tcp']],
    ])->assertRedirect();

    $instance = ServiceInstance::query()->where('service_id', $web->id)->sole();
    expect($instance->scaling->replicas)->toBe(3)
        ->and($instance->deploy->port)->toBe(8080)
        ->and($instance->deploy->startCommand)->toBe(['nginx', '-g', 'daemon off;'])
        ->and($instance->deploy->healthcheck->type->value)->toBe('tcp');
});

test('Advanced edits the entrypoint and capabilities from launchroomd\'s allow-list only', function (): void {
    $web = Projects::image($this->project, 'web');
    $url = route('projects.services.settings', [$this->project, $this->production, $web]);
    $deploy = fn (array $changes): array => ['deploy' => [...ServiceInstance::query()->where('service_id', $web->id)->sole()->deploy->toArray(), ...$changes]];

    $this->actingAs($this->developer)->get(route('projects.services.show', [$this->project, $this->production, $web, 'settings']))
        ->assertInertia(fn ($page) => $page
            ->where('panel.settings.advanced.capabilityOptions.3.name', 'NET_BIND_SERVICE')
            ->where('panel.settings.advanced.capabilityOptions.3.label', 'Listen on ports below 1024')
            ->count('panel.settings.advanced.capabilityOptions', 6));

    $this->actingAs($this->developer)->patch($url, $deploy(['capabilities' => ['SYS_ADMIN']]))->assertSessionHasErrors('deploy.capabilities.0');
    $this->actingAs($this->developer)->patch($url, $deploy(['entrypoint' => []]))->assertSessionHasErrors('deploy.entrypoint');
    $this->actingAs($this->developer)->patch($url, $deploy(['entrypoint' => ['/docker-entrypoint.sh', '--verbose'], 'capabilities' => ['NET_BIND_SERVICE', 'CHOWN']]))->assertRedirect();

    $instance = ServiceInstance::query()->where('service_id', $web->id)->sole();
    expect($instance->deploy->entrypoint)->toBe(['/docker-entrypoint.sh', '--verbose'])
        ->and($instance->deploy->capabilities)->toBe(['NET_BIND_SERVICE', 'CHOWN']);
});

test('removing a service is staged and shows on the canvas', function (): void {
    $web = Projects::image($this->project, 'web');
    $url = route('projects.services.destroy', [$this->project, $this->production, $web]);

    $this->actingAs($this->viewer)->delete($url)->assertForbidden();
    $this->actingAs($this->developer)->delete($url)->assertRedirect(route('projects.canvas', [$this->project, $this->production]));

    expect(ServiceInstance::query()->sole()->removed_at)->not->toBeNull();
    $this->actingAs($this->viewer)->get(route('projects.canvas', [$this->project, $this->production]))
        ->assertInertia(fn ($page) => $page->where('nodes.0.removed', true)->where('nodes.0.status.label', 'Removal staged'));

    // Staged removals can't be edited.
    $this->actingAs($this->developer)->patch(route('projects.services.settings', [$this->project, $this->production, $web]), ['enabled' => false])
        ->assertStatus(409);
});

test('custom domains: add with DNS instructions, remove; only within the service', function (): void {
    [$server] = Projects::server($this->team);
    $web = Projects::image($this->project, 'web');
    $other = Projects::image($this->project, 'docs');
    $store = route('projects.domains.store', [$this->project, $this->production, $web]);

    $this->actingAs($this->viewer)->post($store, ['hostname' => 'shop.example.com'])->assertForbidden();
    $this->actingAs($this->developer)->post($store, ['hostname' => 'not a domain'])->assertSessionHasErrors('hostname');
    $this->actingAs($this->developer)->post($store, ['hostname' => 'Shop.Example.com'])->assertRedirect();

    $domain = Domain::query()->where('hostname', 'shop.example.com')->sole();

    $this->actingAs($this->developer)->get(route('projects.services.show', [$this->project, $this->production, $web, 'settings']))
        ->assertInertia(fn ($page) => $page
            ->where('panel.settings.domains.0.hostname', 'shop.example.com')
            ->where('panel.settings.domains.0.kind', 'custom')
            ->where('panel.settings.domains.0.canDelete', true)
            ->where('panel.settings.domains.0.dnsRecords', [['type' => 'A', 'name' => 'shop.example.com', 'value' => $server->public_ipv4]]));

    // The domain is not reachable through another service's URL.
    $this->actingAs($this->developer)->delete(route('projects.domains.destroy', [$this->project, $this->production, $other, $domain]))->assertNotFound();
    $this->actingAs($this->viewer)->delete(route('projects.domains.destroy', [$this->project, $this->production, $web, $domain]))->assertForbidden();
    $this->actingAs($this->developer)->delete(route('projects.domains.destroy', [$this->project, $this->production, $web, $domain]))->assertRedirect();

    expect(Domain::query()->where('hostname', 'shop.example.com')->exists())->toBeFalse();
});

test('a new database connects to the picked services right away ("Connect it to…")', function (): void {
    $web = Projects::image($this->project, 'web');
    $api = Projects::image($this->project, 'api');
    $foreign = Projects::image(Projects::create(Team::factory()->create(), 'Theirs'), 'evil');

    $this->actingAs($this->developer)->post($this->store, ['name' => 'web2', 'kind' => 'image', 'image' => 'nginx:1.27', 'connectTo' => [$web->public_id]])
        ->assertSessionHasErrors('connectTo');
    $this->actingAs($this->developer)->post($this->store, ['name' => 'pg', 'kind' => 'database', 'engine' => 'postgres', 'version' => '18', 'connectTo' => [$foreign->public_id]])
        ->assertSessionHasErrors('connectTo');
    expect(Service::query()->where('slug', 'pg')->exists())->toBeFalse();

    $this->actingAs($this->developer)->post($this->store, ['name' => 'postgres', 'kind' => 'database', 'engine' => 'postgres', 'version' => '18', 'connectTo' => [$web->public_id, $api->public_id]])
        ->assertRedirect();

    $postgres = Service::query()->where('slug', 'postgres')->sole();
    expect(Connection::query()->where('to_service_id', $postgres->id)->pluck('from_service_id')->sort()->values()->all())->toBe([$web->id, $api->id]);
});
