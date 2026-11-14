<?php

declare(strict_types=1);

use App\Data\Canvas\ServiceOriginData;
use App\Data\Services\GitSource;
use App\Enums\TeamRole;
use App\Models\Environment;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Queries\ServiceOrigin;
use Tests\Support\Projects;

/*
 * The service panel's source link (docs/08 §13): the repository at the
 * branch it deploys, the image's registry page, the engine.
 */

beforeEach(function (): void {
    Projects::useTestKek();
    $this->team = Team::factory()->create();
    $this->project = Projects::create($this->team);
    $this->production = $this->project->production()->sole();
});

function originOf(Service $service, Environment $environment): ServiceOriginData
{
    return resolve(ServiceOrigin::class)->for(ServiceInstance::query()->where('service_id', $service->id)->where('environment_id', $environment->id)->sole());
}

test('a GitHub repository links to the branch it deploys', function (): void {
    $web = Projects::image($this->project, 'web');
    $web->forceFill(['kind' => 'app', 'source' => new GitSource(555, 'acme/shop', 1, branch: 'release/v2')])->save();

    $origin = originOf($web, $this->production);

    expect($origin->kind)->toBe('repository')
        ->and($origin->label)->toBe('acme/shop')
        ->and($origin->host)->toBe('GitHub')
        ->and($origin->branch)->toBe('main')
        ->and($origin->href)->toBe('https://github.com/acme/shop/tree/main');

    // An override wins over the environment's branch.
    ServiceInstance::query()->where('service_id', $web->id)->update(['branch_override' => 'feature/login']);
    expect(originOf($web->refresh(), $this->production)->href)->toBe('https://github.com/acme/shop/tree/feature/login');
});

test('a plain git server has no web page', function (): void {
    $web = Projects::image($this->project, 'web');
    $web->forceFill(['kind' => 'app', 'source' => new GitSource(0, 'fixtures/node-hello', provider: 'git', cloneUrl: 'http://git-fixtures:8080/node-hello.git')])->save();

    $origin = originOf($web, $this->production);

    expect($origin->label)->toBe('fixtures/node-hello')
        ->and($origin->href)->toBeNull();
});

test('images link to Docker Hub, other registries stay text, databases name the engine', function (): void {
    $web = Projects::image($this->project, 'web', 'nginx:1.27');
    $api = Projects::image($this->project, 'api', 'grafana/grafana:11');
    $own = Projects::image($this->project, 'own', 'registry.example.com/acme/app:3');

    expect(originOf($web, $this->production))
        ->kind->toBe('image')
        ->label->toBe('nginx:1.27')
        ->href->toBe('https://hub.docker.com/_/nginx')
        ->and(originOf($api, $this->production)->href)->toBe('https://hub.docker.com/r/grafana/grafana')
        ->and(originOf($own, $this->production)->href)->toBeNull()
        ->and(originOf(Projects::postgres($this->project), $this->production))
        ->kind->toBe('database')
        ->label->toBe('PostgreSQL 18')
        ->href->toBeNull();
});

test('the panel carries the origin', function (): void {
    $viewer = memberOf($this->team, TeamRole::Viewer);
    $web = Projects::image($this->project, 'web', 'nginx:1.27');

    $this->actingAs($viewer)->get(route('projects.services.show', [$this->project, $this->production, $web, 'deployments']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('panel.origin.kind', 'image')
            ->where('panel.origin.href', 'https://hub.docker.com/_/nginx'));
});
