<?php

declare(strict_types=1);

use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Services\UpdateServiceSettings;
use App\Data\Audit\AuditActor;
use App\Data\Services\ScalingSettings;
use App\Data\Services\UpdateServiceSettingsData;
use App\Enums\AuditAction;
use App\Enums\ServerRole;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\Environment;
use App\Models\Placement;
use App\Models\ServiceInstance;
use App\Models\Team;
use Illuminate\Contracts\Database\Query\Builder;
use Tests\Support\PreviewFlow;
use Tests\Support\Projects;

/*
| Server placement settings (labels, "allow previews", "allow production"),
| the placement section of the server page, Settings → Scaling, and preview
| resource caps (docs/02 §2, docs/06 §5).
*/

beforeEach(function (): void {
    Projects::useTestKek();
    $this->team = Team::factory()->create();
    $this->admin = memberOf($this->team, TeamRole::Admin);
    $this->developer = memberOf($this->team, TeamRole::Developer);
    $this->viewer = memberOf($this->team, TeamRole::Viewer);
});

test('admins edit labels and placement flags; others cannot; audited', function (): void {
    [$server] = Projects::server($this->team);
    $payload = ['labels' => ['zone' => 'hel', 'disk' => 'ssd'], 'allowPreviews' => false, 'allowProduction' => true];

    $this->actingAs($this->developer)->put(route('servers.settings.update', $server->public_id), $payload)->assertForbidden();
    $this->actingAs($this->viewer)->put(route('servers.settings.update', $server->public_id), $payload)->assertForbidden();
    $this->actingAs($this->admin)->put(route('servers.settings.update', $server->public_id), $payload)->assertRedirect();

    $server->refresh();

    expect($server->labels)->toBe(['disk' => 'ssd', 'zone' => 'hel'])
        ->and($server->allow_previews)->toBeFalse()
        ->and($server->allow_production)->toBeTrue()
        ->and(AuditEvent::query()->where('action', AuditAction::ServerSettingsUpdated->value)->sole()->meta['labels'])->toBe(['disk=ssd', 'zone=hel']);
});

test('label grammar is enforced', function (): void {
    [$server] = Projects::server($this->team);

    $this->actingAs($this->admin)->put(route('servers.settings.update', $server->public_id), ['labels' => ['Zone!' => 'x'], 'allowPreviews' => true, 'allowProduction' => true])
        ->assertSessionHasErrors('labels');
    $this->actingAs($this->admin)->put(route('servers.settings.update', $server->public_id), ['labels' => ['zone' => 'eu central'], 'allowPreviews' => true, 'allowProduction' => true])
        ->assertSessionHasErrors('labels');
});

test('servers of other teams cannot be edited', function (): void {
    [$foreign] = Projects::server(Team::factory()->create());

    $this->actingAs($this->admin)->put(route('servers.settings.update', $foreign->public_id), ['labels' => [], 'allowPreviews' => true, 'allowProduction' => true])->assertNotFound();
});

test('the server page lists placed workloads and capacity; the list counts them', function (): void {
    [$server] = Projects::server($this->team);
    $project = Projects::create($this->team, 'shop');
    $web = Projects::image($project, 'web');
    resolve(DeployEnvironment::class)->handle($project->production()->sole(), null, AuditActor::system());

    $this->actingAs($this->viewer)->get(route('servers.show', $server->public_id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('servers/show')
            ->where('placement.canUpdate', false)
            ->where('placement.allocatedMemoryMb', 512)
            // 8192 × 1.5 − 256 reserved.
            ->where('placement.schedulableMemoryMb', 12032)
            ->has('placement.workloads', 1)
            ->where('placement.workloads.0.serviceName', 'web')
            ->where('placement.workloads.0.projectName', 'shop'));

    // The latest report's memory wins over the enrollment facts.
    $server->forceFill(['host_status' => ['cpus' => 2, 'memoryMb' => 4096]])->save();

    $this->actingAs($this->admin)->get(route('servers.show', $server->public_id))
        ->assertInertia(fn ($page) => $page->where('placement.canUpdate', true)
            ->where('placement.schedulableMemoryMb', 5888)
            ->where('placement.schedulableCpus', 8));

    $this->actingAs($this->viewer)->get(route('servers.index'))
        ->assertInertia(fn ($page) => $page->where('servers.0.workloads', 1));

    expect($web->public_id)->toStartWith('svc_');
});

test('Settings → Scaling lists the runtime servers against the constraints', function (): void {
    [$one] = Projects::server($this->team);
    [$two] = Projects::server($this->team, [ServerRole::Runtime], ip: '203.0.113.8');
    $two->forceFill(['labels' => ['disk' => 'ssd']])->save();
    $project = Projects::create($this->team, 'shop');
    $web = Projects::image($project, 'web');
    $instance = ServiceInstance::query()->where('service_id', $web->id)->sole();
    resolve(UpdateServiceSettings::class)->handle($instance, UpdateServiceSettingsData::from(['scaling' => new ScalingSettings(placementLabels: ['disk' => 'ssd'])]));
    $environment = $project->production()->sole();

    $this->actingAs($this->viewer)->get(route('projects.services.show', [$project->public_id, $environment->public_id, $web->public_id, 'settings']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('panel.settings.placement.environmentKind', 'production')
            ->where('panel.settings.placement.servers.0.id', $one->public_id)
            ->where('panel.settings.placement.servers.0.eligible', false)
            ->where('panel.settings.placement.servers.0.reason', 'missing label disk=ssd')
            ->where('panel.settings.placement.servers.1.eligible', true));
});

test('previews run one replica with at most the preview CPU cap, only on servers allowing previews', function (): void {
    PreviewFlow::setUp();
    [, $server, , $project, $production, , $web] = PreviewFlow::project();
    $instance = ServiceInstance::query()->where('environment_id', $production->id)->where('service_id', $web->id)->sole();
    resolve(UpdateServiceSettings::class)->handle($instance, UpdateServiceSettingsData::from(['scaling' => new ScalingSettings(replicas: 3, cpus: 2.0, memoryMb: 1024)]));

    // The only server doesn't allow previews: preview workloads can't be placed.
    $server->forceFill(['allow_previews' => false])->save();
    PreviewFlow::event($project);
    PreviewFlow::run($server);
    $preview = Environment::query()->where('kind', 'preview')->sole();
    $copy = ServiceInstance::query()->where('environment_id', $preview->id)->where('service_id', $web->id)->sole();

    expect($copy->scaling->replicas)->toBe(1)
        ->and($copy->scaling->cpus)->toBe(0.5)
        ->and($copy->scaling->memoryMb)->toBe(1024);

    expect(Placement::query()->whereHas('deployment', fn (Builder $query) => $query->where('environment_id', $preview->id))->count())->toBe(0);
});
