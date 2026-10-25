<?php

declare(strict_types=1);

use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Servers\RecordReport;
use App\Actions\Servers\RenderNodeState;
use App\Data\Audit\AuditActor;
use App\Data\Protocol\V1\Report;
use App\Enums\DeploymentFailureClass;
use App\Enums\DeploymentStatus;
use App\Enums\HealthCheckState;
use App\Models\Deployment;
use App\Models\NodeStateVersion;
use App\Models\Server;
use App\Models\Service;
use App\Models\Team;
use App\Models\Volume;
use App\Queries\ServerHealth;
use App\Services\NodeStates\NodeStateValidator;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\Log;
use Tests\Support\Projects;

beforeEach(fn () => Projects::useTestKek());

function latestDeploymentOf(Service $service): Deployment
{
    return Deployment::query()->whereHas('serviceInstance', static fn (Builder $query) => $query->where('service_id', $service->id))->latest('id')->firstOrFail();
}

function desiredStateCheck(Server $server): array
{
    $check = collect(resolve(ServerHealth::class)->evaluate($server->refresh())->checks)->firstWhere('key', 'desired_state');

    return [$check->state, $check->value, $check->detail];
}

test('an invalid NodeState is never published: the deployment fails with config_error, other workloads keep running', function (): void {
    Log::spy();
    $team = Team::factory()->create();
    [$server] = Projects::server($team);
    $shop = Projects::create($team, 'shop');
    $web = Projects::image($shop, 'web');
    $production = $shop->production()->sole();
    resolve(DeployEnvironment::class)->handle($production, null, AuditActor::system());

    for ($i = 0; $i < 3; $i++) {
        Projects::report($server);
    }

    expect(latestDeploymentOf($web)->status)->toBe(DeploymentStatus::Active);
    $published = NodeStateVersion::query()->where('server_id', $server->id)->count();

    // A second project whose database volume got a mount target launchroomd
    // refuses (a "..": not clean). Its render would be refused whole by the
    // daemon, and with it every other workload's update on this server.
    $blog = Projects::create($team, 'blog');
    $api = Projects::postgres($blog, 'db');
    $blogProduction = $blog->production()->sole();
    Volume::query()->whereHas('serviceInstance', static fn (Builder $query) => $query->where('environment_id', $blogProduction->id))
        ->update(['mount_path' => '/var/lib/postgresql/../data']);
    resolve(DeployEnvironment::class)->handle($blogProduction, null, AuditActor::system());

    $failed = latestDeploymentOf($api);

    expect($failed->status)->toBe(DeploymentStatus::Failed)
        ->and($failed->failure_class)->toBe(DeploymentFailureClass::ConfigError)
        ->and($failed->failure_message)->toStartWith('Desired state could not be rendered: workloads[1].mounts[0].target: container path /var/lib/postgresql/../data must be clean')
        ->and(latestDeploymentOf($web)->status)->toBe(DeploymentStatus::Active)
        ->and($failed->placements()->count())->toBe(0);

    // Every version ever published is valid; the invalid one never was.
    $versions = NodeStateVersion::query()->where('server_id', $server->id)->orderBy('version')->get();
    expect($versions)->toHaveCount($published);

    foreach ($versions as $version) {
        expect(resolve(NodeStateValidator::class)->problems($version->document))->toBe([]);
    }

    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'Refused to publish an invalid NodeState') && $context['server'] === $server->public_id)->atLeast()->once();

    // Failing the deployment removed its workloads: the next render is valid again.
    expect($server->refresh()->render_error)->toBeNull();
});

test('a refused render that no deployment can fix stays visible on the server', function (): void {
    $team = Team::factory()->create();
    [$server] = Projects::server($team);
    $shop = Projects::create($team, 'shop');
    Projects::image($shop, 'web');
    $production = $shop->production()->sole();
    resolve(DeployEnvironment::class)->handle($production, null, AuditActor::system());

    for ($i = 0; $i < 3; $i++) {
        Projects::report($server);
    }

    $version = $server->refresh()->desired_version;
    // Break an active environment behind the renderer's back.
    $production->forceFill(['network_subnet' => '10.231.0.1/24'])->save();
    $render = resolve(RenderNodeState::class)->handle($server);

    expect($render->failed())->toBeTrue()
        ->and($render->version->version)->toBe($version)
        ->and($server->refresh()->desired_version)->toBe($version)
        ->and($server->render_error['message'])->toContain('networks[0].subnet: is not a canonical IPv4 prefix')
        ->and(desiredStateCheck($server)[0])->toBe(HealthCheckState::Critical)
        ->and(desiredStateCheck($server)[1])->toBe('Not rendered: the previous version stays applied');

    $production->forceFill(['network_subnet' => '10.231.0.0/24'])->save();
    resolve(RenderNodeState::class)->handle($server);

    expect($server->refresh()->render_error)->toBeNull()
        ->and(desiredStateCheck($server)[0])->toBe(HealthCheckState::Ok);
});

test('a NodeState the daemon refused is shown until it applies a newer one', function (): void {
    $team = Team::factory()->create();
    [$server] = Projects::server($team);
    resolve(RenderNodeState::class)->handle($server);
    $report = fn (array $extra): bool => resolve(RecordReport::class)->handle($server, Report::validateAndCreate([
        'apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'Report', 'serverId' => $server->public_id,
        'observedAt' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'seq' => (int) (microtime(true) * 1_000_000), 'full' => true,
        'appliedVersion' => 1, 'workloads' => [], ...$extra,
    ]));

    $report(['stateRejected' => ['version' => 2, 'error' => 'invalid document: networks.name[1]: duplicate value lr-e_x', 'rejectedAt' => '2026-10-17T12:00:04Z']]);

    [$state, $value, $detail] = desiredStateCheck($server);
    expect($server->state_rejection)->toEqual(['version' => 2, 'error' => 'invalid document: networks.name[1]: duplicate value lr-e_x', 'rejectedAt' => '2026-10-17T12:00:04Z'])
        ->and($state)->toBe(HealthCheckState::Critical)
        ->and($value)->toBe('Server refused v2 and keeps running v1')
        ->and($detail)->toContain('duplicate value lr-e_x');

    $report([]);

    expect($server->refresh()->state_rejection)->toBeNull();
});
