<?php

declare(strict_types=1);

use App\Actions\Deployments\DeployEnvironment;
use App\Data\Audit\AuditActor;
use App\Data\Servers\HostFactsData;
use App\Enums\DeploymentStatus;
use App\Enums\ServerRole;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Team;
use App\Services\Crypto\ResponseSignature;
use App\Services\Crypto\Signer;
use Tests\Support\FakeDaemon;
use Tests\Support\Projects;
use Tests\Support\ProtocolSchemas;

beforeEach(fn () => Projects::useTestKek());

test('a daemon fetches its signed NodeState with workloads and reports them healthy over HTTP', function (): void {
    $team = Team::factory()->create();
    $seed = random_bytes(32);
    $keypair = sodium_crypto_box_keypair();
    $server = Server::factory()->withSigningSeed($seed)->online()->create([
        'team_id' => $team->id,
        'roles' => [ServerRole::Edge, ServerRole::Runtime],
        'sealing_public_key' => 'x25519:'.base64_encode(sodium_crypto_box_publickey($keypair)),
        'facts' => new HostFactsData('host', 'linux', 'arm64', 4, 8192),
        'public_ipv4' => '203.0.113.9',
    ]);
    $daemon = new FakeDaemon($seed)->as($server->public_id);

    $project = Projects::create($team);
    $postgres = Projects::postgres($project);
    resolve(DeployEnvironment::class)->handle($project->production()->sole(), null, AuditActor::system());

    $response = $daemon->send($this, 'GET', '/daemon/v1/state?have=0')->assertOk();
    $body = (string) $response->getContent();
    $signer = resolve(Signer::class);
    $state = json_decode($body, true);
    $workload = $state['workloads'][0];

    expect(ResponseSignature::verify((string) $response->headers->get('X-LR-Signature'), $body, [$signer->kid => $signer->publicKey()]))->toBeTrue()
        ->and(ProtocolSchemas::errors('node-state', $body))->toBe([])
        ->and($workload['service'])->toBe($postgres->public_id)
        ->and(Projects::openEnv($workload['env']['sealed'], $keypair))->toHaveKey('POSTGRES_PASSWORD');

    $report = [
        'apiVersion' => 'launchroom.vantezzen.io/v1',
        'kind' => 'Report',
        'serverId' => $server->public_id,
        'observedAt' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        'seq' => 1,
        'full' => true,
        'appliedVersion' => $state['version'],
        'workloads' => [[
            'id' => $workload['id'],
            'phase' => 'healthy',
            'observedAt' => now()->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'restartCount' => 0,
            'containerId' => str_repeat('d', 64),
            'health' => ['state' => 'passing', 'consecutiveFailures' => 0, 'lastCheckedAt' => now()->utc()->format('Y-m-d\TH:i:s\Z')],
        ]],
        'host' => ['cpus' => 4, 'memoryMb' => 8192, 'diskTotalMb' => 1000, 'diskFreeMb' => 500, 'daemonVersion' => '0.1.0'],
    ];

    expect(ProtocolSchemas::errors('report', (string) json_encode($report)))->toBe([]);

    $daemon->send($this, 'POST', '/daemon/v1/reports', $report)->assertNoContent();
    $deployment = Deployment::query()->sole();

    // A database has no routes, so the edge NodeState didn't change: active at once.
    expect($deployment->status)->toBe(DeploymentStatus::Active)
        ->and($deployment->placements()->sole()->health?->value)->toBe('passing')
        ->and($deployment->placements()->sole()->container_id)->toBe(str_repeat('d', 64))
        ->and($server->refresh()->observed_version)->toBe($state['version']);
});
