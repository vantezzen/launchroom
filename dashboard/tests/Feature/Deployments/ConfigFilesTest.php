<?php

declare(strict_types=1);

use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Servers\RenderNodeState;
use App\Actions\Services\UpdateServiceSettings;
use App\Data\Audit\AuditActor;
use App\Data\Services\ConfigFile;
use App\Data\Services\DeploySettings;
use App\Data\Services\UpdateServiceSettingsData;
use App\Exceptions\DomainRuleViolation;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Models\Volume;
use App\Services\NodeStates\NodeStateValidator;
use Tests\Support\Projects;

beforeEach(function (): void {
    Projects::useTestKek();
});

function withFiles(ServiceInstance $instance, array $files): ServiceInstance
{
    return resolve(UpdateServiceSettings::class)->handle($instance, UpdateServiceSettingsData::from([
        'deploy' => new DeploySettings(port: $instance->deploy->port, files: $files),
    ]));
}

test('config files reach launchroomd read-only in the NodeState, only when it announces the feature (D65)', function (): void {
    $team = Team::factory()->create();
    [$server] = Projects::server($team);
    $project = Projects::create($team);
    $web = Projects::image($project, 'web');
    $instance = ServiceInstance::query()->where('service_id', $web->id)->sole();
    withFiles($instance, [new ConfigFile('/etc/nginx/conf.d/app.conf', "server { listen 80; }\n")]);

    resolve(DeployEnvironment::class)->handle($project->production()->sole(), null, AuditActor::system());
    Projects::report($server);
    Projects::report($server);
    resolve(RenderNodeState::class)->handle($server);

    $workload = static fn (): array => collect(Projects::nodeState($server)['workloads'])->firstWhere('service', $web->public_id) ?? [];

    // An older launchroomd would refuse the unknown field.
    expect($workload())->not->toBeEmpty()
        ->and($workload())->not->toHaveKey('files');

    $server->forceFill(['daemon_features' => ['configFiles']])->save();
    resolve(RenderNodeState::class)->handle($server);

    expect($workload()['files'] ?? null)->toBe([['path' => '/etc/nginx/conf.d/app.conf', 'content' => "server { listen 80; }\n"]])
        ->and(resolve(NodeStateValidator::class)->problems(Projects::latestDocument($server)))->toBe([]);
});

test('config files follow launchroomd’s rules on save: never into a volume, never on managed databases', function (): void {
    $project = Projects::create(Team::factory()->create());
    $instance = ServiceInstance::query()->where('service_id', Projects::image($project, 'web')->id)->sole();
    Volume::query()->create(['team_id' => $instance->team_id, 'service_instance_id' => $instance->id, 'mount_path' => '/data']);
    $database = ServiceInstance::query()->where('service_id', Projects::postgres($project)->id)->sole();

    expect(fn (): ServiceInstance => withFiles($instance, [new ConfigFile('/data/app.conf', 'x')]))->toThrow(DomainRuleViolation::class, 'inside the volume')
        ->and(fn (): ServiceInstance => withFiles($instance, [new ConfigFile('/proc/x', 'x')]))->toThrow(DomainRuleViolation::class)
        ->and(fn (): ServiceInstance => withFiles($database, [new ConfigFile('/etc/x.conf', 'x')]))->toThrow(DomainRuleViolation::class, 'Managed databases')
        ->and(withFiles($instance, [new ConfigFile('/etc/app.conf', 'x')])->deploy->files[0]->path)->toBe('/etc/app.conf');
});
