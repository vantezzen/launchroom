<?php

declare(strict_types=1);

use App\Actions\Connections\CreateConnection;
use App\Actions\Deployments\DeployEnvironment;
use App\Data\Audit\AuditActor;
use App\Enums\ConnectionMode;
use App\Enums\ConnectionStyle;
use App\Enums\TeamRole;
use App\Models\CanvasLayout;
use App\Models\Server;
use App\Models\Team;
use Tests\Support\Projects;

beforeEach(function (): void {
    Projects::useTestKek();
    $this->team = Team::factory()->create();
    $this->developer = memberOf($this->team, TeamRole::Developer);
    $this->viewer = memberOf($this->team, TeamRole::Viewer);
    Projects::server($this->team);
    $this->project = Projects::create($this->team);
    $this->production = $this->project->production()->sole();
    $this->web = Projects::image($this->project, 'web');
    $this->postgres = Projects::postgres($this->project);
    resolve(CreateConnection::class)->handle($this->web, $this->postgres, ConnectionMode::Private, ConnectionStyle::Url, null, AuditActor::system());
});

test('the canvas has a node per service, connection edges and the viewer’s abilities', function (): void {
    $this->actingAs($this->viewer)->get(route('projects.canvas', [$this->project, $this->production]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('projects/canvas')
            ->where('project.id', $this->project->public_id)
            ->where('environment.id', $this->production->public_id)
            ->where('environment.branch', 'main')
            ->where('abilities', ['manage' => false, 'connect' => false, 'deploy' => false])
            ->has('nodes', 2)
            ->where('nodes.0.id', $this->web->public_id)
            ->where('nodes.0.kind', 'image')
            ->where('nodes.0.logo', 'nginx')
            ->where('nodes.0.status.state', 'idle')
            ->where('nodes.0.staged', 'new')
            ->where('nodes.0.privateAddress', 'http://web:80')
            ->where('nodes.0.position', null)
            ->where('nodes.1.logo', 'postgres')
            ->where('nodes.1.subtitle', 'PostgreSQL 18')
            ->where('nodes.1.volume', '/var/lib/postgresql')
            ->has('edges', 1)
            ->where('edges.0.type', 'connection')
            ->where('edges.0.source', $this->web->public_id)
            ->where('edges.0.target', $this->postgres->public_id)
            ->where('edges.0.variables', ['DATABASE_URL'])
            ->where('panel', null)
            ->has('engines', 6));

    $this->actingAs($this->developer)->get(route('projects.canvas', [$this->project, $this->production]))
        ->assertInertia(fn ($page) => $page->where('abilities', ['manage' => true, 'connect' => true, 'deploy' => true]));
});

test('deployed services show their status and are no longer staged', function (): void {
    resolve(DeployEnvironment::class)->handle($this->production, null, AuditActor::system());
    Projects::report(Server::query()->where('team_id', $this->team->id)->sole());
    Projects::report(Server::query()->where('team_id', $this->team->id)->sole());

    $this->actingAs($this->viewer)->get(route('projects.canvas', [$this->project, $this->production]))
        ->assertInertia(fn ($page) => $page
            ->where('nodes.0.staged', null)
            ->where('nodes.0.status.state', 'active')
            ->where('nodes.0.status.label', fn (string $label): bool => in_array($label, ['Live', 'Running'], true))
            ->where('nodes.0.server', fn (string $server): bool => $server !== '')
            ->where('edges.0.staged', false));

    $this->actingAs($this->viewer)->get(route('projects.index'))
        ->assertInertia(fn ($page) => $page->where('projects.0.environments.0.status.label', 'Live'));
});

test('an environment of another project is not found under this project', function (): void {
    $other = Projects::create($this->team, 'Other')->production()->sole();

    $this->actingAs($this->viewer)->get(route('projects.canvas', [$this->project, $other]))->assertNotFound();
    $this->actingAs($this->viewer)->get("/p/{$this->project->public_id}/env_not-an-id")->assertNotFound();
});

test('the service panel is route-backed; Settings carries the settings data', function (): void {
    $url = fn (string $tab): string => route('projects.services.show', [$this->project, $this->production, $this->web, $tab]);

    $this->actingAs($this->viewer)->get($url('deployments'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('projects/canvas')
            ->where('panel.serviceId', $this->web->public_id)
            ->where('panel.tab', 'deployments')
            ->where('panel.settings', null)
            ->where('panel.canUpdate', false)
            ->where('panel.canDelete', false));

    $this->actingAs($this->developer)->get($url('settings'))
        ->assertInertia(fn ($page) => $page
            ->where('panel.tab', 'settings')
            ->where('panel.canUpdate', true)
            ->where('panel.settings.source.type', 'image')
            ->where('panel.settings.source.image', 'nginx@'.Projects::DIGEST)
            ->where('panel.settings.deploy.port', 80)
            ->where('panel.settings.scaling.replicas', 1)
            ->where('panel.settings.privateHost', 'web')
            ->where('panel.settings.detected', null)
            ->has('panel.settings.domains', 1)
            ->where('panel.settings.domains.0.kind', 'generated')
            ->where('panel.settings.domains.0.staged', true)
            ->where('panel.settings.advanced.serviceId', $this->web->public_id)
            ->where('panel.settings.advanced.networkName', $this->production->networkName()));

    $this->actingAs($this->viewer)->get($url('nope'))->assertNotFound();

    // A service of another project is not found under this project.
    $foreign = Projects::image(Projects::create($this->team, 'Other'), 'api');
    $this->actingAs($this->viewer)->get(route('projects.services.show', [$this->project, $this->production, $foreign, 'settings']))->assertNotFound();
});

test('the former Variables tab redirects to its settings page, keeping the add link', function (): void {
    $base = "/p/{$this->project->public_id}/{$this->production->public_id}/s/{$this->web->public_id}";
    $page = route('projects.services.section', [$this->project, $this->production, $this->web, 'variables']);

    $this->actingAs($this->viewer)->get("{$base}/variables?add=API_KEY&value=x")
        ->assertStatus(301)
        ->assertRedirect("{$page}?add=API_KEY&value=x");
    $this->actingAs($this->viewer)->get("{$base}?tab=variables")->assertRedirect($page);
    $this->actingAs($this->viewer)->get("{$base}/deployments?tab=variables&add=X")->assertRedirect("{$page}?add=X");

    $this->actingAs($this->viewer)->get($page)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('panel.tab', 'settings')->where('panel.section', 'variables'));

    // Not a team member: no redirect that would confirm the service exists.
    $outsider = memberOf(Team::factory()->create(), TeamRole::Owner);
    $this->actingAs($outsider)->get("{$base}/variables")->assertNotFound();
});

test('node positions are saved instantly by members who manage the project', function (): void {
    $url = route('projects.layout', [$this->project, $this->production]);
    $positions = ['positions' => [
        $this->web->public_id => ['x' => 10.04, 'y' => -20],
        'svc_01M576RC5J9HKNGDM3FBBPCT6Q' => ['x' => 1, 'y' => 1],
    ]];

    $this->actingAs($this->viewer)->putJson($url, $positions)->assertForbidden();
    $this->actingAs($this->developer)->putJson($url, ['positions' => [$this->web->public_id => ['x' => 'left']]])->assertUnprocessable();
    $this->actingAs($this->developer)->putJson($url, $positions)->assertNoContent();

    expect(CanvasLayout::query()->where('environment_id', $this->production->id)->sole()->positions)
        ->toEqual([$this->web->public_id => ['x' => 10, 'y' => -20]]);

    $this->actingAs($this->viewer)->get(route('projects.canvas', [$this->project, $this->production]))
        ->assertInertia(fn ($page) => $page->where('nodes.0.position', ['x' => 10, 'y' => -20]));
});
