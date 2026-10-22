<?php

declare(strict_types=1);

use App\Actions\Servers\CreateEnrollment;
use App\Data\Audit\AuditActor;
use App\Data\Servers\CreateEnrollmentData;
use App\Enums\ServerRole;
use App\Models\Server;
use App\Models\Team;
use App\Services\Dns\EdgeAddresses;
use Tests\Support\FakeDaemon;
use Tests\Support\ProtocolSchemas;

function addressReport(Server $server, ?array $publicAddresses, int $seq = 1): array
{
    return [
        'apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'Report', 'serverId' => $server->public_id,
        'observedAt' => now()->toIso8601ZuluString(), 'seq' => $seq, 'full' => true, 'appliedVersion' => 0, 'workloads' => [],
        'host' => array_filter([
            'cpus' => 1, 'memoryMb' => 1987, 'diskTotalMb' => 50_000, 'diskFreeMb' => 40_000, 'daemonVersion' => '0.5.0',
            'publicAddresses' => $publicAddresses,
        ], static fn (mixed $value): bool => $value !== null),
    ];
}

beforeEach(function (): void {
    config(['launchroom.server_health.private_addresses' => false]);
    $seed = random_bytes(32);
    // Enrolled before the fix: the control-plane daemon's source address was Docker's bridge gateway.
    $this->server = Server::factory()->withSigningSeed($seed)->online()->create([
        'roles' => [ServerRole::Edge, ServerRole::Runtime, ServerRole::Build],
        'is_control_plane' => true,
        'public_ipv4' => '172.18.0.1',
    ]);
    $this->daemon = new FakeDaemon($seed)->as($this->server->public_id);
});

test('the Go fixture with public addresses is accepted and stored', function (): void {
    $fixture = json_decode((string) file_get_contents(ProtocolSchemas::root().'/fixtures/v1/report.public-addresses.json'), true);
    $fixture['serverId'] = $this->server->public_id;

    $this->daemon->send($this, 'POST', '/daemon/v1/reports', $fixture)->assertNoContent();

    expect($this->server->refresh()->public_ipv4)->toBe('93.184.216.34')
        ->and($this->server->public_ipv6)->toBe('2606:2800:220:1:248:1893:25c8:1946');
});

test('reported public addresses replace the Docker gateway and are where DNS points', function (): void {
    $this->daemon->send($this, 'POST', '/daemon/v1/reports', addressReport($this->server, ['ipv4' => '93.184.216.34', 'detectedAt' => now()->toIso8601ZuluString()]))->assertNoContent();

    expect($this->server->refresh()->public_ipv4)->toBe('93.184.216.34')
        ->and($this->server->public_ipv6)->toBeNull()
        ->and(resolve(EdgeAddresses::class)->addresses(null))->toBe(['93.184.216.34']);
});

test('a reported private address is never stored, and a stored one is dropped', function (): void {
    $this->daemon->send($this, 'POST', '/daemon/v1/reports', addressReport($this->server, ['ipv4' => '10.0.0.5', 'detectedAt' => now()->toIso8601ZuluString()]))->assertNoContent();

    expect($this->server->refresh()->public_ipv4)->toBeNull();
});

test('without public addresses in the report, a stored public one stays and a Docker one goes', function (): void {
    $this->daemon->send($this, 'POST', '/daemon/v1/reports', addressReport($this->server, null))->assertNoContent();
    expect($this->server->refresh()->public_ipv4)->toBeNull();

    $this->server->forceFill(['public_ipv4' => '93.184.216.34'])->save();
    $this->daemon->send($this, 'POST', '/daemon/v1/reports', addressReport($this->server, null, 2))->assertNoContent();
    expect($this->server->refresh()->public_ipv4)->toBe('93.184.216.34');
});

test('DNS instructions never show a private address, even before the next report', function (): void {
    expect(resolve(EdgeAddresses::class)->addresses(null))->toBe([]);
});

test('enrollment keeps only a public source address', function (string $source, ?string $stored): void {
    $team = Team::factory()->create();
    $token = resolve(CreateEnrollment::class)->handle($team, new CreateEnrollmentData(['edge', 'runtime', 'build']), null, AuditActor::system(), true)->token;
    $daemon = new FakeDaemon;

    $this->withServerVariables(['REMOTE_ADDR' => $source]);
    $id = $daemon->send($this, 'POST', '/daemon/v1/enroll', $daemon->enrollBody($token))->assertCreated()->json('serverId');

    expect(Server::query()->where('public_id', $id)->sole()->public_ipv4)->toBe($stored);
})->with([
    'Docker bridge gateway (control plane)' => ['172.18.0.1', null],
    'a NAT router' => ['192.168.1.1', null],
    'a public address' => ['93.184.216.34', '93.184.216.34'],
]);
