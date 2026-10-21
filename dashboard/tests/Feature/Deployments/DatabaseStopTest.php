<?php

declare(strict_types=1);

use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Servers\RenderNodeState;
use App\Actions\Services\CreateService;
use App\Data\Audit\AuditActor;
use App\Data\Services\CreateServiceData;
use App\Enums\ServiceKind;
use App\Models\Team;
use App\Services\NodeStates\NodeStateValidator;
use Tests\Support\Backups;
use Tests\Support\Projects;

beforeEach(function (): void {
    Projects::useTestKek();
});

test('managed databases stop with their engine signal, a 60 s timeout and no init on daemons that announce stopSpec', function (): void {
    [, $server, , $instance] = Backups::runningPostgres();
    $service = $instance->service->public_id;

    // Older daemons don't know the field.
    expect(collect(Projects::nodeState($server)['workloads'])->firstWhere('service', $service))->not->toHaveKey('stop');

    $server->forceFill(['daemon_features' => ['stopSpec']])->save();
    resolve(RenderNodeState::class)->handle($server);

    $workload = collect(Projects::nodeState($server)['workloads'])->firstWhere('service', $service);
    expect($workload['stop'] ?? null)->toBe(['signal' => 'SIGINT', 'timeout' => '60s', 'noInit' => true])
        ->and($workload)->not->toHaveKey('command')
        ->and(resolve(NodeStateValidator::class)->problems(Projects::latestDocument($server)))->toBe([]);

    // Stable: a re-render needs no new version.
    $version = $server->refresh()->desired_version;
    resolve(RenderNodeState::class)->handle($server);
    expect($server->refresh()->desired_version)->toBe($version);
});

test('valkey skips the init its image starts, mysql and redis keep their entrypoint', function (): void {
    $team = Team::factory()->create();
    [$server] = Projects::server($team);
    $server->forceFill(['daemon_features' => ['stopSpec']])->save();
    $project = Projects::create($team, 'cache-'.bin2hex(random_bytes(3)));

    foreach (['valkey' => '8', 'redis' => '7', 'mysql' => '8.4'] as $engine => $version) {
        resolve(CreateService::class)->handle($project, CreateServiceData::from([
            'name' => $engine, 'kind' => ServiceKind::Database,
            'source' => ['type' => 'database', 'engine' => $engine, 'version' => $version],
        ]), null, AuditActor::system());
    }

    resolve(DeployEnvironment::class)->handle($project->production()->sole(), null, AuditActor::system());
    Projects::report($server);
    Projects::report($server);
    resolve(RenderNodeState::class)->handle($server);

    $workloads = collect(Projects::nodeState($server)['workloads'])->keyBy(fn (array $w): string => (string) str($w['image'])->after('docker.io/')->before('@'));
    expect($workloads->keys()->sort()->values()->all())->toBe(['library/mysql', 'library/redis', 'valkey/valkey'])
        ->and($workloads['valkey/valkey']['command'] ?? null)->toBe(['docker-entrypoint.sh'])
        ->and($workloads['valkey/valkey']['stop'])->toBe(['signal' => 'SIGTERM', 'timeout' => '60s', 'noInit' => true])
        ->and($workloads['library/redis'])->not->toHaveKey('command')
        ->and($workloads['library/redis']['stop']['signal'])->toBe('SIGTERM')
        ->and($workloads['library/mysql'])->not->toHaveKey('command')
        ->and($workloads['library/mysql']['stop']['signal'])->toBe('SIGTERM')
        ->and(resolve(NodeStateValidator::class)->problems(Projects::latestDocument($server)))->toBe([]);
});
