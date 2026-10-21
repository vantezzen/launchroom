<?php

declare(strict_types=1);

use App\Enums\ServerRole;
use App\Enums\TeamRole;
use App\Models\Domain;
use App\Models\ServiceInstance;
use App\Models\Team;
use Tests\Support\FakeDns;
use Tests\Support\Projects;

/*
| The Settings tab's pages (docs/08 §16): deep links to a page, the live
| scheduler preview of draft scaling, what the Scaling, Domains and Volumes
| pages show about servers, and the quiet DNS re-check.
*/

beforeEach(function (): void {
    Projects::useTestKek();
    $this->team = Team::factory()->create();
    $this->developer = memberOf($this->team, TeamRole::Developer);
    $this->viewer = memberOf($this->team, TeamRole::Viewer);
    [$this->one] = Projects::server($this->team, [ServerRole::Edge, ServerRole::Runtime], ip: '203.0.113.7');
    [$this->two] = Projects::server($this->team, [ServerRole::Runtime], ip: '203.0.113.8');
    $this->project = Projects::create($this->team);
    $this->production = $this->project->production()->sole();
    $this->web = Projects::image($this->project, 'web');
    $this->preview = fn (): string => route('projects.services.scaling-preview', [$this->project, $this->production, $this->web]);
});

test('a settings page has its own URL that opens the panel on it', function (): void {
    $this->actingAs($this->viewer)
        ->get(route('projects.services.section', [$this->project, $this->production, $this->web, 'scaling']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('panel.tab', 'settings')
            ->where('panel.section', 'scaling')
            ->has('panel.settings.placement.servers', 2));

    $this->actingAs($this->viewer)
        ->get(route('projects.services.show', [$this->project, $this->production, $this->web, 'settings']))
        ->assertInertia(fn ($page) => $page->where('panel.section', null));
});

test('unknown pages 404 and other teams cannot open a page', function (): void {
    $this->actingAs($this->viewer)
        ->get("/p/{$this->project->public_id}/{$this->production->public_id}/s/{$this->web->public_id}/settings/nope")
        ->assertNotFound();

    $stranger = memberOf(Team::factory()->create(), TeamRole::Owner);
    $this->actingAs($stranger)
        ->get(route('projects.services.section', [$this->project, $this->production, $this->web, 'domains']))
        ->assertNotFound();
});

test('Scaling shows each server with its device, capacity and the replicas the scheduler would place', function (): void {
    $instance = ServiceInstance::query()->where('service_id', $this->web->id)->sole();
    $instance->forceFill(['scaling' => [...$instance->scaling->toArray(), 'replicas' => 2]])->save();

    $this->actingAs($this->viewer)
        ->get(route('projects.services.section', [$this->project, $this->production, $this->web, 'scaling']))
        ->assertInertia(fn ($page) => $page
            ->where('panel.settings.placement.planError', null)
            ->where('panel.settings.placement.stateful', false)
            ->where('panel.settings.placement.servers.0.planned', 1)
            ->where('panel.settings.placement.servers.1.planned', 1)
            ->where('panel.settings.placement.servers.0.memoryMb', 8192)
            ->has('panel.settings.placement.servers.0.device.kind')
            ->where('panel.settings.placement.servers.0.allowProduction', true));
});

test('the scaling preview places draft settings without saving them', function (): void {
    $before = ServiceInstance::query()->where('service_id', $this->web->id)->sole()->scaling->toArray();

    $this->actingAs($this->viewer)
        ->postJson(($this->preview)(), ['scaling' => ['replicas' => 3, 'cpus' => 0.5, 'memoryMb' => 256, 'strategy' => 'pack']])
        ->assertOk()
        ->assertJsonPath('error', null)
        ->assertJsonPath('servers.0.id', $this->one->public_id)
        ->assertJsonPath('servers.0.planned', 3)
        ->assertJsonPath('servers.1.planned', 0);

    $this->actingAs($this->viewer)
        ->postJson(($this->preview)(), ['scaling' => ['replicas' => 2, 'denyServers' => [$this->one->public_id]]])
        ->assertOk()
        ->assertJsonPath('servers.0.eligible', false)
        ->assertJsonPath('servers.0.reason', 'excluded by this service')
        ->assertJsonPath('servers.1.planned', 2);

    expect(ServiceInstance::query()->where('service_id', $this->web->id)->sole()->scaling->toArray())->toBe($before);
});

test('the scaling preview says when a draft does not fit, and validates like saving', function (): void {
    $this->actingAs($this->viewer)
        ->postJson(($this->preview)(), ['scaling' => ['replicas' => 1, 'memoryMb' => 1048576]])
        ->assertOk()
        ->assertJsonPath('servers.0.planned', 0)
        ->assertJson(fn ($json) => $json->whereType('error', 'string')->etc());

    $this->actingAs($this->viewer)
        ->postJson(($this->preview)(), ['scaling' => ['replicas' => 99]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('scaling.replicas');
});

test('other teams cannot preview scaling', function (): void {
    $stranger = memberOf(Team::factory()->create(), TeamRole::Owner);

    $this->actingAs($stranger)
        ->postJson(($this->preview)(), ['scaling' => ['replicas' => 1]])
        ->assertNotFound();
});

test('Domains lists the edge servers that serve every domain', function (): void {
    $this->actingAs($this->viewer)
        ->get(route('projects.services.section', [$this->project, $this->production, $this->web, 'domains']))
        ->assertInertia(fn ($page) => $page
            ->has('panel.settings.edges', 1)
            ->where('panel.settings.edges.0.id', $this->one->public_id)
            ->has('panel.settings.edges.0.device.kind'));
});

test('a quiet DNS check updates the result without a toast', function (): void {
    FakeDns::set('shop.example.com', ['203.0.113.7']);
    $this->actingAs($this->developer)->post(route('projects.domains.store', [$this->project, $this->production, $this->web]), ['hostname' => 'shop.example.com']);
    $domain = Domain::query()->where('hostname', 'shop.example.com')->sole();
    $url = route('projects.domains.verify', [$this->project, $this->production, $this->web, $domain]);
    // The add's own toast is shown (and consumed) by the next page.
    $this->actingAs($this->developer)->get(route('projects.services.section', [$this->project, $this->production, $this->web, 'domains']))->assertOk();

    $this->actingAs($this->developer)->post($url.'?quiet=1')->assertRedirect()->assertSessionMissing('inertia.flash_data');
    expect($domain->refresh()->verification['status'])->toBe('ok');

    $this->actingAs($this->viewer)->post($url.'?quiet=1')->assertForbidden();
});
